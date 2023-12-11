<?php

namespace mb\wechat\platform\traits;

use mb\wechat\Platform;

/**
 * 为platform对象集成消息处理接口
 * @package mb\wechat\platform\traits
 */
trait Integration
{

    private $integrationConfig;
    private $eventPool = [];

    /**
     * 注册接受消息事件
     *
     * @param string $msg MSG_*
     * @param callable $processor 处理器
     * @return mixed
     */
    public function on($msg, callable $processor)
    {
        if (!is_callable($processor)) {
            return;
        }
        if (empty($this->eventPool[$msg])) {
            $this->eventPool[$msg] = [];
        }
        $this->eventPool[$msg][] = $processor;
    }

    /**
     * 反注册接受消息事件
     *
     * @param string $msg MSG_*
     * @param callable|null $processor 处理器
     * @return mixed
     */
    public function off($msg, callable $processor = null)
    {
        if (empty($processor)) {
            $this->eventPool[$msg] = [];
        } else {
            //移除单个消息事件
        }
    }

    public function start($config)
    {
        $this->integrationConfig = $config;
        if (!$this->integrationCheckSignature()) {
            //验证签名
            exit('signature fail');
        }
        $echoStr = input('get.echostr');
        if (!empty($echoStr)) {
            exit($echoStr);
        }
        $inputRaw = file_get_contents('php://input');
        $message = '<?xml version="1.0" encoding="utf-8"?>' . $inputRaw;

        $msg = $this->parseMessage($message);
        $hitPacket = null;
        if (!empty($msg) && !empty($msg['type'])) {
            $packets = $this->fireEvent($msg['type'], $msg);
            if (!empty($packets)) {
                $hitPacket = $packets[0];
            }
        }
        if (empty($hitPacket)) {
            $packets = $this->fireEvent(Platform::MSG_DEFAULT, $msg);
            if (!empty($packets)) {
                $hitPacket = $packets[0];
            }
        }
        if (!empty($hitPacket)) {
            $hitPacket['from'] = $msg['to'];
            $hitPacket['to'] = $msg['from'];
            return response($this->buildPacket($hitPacket));
        }
        return null;
    }

    protected function fireEvent($msgType, $msg)
    {
        $packets = [];
        if (!empty($this->eventPool[$msgType])) {
            $events = $this->eventPool[$msgType];
            foreach ($events as $event) {
                $packet = call_user_func($event, $msg);
                if (!empty($packet)) {
                    $packets[] = $packet;
                }
            }
        }

        return $packets;
    }

    private function integrationCheckSignature()
    {
        $pars = [
            $this->integrationConfig['token'],
            input('get.timestamp'),
            input('get.nonce')
        ];
        sort($pars, SORT_STRING);
        $string1 = implode($pars);
        $signature = sha1($string1);

        if ($signature == input('get.signature')) {
            return true;
        }

        return false;
    }

    protected function parseMessage($message)
    {
        $msg = array();
        if (!empty($message)) {
            $xml = $message;
            $dom = new \DOMDocument();
            if ($dom->loadXML($xml, LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
                $xpath = new \DOMXpath($dom);
                $msg['from'] = trim($xpath->evaluate('string(//xml/FromUserName)'));
                $msg['to'] = trim($xpath->evaluate('string(//xml/ToUserName)'));
                $msg['time'] = $xpath->evaluate('number(//xml/CreateTime)');
                $msg['type'] = 'unknow';
                $elms = $xpath->query('//xml/*');
                foreach ($elms as $elm) {
                    if ($elm->childNodes->length == 1) {
                        $msg['original'][$elm->nodeName] = trim(strval($elm->nodeValue));
                    }
                }
                $type = $msg['original']['MsgType'];
                if ($type == 'text') {
                    $msg['type'] = Platform::MSG_TEXT;
                    $msg['content'] = trim($xpath->evaluate('string(//xml/Content)'));
                }
                if ($type == 'image') {
                    $msg['type'] = Platform::MSG_IMAGE;
                    $msg['url'] = $xpath->evaluate('string(//xml/PicUrl)');
                    $msg['media'] = $xpath->evaluate('string(//xml/MediaId)');
                }
                if ($type == 'miniprogrampage') {
                    $msg['type'] = Platform::MSG_MINIPROGRAMPAGE;
                    $msg['title'] = $msg['original']['Title'];
                    $msg['appId'] = $msg['original']['AppId'];
                    $msg['page'] = $msg['original']['PagePath'];
                    $msg['image'] = $msg['original']['ThumbUrl'];
                    $msg['imageMediaId'] = $msg['original']['ThumbMediaId'];
                }

                if ($type == 'event') {
                    //处理其他事件类型
                    $event = $xpath->evaluate('string(//xml/Event)');
                    if ($event == 'subscribe') {
                        //开始关注
                        $msg['type'] = Platform::MSG_SUBSCRIBE;
                        if (!empty($msg['original']['eventkey'])) {
                            $msg['qr']['code'] = substr($msg['original']['eventkey'], 8);
                        }
                    }
                    if ($event == 'unsubscribe') {
                        //取消关注
                        $msg['type'] = Platform::MSG_UNSUBSCRIBE;
                    }
                    if ($event == 'SCAN') {
                        $msg['type'] = Platform::MSG_QR;
                        $msg['qr']['code'] = $msg['original']['eventkey'];
                    }
                    if ($event == 'ENTER' || $event == 'user_enter_tempsession') {
                        //进入对话
                        $msg['type'] = Platform::MSG_ENTER;
                        if (!empty($msg['original']['sessionfrom'])) {
                            $msg['session'] = $msg['original']['sessionfrom'];
                        }
                    }
                    if ($event == 'CLICK') {
                        $msg['type'] = Platform::MSG_MENU_CLICK;
                        $msg['key'] = $msg['original']['eventkey'];
                    }
                    if ($event == 'VIEW') {
                        $msg['type'] = Platform::MSG_MENU_VIEW;
                        $msg['url'] = $msg['original']['eventkey'];
                    }
                }
            }
        }

        return $msg;
    }

    protected function buildPacket($packet)
    {
        $type = '';
        if ($packet['type'] == Platform::PACKET_TEXT) {
            $type = 'text';
            $extras = "<Content><![CDATA[{$packet['content']}]]></Content>";
        }
        if ($packet['type'] == Platform::PACKET_IMAGE) {
            $type = 'image';
            $media = $packet['image'];
            $extras = "<Image><MediaId><![CDATA[{$media}]]></MediaId></Image>";
        }
        if ($packet['type'] == Platform::PACKET_NEWS) {
            $type = 'news';
            if (count($packet['news']) > 10) {
                $packet['news'] = array_rand($packet['news'], 10);
            }
            $count = count($packet['news']);
            $extras = "<ArticleCount>{$count}</ArticleCount><Articles>";
            foreach ($packet['news'] as $article) {
                $extras .= "<item><Title><![CDATA[{$article['title']}]]></Title><Description><![CDATA[{$article['description']}]]></Description><PicUrl><![CDATA[{$article['image']}]]></PicUrl><Url><![CDATA[{$article['url']}]]></Url></item>";
            }
            $extras .= "</Articles>";
        }
        $now = time();
        $resp = "<xml>
<ToUserName><![CDATA[{$packet['to']}]]></ToUserName>
<FromUserName><![CDATA[{$packet['from']}]]></FromUserName>
<CreateTime>{$now}</CreateTime>
<MsgType><![CDATA[{$type}]]></MsgType>
{$extras}
</xml>";

        return $resp;
    }
}
