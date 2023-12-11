<?php

namespace mb\wechat\platform;

use mb\helper\Net;
use mb\wechat\Helper;
use mb\wechat\Platform;
use mb\wechat\platform\traits\Integration;

/**
 * 公众平台封装对象（用于服务号，订阅号）
 * @package mb\wechat\platform
 */
class WeXin implements Platform
{
    protected static function getAccessToken($appid, $secret)
    {
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$secret}";
        $content = Net::httpGet($url);
        if (is_error($content)) {
            return error(-1, '获取微信公众号授权失败, 请稍后重试！错误详情: ' . $content['message']);
        }
        $token = @json_decode($content, true);
        if (empty($token) || !is_array($token) || empty($token['access_token']) || empty($token['expires_in'])) {
            return error($token['errcode'], $token['errmsg']);
        }
        $record = array();
        $record['token'] = $token['access_token'];
        $record['expire'] = time() + $token['expires_in'];

        return $record;
    }

    protected $account;
    protected $saver;

    /**
     * 特定公众号平台的操作对象构造方法
     *
     * @param array $account 公号平台数据
     * @param callable|null $saver 持久化操作
     */
    public function __construct(array $account, callable $saver = null)
    {
        $this->account = $account;
        if (empty($this->account['js_ticket'])) {
            $this->account['js_ticket'] = '';
        }
        $this->saver = $saver;
    }

    /**
     * 获取当前平台数据
     *
     * @return array
     */
    public function getAccount()
    {
        return $this->account;
    }

    use Integration;

    public function push($openid, array $packet)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $pars = [];
        $pars['touser'] = $openid;
        if (!empty($packet['kf'])) {
            $pars['customservice']['kf_account'] = $packet['kf']['account'];
        }
        if ($packet['type'] == Platform::PACKET_TEXT) {
            $pars['msgtype'] = 'text';
            $pars['text']['content'] = urlencode($packet['content']);
        }
        if ($packet['type'] == Platform::PACKET_IMAGE) {
            $pars['msgtype'] = 'image';
            $media = $packet['image'];
            if (is_file($packet['image'])) {
                $ret = $this->uploadMedia('image', $packet['image']);
                if (is_error($ret)) {
                    return $ret;
                }
                $media = $ret['media'];
            }
            $pars['image']['media_id'] = $media;
        }
        if ($packet['type'] == Platform::PACKET_NEWS) {
            $pars['msgtype'] = 'news';
            if (count($packet['news']) > 10) {
                $packet['news'] = array_rand($packet['news'], 10);
            }
            foreach ($packet['news'] as $article) {
                $pars['news']['articles'][] = array(
                    'title' => urlencode($article['title']),
                    'description' => urlencode($article['description']),
                    'url' => $article['url'],
                    'picurl' => $article['image']
                );
            }
        }
        if ($packet['type'] == Platform::PACKET_CARD) {
            $pars['msgtype'] = 'wxcard';
            $filter = [];
            $filter['card'] = $packet['card'];
            $card = $this->cardDataCreate($filter, 'ext');
            $pars['wxcard']['card_id'] = $card['card'];
            $ext = [];
            $ext['code'] = '';
            $ext['openid'] = '';
            $ext['timestamp'] = $card['timestamp'];
            $ext['signature'] = $card['signature'];
            $pars['wxcard']['card_ext'] = json_encode($ext);
        }
        if ($packet['type'] == Platform::PACKET_MINIPROGRAMPAGE) {
            $pars['msgtype'] = 'miniprogrampage';
            $pars['miniprogrampage']['title'] = urlencode($packet['title']);
            $pars['miniprogrampage']['appid'] = $packet['appid'];
            $pars['miniprogrampage']['pagepath'] = $packet['page'];
            $pars['miniprogrampage']['thumb_media_id'] = $packet['thumb'];
        }
        $url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$token}";
        $dat = json_encode($pars);
        $resp = Net::httpPost($url, urldecode($dat));
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            if ($result['errcode'] == '40001') {
                $this->fetchToken(true);
            }

            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }

        return true;
    }

    /**
     * 向一组用户发送群发消息, 可选的可以指定是否要指定特定组
     *
     * @param array $packet 统一消息结构
     * @param array|null $targets 单独向一组用户群发, 或指定fans列表发送
     */
    public function broadcast(array $packet, array $targets = null)
    {
        // TODO: Implement broadcast() method.
    }

    /**
     * 向某个用户发送通知消息
     *
     * @param string $openid 用户编号
     * @param array $notification 通知消息结构
     */
    public function notify(string $openid, array $notification)
    {
        // TODO: Implement notify() method.
    }

    /**
     * 为当前公众号创建菜单
     *
     * @param array $menu 统一菜单结构
     * @param array $conditional 菜单生效条件
     * @return bool 是否创建成功
     */
    public function menuCreate(array $menu, array $conditional = null)
    {
        $set = [];
        $set['button'] = [];
        foreach ($menu as $m) {
            $entry = [];
            $entry['name'] = $m['title'];
            if (!empty($m['subMenus'])) {
                $entry['sub_button'] = [];
                foreach ($m['subMenus'] as $s) {
                    $e = [];
                    $e['type'] = $s['type'];
                    $e['name'] = $s['title'];
                    if ($e['type'] == 'view') {
                        $e['url'] = $s['url'];
                    } elseif (in_array($e['type'], array('media_id', 'view_limited'))) {
                        $e['media_id'] = $s['media_id'];
                    } elseif ($e['type'] == 'miniprogram') {
                        $e['url'] = $s['url'];
                        $e['appid'] = $s['appid'];
                        $e['pagepath'] = $s['pagepath'];
                    } else {
                        $e['key'] = $s['key'];
                    }
                    $entry['sub_button'][] = $e;
                }
            } else {
                $entry['type'] = $m['type'];
                if ($entry['type'] == 'view') {
                    $entry['url'] = $m['url'];
                } elseif (in_array($entry['type'], array('media_id', 'view_limited'))) {
                    $entry['media_id'] = $m['media_id'];
                } elseif ($entry['type'] == 'miniprogram') {
                    $entry['url'] = $m['url'];
                    $entry['appid'] = $m['appid'];
                    $entry['pagepath'] = $m['pagepath'];
                } else {
                    $entry['key'] = $m['key'];
                }
            }
            $set['button'][] = $entry;
        }

        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/menu/create?access_token={$token}";
        if (!empty($conditional)) {
            $url = "https://api.weixin.qq.com/cgi-bin/menu/addconditional?access_token={$token}";
            $set['matchrule'] = [];
            if (!empty($conditional['group'])) {
                $set['matchrule']['group_id'] = $conditional['group'];
            }
            if (!empty($conditional['sex'])) {
                $set['matchrule']['sex'] = $conditional['sex'];
            }
            if (!empty($conditional['country'])) {
                $set['matchrule']['country'] = $conditional['country'];
            }
            if (!empty($conditional['province'])) {
                $set['matchrule']['province'] = $conditional['province'];
            }
            if (!empty($conditional['city'])) {
                $set['matchrule']['city'] = $conditional['city'];
            }
            if (!empty($conditional['client_platform_type'])) {
                $set['matchrule']['client_platform_type'] = $conditional['platform'];
            }
            if (!empty($conditional['language'])) {
                $set['matchrule']['language'] = $conditional['language'];
            }
        }
        $dat = json_encode($set, JSON_UNESCAPED_UNICODE);
        $resp = Net::httpPost($url, $dat);
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }

        return true;
    }

    /**
     * 删除当前公众号的菜单
     *
     * @return bool 是否删除成功
     */
    public function menuDelete($menuId = null)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/menu/delete?access_token={$token}";
        $resp = Net::httpGet($url);
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }

        return true;
    }

    /**
     * 修改当前公众号的菜单
     *
     * @param array $menu 统一菜单结构
     * @return bool 是否修改成功
     */
    public function menuModify(array $menu)
    {
        return $this->menuCreate($menu);
    }

    /**
     * 查询菜单
     *
     * @return array 统一菜单结构
     */
    public function menuQuery()
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/menu/get?access_token={$token}";
        $resp = Net::httpGet($url);
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            if ($result['errcode'] == '46003') {
                return array();
            }

            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }
        $types = array(
            'view',
            'view_limited',
            'media_id',
            'click',
            'scancode_push',
            'scancode_waitmsg',
            'pic_sysphoto',
            'pic_photo_or_album',
            'pic_weixin',
            'location_select'
        );
        $menus = array();
        foreach ($result['selfmenu_info']['button'] as $val) {
            $m = array();
            $m['type'] = in_array($val['type'], $types) ? $val['type'] : 'view';
            $m['title'] = $val['name'];
            if (!empty($val['media_id'])) {
                $m['media_id'] = $val['media_id'];
            }
            if (!empty($val['url'])) {
                $m['url'] = $val['url'];
            }
            $m['subMenus'] = array();
            if (!empty($val['sub_button'])) {
                foreach ($val['sub_button'] as $v) {
                    $s = array();
                    $s['type'] = in_array($v['type'], $types) ? $v['type'] : 'view';
                    $s['title'] = $v['name'];
                    if (!empty($v['media_id'])) {
                        $m['media_id'] = $v['media_id'];
                    }
                    if (!empty($v['url'])) {
                        $m['url'] = $v['url'];
                    }
                    $m['subMenus'][] = $s;
                }
            }
            $menus[] = $m;
        }

        return $menus;
    }

    /**
     * 查询当前公号的粉丝标签
     *
     * @return array 统一分组结构集合
     */
    public function tagAll()
    {
        // TODO: Implement tagAll() method.
    }

    /**
     * 在当前公号记录中创建一个粉丝标签
     *
     * @param array $tag 标签结构
     * @return bool 是否执行成功
     */
    public function tagCreate(array $tag)
    {
        // TODO: Implement tagCreate() method.
    }

    /**
     * 在当前公号记录中修改一条粉丝标签
     *
     * @param array $tag 标签结构
     * @return bool 是否执行成功
     */
    public function tagModify(array $tag)
    {
        // TODO: Implement tagModify() method.
    }

    /**
     * 查询指定的用户的标签列表
     *
     * @param string $openid 指定用户
     * @return array $tag 标签结构
     */
    public function tagQuery($openid)
    {
        // TODO: Implement tagQuery() method.
    }

    /**
     * 将指定用户列表添加上某个标签
     *
     * @param array $openids 指定用户
     * @param array $tag 标签结构
     * @return bool 是否执行成功
     */
    public function tagFansAdd(array $openids, array $tag)
    {
        // TODO: Implement tagFansAdd() method.
    }

    /**
     * @param array $openids
     * @param array $tag
     * @return mixed
     */
    public function tagFansRemove(array $openids, array $tag)
    {
        // TODO: Implement tagFansRemove() method.
    }

    /**
     * 查询指定的用户的基本信息
     *
     * @param string $openid 指定用户
     * @return array 统一粉丝信息结构
     */
    public function fanQueryInfo($openid)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$token}&openid={$openid}&lang=zh_CN";
        $response = Net::httpGet($url);
        if (is_error($response)) {
            return error(-1, "访问公众平台接口失败, 错误: {$response['message']}");
        }
        $result = @json_decode($response, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$response}");
        } elseif (!empty($result['errcode'])) {
            if ($result['errcode'] == '40001') {
                $this->fetchToken(true);
            }

            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }
        $ret = [];
        $ret['openid'] = $result['openid'];
        if (!empty($result['subscribe'])) {
            $ret['unionid'] = '';
            if (!empty($result['unionid'])) {
                $ret['unionid'] = $result['unionid'];
            }
            $ret['nickname'] = $result['nickname'];
            $ret['gender'] = '未知';
            if ($result['sex'] == '1') {
                $ret['gender'] = '男';
            }
            if ($result['sex'] == '2') {
                $ret['gender'] = '女';
            }
            $ret['city'] = $result['city'];
            $ret['state'] = $result['province'];
            $ret['avatar'] = $result['headimgurl'];
        }
        $ret['original'] = $result;

        return $ret;
    }

    /**
     * 解密并获取群标记
     *
     * @param $sessionKey
     * @param $encryptedData
     * @return mixed
     */
    public function fanQueryGroup($sessionKey, $encryptedData)
    {
        // TODO: Implement fanQueryGroup() method.
    }

    /**
     * 解密并获取手机号码
     *
     * @param $sessionKey
     * @param $encryptedData
     * @return mixed
     */
    public function fanQueryPhone($sessionKey, $encryptedData)
    {
        // TODO: Implement fanQueryPhone() method.
    }

    /**
     * 查询当前公号的所有粉丝
     *
     * @param string $next 下一个粉丝, 用于分页
     * @param array $raw 返回数据信息
     * @return array 粉丝的OPENID集合
     */
    public function fansAll($next = '', &$raw = [])
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/user/get?access_token={$token}&next_openid={$next}";
        $response = Net::httpGet($url);
        if (is_error($response)) {
            return error(-1, "访问公众平台接口失败, 错误: {$response['message']}");
        }
        $result = @json_decode($response, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$response}");
        } elseif (!empty($result['errcode'])) {
            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }
        $ds = [];
        foreach ($result['data']['openid'] as $openid) {
            $ds[] = $openid;
        }
        $raw['total'] = $result['total'];
        $raw['next'] = empty($result['data']['next_openid']) ? 0 : $result['data']['next_openid'];

        return $ds;
    }

    /**
     * 生成临时的二维码
     *
     */
    public function qrCreateDisposable(array $barcode)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$token}";
        $pars = [];
        $pars['expire_seconds'] = empty($barcode['expire']) ? '2592000' : $barcode['expire'];
        $pars['action_name'] = 'QR_STR_SCENE';
        $pars['action_info']['scene']['scene_str'] = $barcode['text'];
        $resp = Net::httpPost($url, json_encode($pars));
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }
        $ret = [];
        $ret['url'] = 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode($result['ticket']);
        $ret['raw'] = $result['url'];

        return $ret;
    }

    /**
     * 生成永久的二维码
     */
    public function qrCreateFixed(array $barcode)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$token}";
        $pars = [];
        $pars['action_name'] = 'QR_LIMIT_STR_SCENE';
        $pars['action_info']['scene']['scene_str'] = $barcode['text'];
        $resp = Net::httpPost($url, json_encode($pars));
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }
        $ret = [];
        $ret['url'] = 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode($result['ticket']);
        $ret['raw'] = $result['url'];

        return $ret;
    }

    /**
     * 创建分享接口参数
     */
    public function jsDataCreate($url = '')
    {
        if (empty($url) && !empty($_SERVER['HTTP_REFERER'])) {
            $url = $_SERVER['HTTP_REFERER'];
        }
        if (empty($url)) {
            return error(-1, '需要指定url');
        }
        $ticket = @unserialize($this->account['jsticket']);
        if (is_array($ticket) && !empty($ticket['ticket']) && !empty($ticket['expire']) && $ticket['expire'] > time()) {
            $t = $ticket['ticket'];
        } else {
            $token = $this->fetchToken();
            if (is_error($token)) {
                return $token;
            }
            $apiUrl = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token={$token}&type=jsapi";
            $resp = Net::httpGet($apiUrl);
            if (is_error($resp)) {
                return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
            }
            $ret = @json_decode($resp, true);
            if (empty($ret)) {
                return error(-2, "接口调用失败, 错误信息: {$resp}");
            } elseif (!empty($ret['errcode'])) {
                return error($ret['errcode'], "访问微信接口错误, 错误代码: {$ret['errcode']}, 错误信息: {$ret['errmsg']}");
            }

            $rec = array();
            $rec['ticket'] = $ret['ticket'];
            $rec['expire'] = time() + $ret['expires_in'];
            $this->account['jsticket'] = serialize($rec);
            call_user_func($this->saver, $this->account);
            $t = $rec['ticket'];
        }

        $share = array();
        $share['appid'] = $this->account['appid'];
        $share['timestamp'] = time();
        $share['nonce'] = md5(uniqid());

        $string1 = "jsapi_ticket={$t}&noncestr={$share['nonce']}&timestamp={$share['timestamp']}&url={$url}";
        $share['signature'] = sha1($string1);
        return $share;
    }

    /**
     * 上传临时素材(多媒体文件)
     *
     * @param $type
     * @param $file
     */
    public function uploadMedia($type, $file)
    {
        // TODO: Implement uploadMedia() method.
    }

    /**
     * 下载临时素材(多媒体文件)
     *
     * @param $mediaid
     * @param string $fname
     */
    public function downloadMedia($mediaid, $fname = '')
    {
        // TODO: Implement downloadMedia() method.
    }

    /**
     * 下载永久消息素材
     *
     * @param $type
     * @param int $pindex
     * @param int $psize
     * @param int $total
     */
    public function materialQuery($type, $pindex = 1, $psize = 20, &$total = 0)
    {
        // TODO: Implement materialQuery() method.
    }

    /**
     * 创建永久素材
     *
     * @param $packet array 素材内容
     * @return string|error
     */
    public function materialCreateFix($packet)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $pars = [];
        switch ($packet['type']) {
            case Platform::PACKET_IMAGE:
                $pars['type'] = 'image';
                $pars['media'] = $packet['file'];
                break;
            default:
                return error(-1, '不支持的文件类型');
        }

        $url = "https://api.weixin.qq.com/cgi-bin/material/add_material?access_token={$token}&type={$pars['type']}";
        $body = [];
        if (class_exists('CURLFile')) {
            $body['media'] = new \CURLFile($pars['media']);
        } else {
            $body['media'] = '@' . $pars['media'];
        }
        $resp = Net::httpRequest($url, $body);
        if (is_error($resp)) {
            return error(-1, "访问公众平台接口失败, 错误: {$resp['message']}");
        }
        $resp = $resp['content'];
        $result = @json_decode($resp, true);
        if (empty($result)) {
            return error(-2, "接口调用失败, 错误信息: {$resp}");
        } elseif (!empty($result['errcode'])) {
            return error($result['errcode'], "访问微信接口错误, 错误代码: {$result['errcode']}, 错误信息: {$result['errmsg']}");
        }
        $ret = [];
        $ret['type'] = $packet['type'];
        $ret['media'] = $result['media_id'];
        if (!empty($result['url'])) {
            $ret['url'] = $result['url'];
        }

        return $ret;
    }

    /**
     * 获取用户统计情况
     *
     * @param $start
     */
    public function summaryUserGet($start)
    {
        // TODO: Implement summaryUserGet() method.
    }

    /**
     * 获取图文统计情况
     *
     * @param $date
     */
    public function summaryArticleGet($date)
    {
        // TODO: Implement summaryArticleGet() method.
    }

    /**
     * 通过oAuth获取当前网站的访问用户
     */
    public function auth($type = 'auto', $host = '')
    {
        // TODO: Implement auth() method.
    }

    /**
     * @param string $code
     * @return \Error|array
     */
    public function authUser(string $code)
    {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->account['appid']}&secret={$this->account['secret']}&code={$code}&grant_type=authorization_code";
        $content = Net::httpGet($url);
        if (is_error($content)) {
            return error(-1, '微信通信失败(GetUserInfo), 请稍后重试！错误详情: ' . $content['message']);
        }
        $token = @json_decode($content, true);
        if (empty($token) || !is_array($token) || empty($token['openid'])) {
            return error(-2, '微信通信失败(GetUserInfo), 请稍后重试！错误详情: ' . $content);
        }
        $user = array();
        $user['openid'] = $token['openid'];
        if ($token['scope'] == 'snsapi_userinfo') {
            $url = "https://api.weixin.qq.com/sns/userinfo?access_token={$token['access_token']}&openid={$user['openid']}&lang=zh_CN";
            $content = Net::httpGet($url);
            $info = @json_decode($content, true);
            if (!empty($info) && is_array($info) && !empty($info['openid'])) {
                $user['openid'] = $info['openid'];
                $user['unionid'] = '';
                if (!empty($info['unionid'])) {
                    $user['unionid'] = $info['unionid'];
                }
                $user['nickname'] = $info['nickname'];
                $user['gender'] = '保密';
                if ($info['sex'] == '1') {
                    $user['gender'] = '男';
                }
                if ($info['sex'] == '2') {
                    $user['gender'] = '女';
                }
                $user['city'] = $info['city'];
                $user['state'] = $info['province'];
                $user['avatar'] = $info['headimgurl'];
                $user['original'] = $info;
            }
        }

        return $user;
    }

    /**
     * @param string $type
     * @param string $host
     * @return mixed
     */
    public function authBuildUrl($type = 'auto', $host = '')
    {
        // TODO: Implement authBuildUrl() method.
    }

    /**
     * 发放企业付款
     *
     * @param string $openid
     * @param array $trade
     *            tid:  付款序号
     *            fee:  付款金额
     *            description: 付款说明
     * @param array $config
     *            mchid:  商户号
     *            ip:  服务器IP
     *            password:  支付密钥
     *            ca:  CA证书
     *            cert:  Cert证书
     *            key:  Key证书
     * @return true|error
     */
    public function payBusiness($openid, array $trade, array $config = null)
    {
        $url = 'https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers';
        $pars = [];
        $pars['mch_appid'] = $this->account['appid'];
        $pars['mchid'] = $config['mchid'];
        $pars['nonce_str'] = md5(uniqid());
        if (empty($trade['tid'])) {
            $trade['tid'] = rand(0, 9999999999);
        }
        $pars['partner_trade_no'] = date('YmdHis') . sprintf('%010d', $trade['tid']);
        $pars['openid'] = $openid;
        $pars['check_name'] = 'NO_CHECK';
        $pars['amount'] = $trade['fee'] * 100;
        $pars['desc'] = $trade['description'];
        $pars['spbill_create_ip'] = $config['ip'];

        ksort($pars, SORT_STRING);
        $string1 = '';
        foreach ($pars as $k => $v) {
            $string1 .= "{$k}={$v}&";
        }
        $string1 .= "key={$config['password']}";
        $pars['sign'] = strtoupper(md5($string1));
        $xml = '<xml>';
        foreach ($pars as $k => $v) {
            $xml .= "<{$k}>{$v}</{$k}>";
        }
        $xml .= '</xml>';

        $extras = [];
        $extras['CURLOPT_CAINFO'] = $config['ca'];
        $extras['CURLOPT_SSLCERT'] = $config['cert'];
        $extras['CURLOPT_SSLKEY'] = $config['key'];

        $procResult = null;
        $headers = [];
        $resp = Net::httpRequest($url, $xml, $headers, '', 60, $extras);
        if (is_error($resp)) {
            return $resp;
        } else {
            $xml = '<?xml version="1.0" encoding="utf-8"?>' . $resp['content'];
            $dom = new \DOMDocument();
            if ($dom->loadXML($xml, LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
                $xpath = new \DOMXPath($dom);
                $code = $xpath->evaluate('string(//xml/return_code)');
                $ret = $xpath->evaluate('string(//xml/result_code)');
                if (strtolower($code) == 'success' && strtolower($ret) == 'success') {
                    $procResult = true;
                } else {
                    $error = $xpath->evaluate('string(//xml/err_code_des)');
                    $procResult = error(-2, $error);
                    $errcode = $xpath->evaluate('string(//xml/err_code)');
                    if ($errcode == 'FREQ_LIMIT') {
                        $procResult = error(-999, $error);
                    }
                }
            } else {
                $procResult = error(-1, 'error response');
            }
        }

        if (is_error($procResult)) {
            return $procResult;
        } else {
            return true;
        }
    }

    /**
     * 发放红包
     *
     * @param string $openid
     * @param array $packet
     *            type:  fission - 裂变红包; basic - 单个现金红包
     *            tid:  红包序号
     *            fee:  红包金额
     *            sender:  红包发送方
     *            wish:  红包祝福语
     *            activity:  红包活动名称
     *            remark:  红包备注说明
     *
     * @param array $config
     *            mchid:  商户号
     *            ip:  服务器IP
     *            password:  支付密钥
     *            ca:  CA证书
     *            cert:  Cert证书
     *            key:  Key证书
     *
     * @return true | error
     */
    public function payRedpacket($openid, array $packet, array $config = null)
    {
        // TODO: Implement payRedpacket() method.
    }

    /**
     * 创建在线支付订单
     *
     * @param string $openid
     *
     * @param array $trade
     *          tid:  订单编号
     *          fee:  订单金额
     *          title:  商品名称
     *          attachment:  附加数据
     *          type:trade_type h5 - H5浏览器支付 wx - 微信浏览器支付
     *
     * @param array $config
     *          mchid:  商户号
     *          password:  支付密钥
     *          notify:  回调地址
     *
     * @return array | error
     */
    public function payCreateOrder($openid, array $trade, array $config = null)
    {
        $pars = array();
        $pars['appid'] = $this->account['appid'];
        $pars['mch_id'] = $config['mchid'];
        $pars['nonce_str'] = md5(uniqid());
        $pars['body'] = $trade['title'];
        if (!empty($trade['attachment'])) {
            $pars['attach'] = $trade['attachment'];
        }
        $pars['out_trade_no'] = $trade['tid'];
        $pars['total_fee'] = $trade['fee'] * 100;
        $pars['spbill_create_ip'] = Helper::getClientIp(0, true);
        $pars['notify_url'] = $config['notify'];
        $pars['trade_type'] = $trade['type'] == 'h5' ? 'MWEB' : 'JSAPI';
        $pars['openid'] = $openid;

        ksort($pars, SORT_STRING);
        $string1 = '';
        foreach ($pars as $k => $v) {
            $string1 .= "{$k}={$v}&";
        }
        $string1 .= "key={$config['password']}";
        $pars['sign'] = strtoupper(md5($string1));
        $xml = '<xml>';
        foreach ($pars as $k => $v) {
            $xml .= "<{$k}>{$v}</{$k}>";
        }
        $xml .= '</xml>';

        $url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
        $resp = Net::httpPost($url, $xml);
        if (is_error($resp)) {
            return $resp;
        } else {
            $xml = '<?xml version="1.0" encoding="utf-8"?>' . $resp;
            $dom = new \DOMDocument();
            if ($dom->loadXML($xml, LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
                $xpath = new \DOMXPath($dom);
                $code = $xpath->evaluate('string(//xml/return_code)');
                $ret = $xpath->evaluate('string(//xml/result_code)');
                if (strtolower($code) == 'success' && strtolower($ret) == 'success') {
                    $prepay = $xpath->evaluate('string(//xml/prepay_id)');
                    $params = array();
                    $params['appId'] = $this->account['appid'];
                    $params['timeStamp'] = strval(time());
                    $params['nonceStr'] = md5(uniqid());
                    $params['package'] = "prepay_id={$prepay}";
                    $params['signType'] = 'MD5';

                    ksort($params, SORT_STRING);
                    $string1 = '';
                    foreach ($params as $k => $v) {
                        $string1 .= "{$k}={$v}&";
                    }
                    $string1 .= "key={$config['password']}";
                    $r = array();
                    $r['appid'] = $this->account['appid'];
                    $r['timestamp'] = $params['timeStamp'];
                    $r['nonce'] = $params['nonceStr'];
                    $r['package'] = $params['package'];
                    $r['signature'] = strtoupper(md5($string1));

                    return $r;
                } else {
                    $error = $xpath->evaluate('string(//xml/return_msg)');

                    return error(-2, $error);
                }
            } else {
                return error(-1, 'error response');
            }
        }
    }

    /**
     * 确认在线支付订单结果
     *
     * @param string $input 支付结果元数据
     *
     * @param array $config
     *                      mchid:  商户号
     *                      password:  支付密钥
     *
     * @return array | error
     */
    public function payConfirmOrder($input, array $config = null)
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . $input;
        $dom = new \DOMDocument();
        if ($dom->loadXML($xml, LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
            $xpath = new \DOMXPath($dom);
            $code = $xpath->evaluate('string(//xml/return_code)');
            $ret = $xpath->evaluate('string(//xml/result_code)');
            if (strtolower($code) == 'success' && strtolower($ret) == 'success') {
                $pars = array();
                $vals = $xpath->query('//xml/*');
                foreach ($vals as $val) {
                    $pars[$val->tagName] = $val->nodeValue;
                }

                ksort($pars, SORT_STRING);
                $string1 = '';
                foreach ($pars as $k => $v) {
                    if (!in_array($k, array('sign'))) {
                        $string1 .= "{$k}={$v}&";
                    }
                }
                $string1 .= "key={$config['password']}";
                if ($pars['sign'] == strtoupper(md5($string1))) {
                    $ret = array();
                    $ret['openid'] = $pars['openid'];
                    $ret['tid'] = $pars['out_trade_no'];
                    $ret['fee'] = $pars['total_fee'] / 100;
                    $ret['attachment'] = $pars['attach'];
                    $ret['original'] = $pars;

                    return $ret;
                }
            } else {
                $error = $xpath->evaluate('string(//xml/return_msg)');

                return error(-2, $error);
            }
        } else {
            return error(-1, 'error response');
        }
    }

    /**
     * 创建在线支付订单退款
     *
     * @param array $order
     *            refundid:  退款编号
     *            transactionid:  支付订单号
     *            tid:  系统订单号
     *            fee:  订单金额
     *
     * @param array $config
     *            mchid:  商户号
     *            password:  支付密钥
     *            ca:  CA证书
     *            cert:  Cert证书
     *            key:  Key证书
     *
     * @return true | error
     */
    public function payRefund(array $order, array $config = null)
    {
        // TODO: Implement payRefund() method.
    }

    /**
     * 创建卡券调用参数
     *
     * @param array $filter
     */
    public function cardDataCreate(array $filter = null, $type = 'choose')
    {
        // TODO: Implement cardDataCreate() method.
    }

    /**
     * 查询特定用户是拥有(特定)卡券
     *
     * @param $openid
     */
    public function cardQueryUser($openid, $card = '')
    {
        // TODO: Implement cardQueryUser() method.
    }

    /**
     * 查询特定优惠券详情
     *
     * @param $card
     */
    public function cardQueryInfo($card)
    {
        // TODO: Implement cardQueryInfo() method.
    }

    /**
     * 查询优惠券编码信息
     */
    public function cardQueryCode($code, $isEncrypt = false)
    {
        // TODO: Implement cardQueryCode() method.
    }

    /**
     * 核销优惠券
     *
     * @param $code
     */
    public function cardConsume($code)
    {
        // TODO: Implement cardConsume() method.
    }

    /**
     * 生成短连接
     *
     * @param $url
     */
    public function shortUrl($url)
    {
        // TODO: Implement shortUrl() method.
    }

    protected function fetchToken($force = false)
    {
        $access = unserialize($this->account['access']);
        if (!$force && !empty($access) && !empty($access['token']) && $access['expire'] > time()) {
            return $access['token'];
        } else {
            $ret = self::getAccessToken($this->account['appid'], $this->account['secret']);
            if (is_error($ret)) {
                return $ret;
            } else {
                $this->account['access'] = serialize($ret);
                call_user_func($this->saver, $this->account);

                return $ret['token'];
            }
        }
    }
}
