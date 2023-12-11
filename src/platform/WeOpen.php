<?php

namespace mb\wechat\platform;

use mb\helper\Net;
use mb\wechat\Cross;
use mb\wechat\Helper;
use mb\wechat\Platform;

/**
 * 适用于开放平台的微信对象封装，（用于通过开发平台接入服务号，订阅号）
 * @package mb\wechat\platform
 */
class WeOpen extends WeXin
{
    /**
     * @var Cross
     */
    private $cross;

    /**
     * 特定公众号平台的操作对象构造方法
     *
     * @param array $account 公号平台数据
     * @param callable|null $saver 持久化操作
     */
    public function __construct(array $account, callable $saver = null)
    {
        $this->cross = $account['cross'];
        unset($account['cross']);
        $this->account = $account;
        $this->saver = $saver;
    }

    public function start($config)
    {
        if (!$this->cross->checkSign()) {
            exit('signature fail');
        }
        $message = '<?xml version="1.0" encoding="utf-8"?>' . $this->cross->inputRaw();

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
            echo $this->buildPacket($hitPacket);
        }
        exit;
    }

    protected function buildPacket($packet)
    {
        $str = parent::buildPacket($packet);
        $wraper = $this->cross->wrapperPacket($str);

        return $wraper;
    }

    protected function fetchToken($force = false)
    {
        $access = unserialize($this->account['access']);
        if (!$force && !empty($access) && !empty($access['token']) && $access['expire'] > time()) {
            return $access['token'];
        } else {
            $ret = $this->cross->getAuthorizerAccessToken($this->account['appid'], $this->account['refresh']);
            if (is_error($ret)) {
                return $ret;
            } else {
                $this->account['access'] = serialize($ret['access']);
                $this->account['refresh'] = $ret['refresh'];
                call_user_func($this->saver, $this->account);

                return $ret['access']['token'];
            }
        }

    }
}
