<?php

namespace mb\wechat\platform;

use Error;
use mb\helper\Collection;
use mb\helper\Net;
use mb\wechat\Helper;
use mb\wechat\Platform;

/**
 * 小程序处理对象封装（用于小程序相关接口）
 * @package mb\wechat\platform
 */
class WeApp extends WeXin
{
    /**
     * 特定公众号平台的操作对象构造方法
     *
     * @param array $account 公号平台数据
     * @param callable|null $saver 持久化操作
     */
    public function __construct(array $account, callable $saver = null)
    {
        $this->account = $account;
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

    public function push($openid, array $packet)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $pars = [];
        $pars['touser'] = $openid;
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
            $pars['msgtype'] = 'link';
            foreach ($packet['news'] as $article) {
                $pars['link'] = array(
                    'title' => urlencode($article['title']),
                    'description' => urlencode($article['description']),
                    'url' => $article['url'],
                    'thumb_url' => $article['image']
                );
            }
        }
        if ($packet['type'] == Platform::PACKET_MINIPROGRAMPAGE) {
            $pars['msgtype'] = 'miniprogrampage';
            $pars['miniprogrampage']['title'] = urlencode($packet['title']);
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
     *
     * mp.appid: 公众号appId
     *
     * @param string $openid
     * @param array $notification
     * @return array|mixed
     */
    public function notify($openid, $notification)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        if (!empty($notification['mp']) && empty($notification['mp']['wa']['appid'])) {
            $notification['mp']['wa']['appid'] = $this->account['appid'];
        }
        $url = "https://api.weixin.qq.com/cgi-bin/message/wxopen/template/uniform_send?access_token={$token}";
        $params = [
            'touser' => $openid,
            'mp_template_msg' => [
                'appid' => $notification['mp']['appid'],
                'template_id' => $notification['mp']['template'],
                'url' => $notification['mp']['url'],
                'miniprogram' => [
                    'appid' => $notification['mp']['wa']['appid'],
                    'pagepath' => $notification['mp']['wa']['path'],
                ],
                'data' => $notification['mp']['data'],
            ]
        ];
        $response = Net::httpPost($url, json_encode($params, JSON_UNESCAPED_UNICODE));
        if (is_error($response)) {
            return $response;
        }
        $resp = json_decode($response, true);
        if (is_array($resp)) {
            if ($resp['errcode'] === 0) {
                return true;
            } else {
                return error($resp['errcode'], $resp['errmsg']);
            }
        }
        return error(-1, '未知错误');
    }


    /**
     * 查询指定的用户的基本信息
     *
     * @param array $params 查询参数 sessionKey, iv, encryptedData
     * @return array 统一粉丝信息结构
     */
    public function fanQueryInfo($params)
    {
        $decryptRet = $this->decryptData($params['sessionKey'], $params['iv'], $params['encryptedData']);
        if (is_error($decryptRet)) {
            return $decryptRet;
        }
        $ret = [
            'openid' => $decryptRet['openId'],
            'unionid' => empty($decryptRet['unionId']) ? '' : $decryptRet['unionId'],
            'nickname' => $decryptRet['nickName'],
            'gender' => $decryptRet['gender'] == '1' ? '男' : '女',
            'state' => $decryptRet['province'],
            'city' => $decryptRet['city'],
            'country' => $decryptRet['country'],
            'avatar' => $decryptRet['avatarUrl'],
        ];

        return $ret;
    }

    public function fanQueryPhone($sessionKey, $encryptedData)
    {
        $decryptRet = $this->decryptData($sessionKey, $encryptedData['iv'], $encryptedData['encryptedData']);
        if (is_error($decryptRet)) {
            return $decryptRet;
        }
        $ret = [
            'code' => $decryptRet['countryCode'],
            'phone' => $decryptRet['purePhoneNumber']
        ];

        return $ret;
    }

    public function fanQueryGroup($sessionKey, $encryptedData)
    {
        $decryptRet = $this->decryptData($sessionKey, $encryptedData['iv'], $encryptedData['encryptedData']);
        if (is_error($decryptRet)) {
            return $decryptRet;
        }
        $ret = [
            'opengid' => $decryptRet['openGId']
        ];

        return $ret;
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
        $url = "https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token={$token}";
        $pars = Collection::elements(['scene', 'page', 'width'], $barcode);

        $pars['is_hyaline'] = true;
        if (!empty($barcode['lineColor'])) {
            $pars['line_color'] = $barcode['lineColor'];
        }else {
            $pars['auto_color'] = true;
        }
        $dat = json_encode($pars);
        $resp = Net::httpPost($url, $dat);
        if (is_error($resp)) {
            return $resp;
        }
        if (substr($resp, 0, 10) === '{"errcode"') {
            $jsonArray = json_decode($resp, true);
            return error($jsonArray['errcode'], $jsonArray['errmsg']);
        }

        return $resp;
    }

    /**
     * 上传临时素材(多媒体文件)
     *
     * @param $type
     * @param $file
     * @return array|error
     */
    public function uploadMedia($type, $file)
    {
        $token = $this->fetchToken();
        if (is_error($token)) {
            return $token;
        }
        $url = "https://api.weixin.qq.com/cgi-bin/media/upload?access_token={$token}&type=image";
        $body = [];
        if (class_exists('CURLFile')) {
            $body['media'] = new \CURLFile($file);
        } else {
            $body['media'] = '@' . $file;
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
        $ret['type'] = 'image';
        $ret['media'] = $result['media_id'];
        if (!empty($result['url'])) {
            $ret['url'] = $result['url'];
        }
        return $ret;
    }

    /**
     * 通过oAuth获取当前网站的访问用户
     */
    public function auth($type = 'auto', $host = '')
    {
        $code = $type;
        if (empty($code)) {
            $code = input('param.code');
        }
        if (empty($code)) {
            return error(-1, '错误的访问方式');
        }
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$this->account['appid']}&secret={$this->account['secret']}&js_code={$code}&grant_type=authorization_code";
        $resp = Net::httpGet($url);
        if (is_error($resp)) {
            return $resp;
        }
        $object = @json_decode($resp, true);
        if (!empty($object['errcode'])) {
            return error($object['errcode'], $object['errmsg']);
        }

        $ret = [];
        $ret['openid'] = $object['openid'];
        $ret['unionid'] = array_key_exists('unionid', $object) ? $object['unionid'] : '';
        $ret['sessionKey'] = $object['session_key'];

        return $ret;
    }

    private function decryptData($sessionKey, $iv, $encryptedData)
    {
        if (strlen($sessionKey) != 24) {
            return error(-1, 'SessionKey 不合法');
        }
        $aesKey = base64_decode($sessionKey);

        if (strlen($iv) != 24) {
            return error(-2, 'IV 不合法');
        }
        $aesIV = base64_decode($iv);

        $aesCipher = base64_decode($encryptedData);
        $result = openssl_decrypt($aesCipher, "AES-128-CBC", $aesKey, 1, $aesIV);

        $dataObj = json_decode($result, true);
        if ($dataObj == null) {
            return error(-3, '解密数据错误');
        }
        if ($dataObj['watermark']['appid'] != $this->account['appid']) {
            return error(-3, '解密数据错误');
        }

        return $dataObj;
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
     *
     * @param array $config
     *          mchid:  商户号
     *          password:  支付密钥
     *          notify:  回调地址
     *
     * @return array | Error
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
        $pars['trade_type'] = 'JSAPI';
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

    public function payCreatedOrder2($authCode, $trade, $config)
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
        $pars['auth_code'] = $authCode;
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
        $url = 'https://api.mch.weixin.qq.com/pay/micropay';
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
                    $totalFee = $xpath->evaluate('string(//xml/total_fee)');
                    $openid = $xpath->evaluate('string(//xml/openid)');
                    $transactionId = $xpath->evaluate('string(//xml/transaction_id)');
                    $orderNo = $xpath->evaluate('string(//xml/out_trade_no)');
                    $cashFee = $xpath->evaluate('string(//xml/cash_fee)');
                    $params = [];
                    $params['appid'] = $this->account['appid'];
                    $params['totalFee'] = $totalFee;
                    $params['openid'] = $openid;
                    $params['transactionId'] = $transactionId;
                    $params['orderNo'] = $orderNo;
                    $params['cashFee'] = $cashFee;
                    return $params;
                } else {
                    $error = $xpath->evaluate('string(//xml/err_code_des)');
                    return error(-2, $error);
                }
            } else {
                return error(-1, 'error response');
            }
        }
    }

    public function payConfirmOrder2($orderNo, $config)
    {
        $pars = [];
        $pars['appid'] = $this->account['appid'];
        $pars['mch_id'] = $config['mchid'];
        $pars['out_trade_no'] = $orderNo;
        $pars['nonce_str'] = md5(uniqid());
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
        $url = 'https://api.mch.weixin.qq.com/pay/orderquery';
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
                    $openid = $xpath->evaluate('string(//xml/openid)');
                    $transactionId = $xpath->evaluate('string(//xml/transaction_id)');
                    if (empty($openid)) {
                        return error(-1, '订单未支付');
                    }
                    $totalFee = $xpath->evaluate('string(//xml/total_fee)');
                    $params = [];
                    $params['openid'] = $openid;
                    $params['transactionId'] = $transactionId;
                    $params['totalFee'] = $totalFee;
                    return $params;
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
                    $ret['fee'] = $pars['total_fee'];
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

    protected function fetchToken($force = false)
    {
        $access = $this->account['access'];
        if (!$force && !empty($access) && !empty($access['token']) && $access['expire'] > time()) {
            return $access['token'];
        } else {
            $ret = WeXin::getAccessToken($this->account['appid'], $this->account['secret']);
            if (is_error($ret)) {
                return $ret;
            } else {
                $this->account['access'] = $ret;
                call_user_func($this->saver, $this->account);

                return $ret['token'];
            }
        }
    }
}
