<?php

namespace mb\wechat\platform;

use mb\helper\Net;
use mb\wechat\Helper;

/**
 * 独立App微信Sdk相关功能封装（用于独立App）
 * @package mb\wechat\platform
 */
class WxApp extends WeXin
{
    public function payCreateOrder($openid, array $trade, array $config = null)
    {
        $pars = array();
        $pars['appid'] = $this->account['appid'];
        $pars['mch_id'] = $config['n'];
        $pars['nonce_str'] = md5(uniqid());
        $pars['body'] = $trade['title'];
        if (!empty($trade['attachment'])) {
            $pars['attach'] = $trade['attachment'];
        }
        $pars['out_trade_no'] = $trade['tid'];
        $pars['total_fee'] = $trade['fee'] * 100;
        $pars['spbill_create_ip'] = Helper::getClientIp(0, true);
        $pars['notify_url'] = $config['notify'];
        $pars['trade_type'] = 'APP';

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
                    $params['appid'] = $this->account['appid'];
                    $params['partnerid'] = $config['mchid'];
                    $params['prepayid'] = $prepay;
                    $params['package'] = "Sign=WXPay";
                    $params['noncestr'] = md5(uniqid());
                    $params['timestamp'] = strval(time());

                    ksort($params, SORT_STRING);
                    $string1 = '';
                    foreach ($params as $k => $v) {
                        $string1 .= "{$k}={$v}&";
                    }
                    $string1 .= "key={$config['password']}";
                    $r = array();
                    $r['appid'] = $this->account['appid'];
                    $r['partner'] = $config['mchid'];
                    $r['prepay'] = $prepay;
                    $r['package'] = "Sign=WXPay";
                    $r['nonce'] = $params['noncestr'];
                    $r['timestamp'] = $params['timestamp'];
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
}
