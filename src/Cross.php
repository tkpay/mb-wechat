<?php

namespace mb\wechat;

use mb\helper\Net;
use mb\wechat\platform\WeOpen;
use think\facade\Request;

/**
 * 微信开发平台相关功能封装（用于开发平台管理）
 * @package mb\wechat
 */
class Cross
{
    private $config = array();
    private $saver = array();
    private $inputRaw = null;

    function __construct(array $config, callable $saver)
    {
        $this->config = $config;
        $this->saver = $saver;
    }

    public function inputRaw()
    {
        return $this->inputRaw;
    }

    public function decode($input)
    {
        $key = base64_decode($this->config['key'] . '=');
        $iv = substr($key, 0, 16);
        $text = openssl_decrypt($input, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv);
        //PKCS7 decode
        $pad = ord(substr($text, -1));
        if ($pad < 1 || $pad > 32) {
            $pad = 0;
        }
        $result = substr($text, 0, (strlen($text) - $pad));
        //PKCS7 decode end
        if (strlen($result) < 16) {
            return '';
        }
        $content = substr($result, 16, strlen($result));
        $len_list = unpack("N", substr($content, 0, 4));
        $useful_len = $len_list[1];
        $useful_content = substr($content, 4, $useful_len);

        return $useful_content;
    }

    public function encode($raw)
    {
        $key = base64_decode($this->config['key'] . '=');
        $text = substr(md5(uniqid()), 0, 16) . pack("N", strlen($raw)) . $raw . $this->config['appid'];
        //pkcs7 encode
        $block_size = 32;
        $text_length = strlen($text);
        $amount_to_pad = $block_size - ($text_length % $block_size);
        if ($amount_to_pad == 0) {
            $amount_to_pad = block_size;
        }
        $pad_chr = chr($amount_to_pad);
        $tmp = "";
        for ($index = 0; $index < $amount_to_pad; $index++) {
            $tmp .= $pad_chr;
        }
        $text = $text . $tmp;
        //pkcs7 encode end
        $iv = substr($key, 0, 16);
        $encrypted = openssl_encrypt($text, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv);

        return $encrypted;
    }

    public function wrapperPacket($input)
    {
        $pars = array();
        $pars['encrypt'] = $this->encode($input);
        $pars['stamp'] = time();
        $pars['nonce'] = md5(uniqid());

        $params = array_values($pars);
        $params[] = $this->config['token'];
        sort($params, SORT_STRING);
        $sign = sha1(implode($params));
        $xml = <<<DOC
<xml>
    <Encrypt><![CDATA[{$pars['encrypt']}]]></Encrypt>
    <MsgSignature><![CDATA[{$sign}]]>></MsgSignature>
    <TimeStamp>{$pars['stamp']}</TimeStamp>
    <Nonce><![CDATA[{$pars['nonce']}]]></Nonce>
</xml> 
DOC;
        $xml = trim($xml);

        return $xml;
    }

    public function checkSign()
    {
        $input = input('get.');

        $params = array();
        $params[] = $this->config['token'];
        $params[] = $input['timestamp'];
        $params[] = $input['nonce'];
        sort($params, SORT_STRING);
        $string1 = implode($params);
        $sign1 = sha1($string1);

        $inputRaw = input_raw(false);
        $dom = new \DOMDocument();
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . $inputRaw;
        if (!$dom->loadXML($xml, LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
            return false;
        }
        $xpath = new \DOMXPath($dom);
        $encrypt = $xpath->evaluate('string(//xml/Encrypt)');
        $params[] = $encrypt;
        sort($params, SORT_STRING);
        $string2 = implode($params);
        $sign2 = sha1($string2);
        if ($sign1 == $input['signature'] && $sign2 == $input['msg_signature']) {
            $this->inputRaw = $this->decode($encrypt);

            return true;
        }

        return false;
    }

    public function flushTicket()
    {
        if (Request::isPost() && $this->checkSign()) {
            $dom = new \DOMDocument();
            $xml = '<?xml version="1.0" encoding="utf-8"?>' . $this->inputRaw;
            if ($dom->loadXML($xml, LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
                $xpath = new \DOMXPath($dom);
                $infoType = $xpath->evaluate('string(//xml/InfoType)');
                if ($infoType == 'component_verify_ticket') {
                    $ticket = $xpath->evaluate('string(//xml/ComponentVerifyTicket)');
                    if (!empty($ticket)) {
                        $this->config['ticket'] = $ticket;
                        call_user_func($this->saver, $this->config);

                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function robotVerify()
    {
        $account = [
            'appid' => 'wx570bc396a51b8ff8',
            'access' => '',
            'refresh' => ''
        ];
        $account['cross'] = $this;
        $saver = function ($input) {
        };
        $mp = new WeOpen($account, $saver);
        $mp->on(Platform::MSG_TEXT, function ($msg) {
            if ($msg['content'] == 'TESTCOMPONENT_MSG_TYPE_TEXT') {
                $packet = [];
                $packet['type'] = Platform::PACKET_TEXT;
                $packet['content'] = 'TESTCOMPONENT_MSG_TYPE_TEXT_callback';

                return $packet;
            }
            if (substr($msg['content'], 0, 15) == 'QUERY_AUTH_CODE') {
                //客服回复
                $authCode = substr($msg['content'], 16);
                $authorizer = $this->getAuthorizer($authCode);

                $account = [
                    'appid' => 'wx570bc396a51b8ff8',
                    'access' => serialize($authorizer['access']),
                    'refresh' => $authorizer['refresh'],
                ];
                $account['cross'] = $this;
                $saver = function ($input) {
                };
                $mp = new WeOpen($account, $saver);
                $mp->push($msg['from'], [
                    'type' => Platform::MSG_TEXT,
                    'content' => "{$authCode}_from_api"
                ]);
                exit;
            }
        });

        $cfg = [];
        $mp->start($cfg);
    }

    public function createAuthUrl($callback)
    {
        $access = $this->getAccessToken();
        if (is_error($access)) {
            return $access;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode?component_access_token={$access}";
        $params = array();
        $params['component_appid'] = $this->config['appid'];
        $content = Net::httpPost($url, json_encode($params));
        if (is_error($content)) {
            return error(-1, '微信通信失败(PreAuthCode), 请稍后重试！错误详情: ' . $content['message']);
        }
        $token = @json_decode($content, true);
        if (empty($token) || !is_array($token) || empty($token['pre_auth_code']) || empty($token['expires_in'])) {
            return error(-2, '微信通信失败(PreAuthCode), 请稍后重试！错误详情: ' . $content);
        }
        $code = $token['pre_auth_code'];
        $url = "https://mp.weixin.qq.com/cgi-bin/componentloginpage?component_appid={$this->config['appid']}&pre_auth_code={$code}&redirect_uri={$callback}";

        return $url;
    }

    public function getAuthorizer($authCode)
    {
        $access = $this->getAccessToken();
        if (is_error($access)) {
            return $access;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/component/api_query_auth?component_access_token={$access}";
        $params = array();
        $params['component_appid'] = $this->config['appid'];
        $params['authorization_code'] = $authCode;
        $content = Net::httpPost($url, json_encode($params));
        if (is_error($content)) {
            return error(-1, '微信通信失败(QueryAuth), 请稍后重试！错误详情: ' . $content['message']);
        }
        $auth = @json_decode($content, true);
        if (empty($auth) || !is_array($auth) || empty($auth['authorization_info'])) {
            return error(-2, '微信通信失败(QueryAuth), 请稍后重试！错误详情: ' . $content);
        }
        $rec = array();
        $rec['appid'] = $auth['authorization_info']['authorizer_appid'];
        if (!empty($auth['authorization_info']['authorizer_access_token'])) {
            $rec['access']['token'] = $auth['authorization_info']['authorizer_access_token'];
            $rec['access']['expire'] = time() + intval($auth['authorization_info']['expires_in']);
            $rec['refresh'] = $auth['authorization_info']['authorizer_refresh_token'];
        }

        $url = "https://api.weixin.qq.com/cgi-bin/component/api_get_authorizer_info?component_access_token={$access}";
        $params = array();
        $params['component_appid'] = $this->config['appid'];
        $params['authorizer_appid'] = $rec['appid'];
        $content = Net::httpPost($url, json_encode($params));
        if (is_error($content)) {
            return error(-1, '微信通信失败(GetAuth), 请稍后重试！错误详情: ' . $content['message']);
        }
        $info = @json_decode($content, true);
        if (empty($info) || !is_array($info) || empty($info['authorizer_info'])) {
            return error(-2, '微信通信失败(GetAuth), 请稍后重试！错误详情: ' . $content);
        }
        $rec['title'] = $info['authorizer_info']['nick_name'];
        if (in_array($info['authorizer_info']['service_type_info']['id'], array('0', '1'))) {
            if (in_array($info['authorizer_info']['verify_type_info']['id'], array('0', '3', '4', '5'))) {
                $rec['level'] = '1';
            } else {
                $rec['level'] = '0';
            }
        } else {
            if (in_array($info['authorizer_info']['verify_type_info']['id'], array('0', '3', '4', '5'))) {
                $rec['level'] = '11';
            } else {
                $rec['level'] = '10';
            }
        }
        $rec['avatar'] = $info['authorizer_info']['head_img'];
        $rec['original'] = $info['authorizer_info']['user_name'];
        $rec['username'] = $info['authorizer_info']['alias'];
        $rec['functions'] = array();
        if (is_array($info['authorization_info']['func_info'])) {
            foreach ($info['authorization_info']['func_info'] as $func) {
                $rec['functions'][] = $func['funcscope_category']['id'];
            }
        }

        return $rec;
    }

    public function getAuthorizerAccessToken($authorizer, $refresh)
    {
        $access = $this->getAccessToken();
        if (is_error($access)) {
            return $access;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/component/api_authorizer_token?component_access_token={$access}";
        $pars = array();
        $pars['component_appid'] = $this->config['appid'];
        $pars['authorizer_appid'] = $authorizer;
        $pars['authorizer_refresh_token'] = $refresh;
        $content = Net::httpPost($url, json_encode($pars));
        if (is_error($content)) {
            return error(-1, '微信通信失败(AuthorizerAccessToken), 请稍后重试！错误详情: ' . $content['message']);
        }
        $token = @json_decode($content, true);
        if (empty($token) || !is_array($token) || empty($token['authorizer_access_token'])) {
            return error(-2, '微信通信失败(AuthorizerAccessToken), 请稍后重试！错误详情: ' . $content);
        }
        $rec = array();
        $rec['access']['token'] = $token['authorizer_access_token'];
        $rec['access']['expire'] = time() + intval($token['expires_in']);
        $rec['refresh'] = $token['authorizer_refresh_token'];

        return $rec;
    }

    public function createAuthorizerAuthUrl($authorizer, $type = 'snsapi_userinfo', $state = '', $callback = '')
    {
        if (empty($callback)) {
            $callback = __HOST__ . MAU('auth/auth');
        }
        $forward = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$authorizer}&redirect_uri={$callback}&response_type=code&scope={$type}&state={$state}&component_appid={$this->config['APPID']}#wechat_redirect";

        return $forward;
    }

    public function getAuthorizerUserInfo($authorizer, $code)
    {
        $access = $this->getAccessToken();
        if (is_error($access)) {
            return $access;
        }
        $url = "https://api.weixin.qq.com/sns/oauth2/component/access_token?appid={$authorizer}&code={$code}&grant_type=authorization_code&component_appid={$this->config['APPID']}&component_access_token={$access}";
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
                $user['unionid'] = $info['unionid'];
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
                $user['country'] = $info['country'];
                if (!empty($user['avatar'])) {
                    $user['avatar'] = rtrim($user['avatar'], '0');
                    $user['avatar'] .= '132';
                }
                $user['original'] = $info;
            }
        }

        return $user;
    }

    private function getAccessToken()
    {
        $access = unserialize($this->config['access']);
        if (empty($access) || empty($access['token']) || $access['expire'] < time()) {
            $url = "https://api.weixin.qq.com/cgi-bin/component/api_component_token";
            $params = array();
            $params['component_appid'] = $this->config['appid'];
            $params['component_appsecret'] = $this->config['secret'];
            $params['component_verify_ticket'] = $this->config['ticket'];
            $content = Net::httpPost($url, json_encode($params));
            if (is_error($content)) {
                return error(-1, '微信通信失败(AccessToken), 请稍后重试！错误详情: ' . $content['message']);
            }
            $token = @json_decode($content, true);
            if (empty($token) || !is_array($token) || empty($token['component_access_token']) || empty($token['expires_in'])) {
                return error($token['errcode'], $token['errmsg']);
            }
            $record = array();
            $record['token'] = $token['component_access_token'];
            $record['expire'] = time() + $token['expires_in'];
            $this->config['access'] = serialize($record);
            call_user_func($this->saver, $this->config);

            return $record['token'];
        }

        return $access['token'];
    }
}
