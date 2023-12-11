<?php

namespace mb\wechat;

use app\Request;
use think\facade\Session;
use think\middleware\SessionInit;

class TrdSession
{
    public static function init($sessionKey = '')
    {
        if (empty($sessionKey)) {
            $sessionKey = self::getSessionKey();
        }
        Session::init();
    }

    public static function getSessionKey()
    {
        if (empty($sessionKey)) {
            $headers = getallheaders();
            $headers = array_change_key_case($headers);
            if (!empty($headers['token'])) {
                $sessionKey = $headers['token'];
            }
        }
        if (empty($sessionKey)) {
            $sessionKey = Session::getId();
        }
        if (empty($sessionKey)) {
            $sessionKey = getenv('HTTP_TOKEN');
        }
        if (empty($sessionKey)) {
            if (!empty($_SERVER['HTTP_TOKEN'])) {
                $sessionKey = $_SERVER['HTTP_TOKEN'];
            }
        }
        if (empty($sessionKey)) {
            $sessionKey = input('param.token');
        }
        if (empty($sessionKey)) {
            $sessionKey = md5(uniqid());
        }
        return $sessionKey;
    }
}