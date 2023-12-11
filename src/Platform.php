<?php

namespace mb\wechat;

/**
 * 平台操作抽象
 * @package mb\wechat
 */
interface Platform
{

    /**
     * 回复文字消息
     * content      - string: 文本内容
     */
    const PACKET_TEXT = 'text';
    /**
     * 回复图片消息
     * image        - string: 图片内容
     */
    const PACKET_IMAGE = 'image';
    /**
     * 回复图文消息
     * 消息定义为元素集合, 每个元素结构定义为
     * title        - string: 新闻标题,
     * description  - string: 新闻描述,
     * picurl       - string: 图片链接,
     * url          - string: 原文链接
     */
    const PACKET_NEWS = 'news';
    /**
     * 回复卡券信息
     * card         - string: 卡券编号
     * scene        - string: 发放场景
     */
    const PACKET_CARD = 'card';
    /**
     * 回复小程序信息
     * title    - 标题
     * appid    - 小程序编号
     * page     - 小程序页面
     * thumb    - 预览图片
     */
    const PACKET_MINIPROGRAMPAGE = 'mini';

    /**
     * 通用类型: text, image, voice, video, location, link,
     * 扩展类型: subscribe, unsubscribe, qr, trace, menu_click, menu_view, menu_scan, menu_scan_waiting, menu_photo, menu_album, menu_photo_album, menu_location, enter
     * 通用类型: 文本消息, 图片消息, 音频消息, 视频消息, 位置消息, 链接消息,
     * 扩展类型: 开始关注, 取消关注, 扫描二维码, 追踪位置, 点击菜单(模拟关键字), 点击菜单(链接), [更多菜单事件], 进入聊天窗口
     */

    /**
     * 粉丝发送文字消息
     */
    const MSG_TEXT = 'text';
    /**
     * 粉丝发送图片消息
     */
    const MSG_IMAGE = 'image';
    /**
     * 小程序项目
     */
    const MSG_MINIPROGRAMPAGE = 'miniprogrampage';
    /**
     * 粉丝关注
     */
    const MSG_SUBSCRIBE = 'subscribe';
    /**
     * 粉丝取消关注
     */
    const MSG_UNSUBSCRIBE = 'unsubscribe';
    /**
     * 粉丝扫描二维码
     */
    const MSG_QR = 'qr';
    /**
     * 粉丝进入对话
     */
    const MSG_ENTER = 'enter';
    /**
     * 粉丝点击菜单
     */
    const MSG_MENU_CLICK = 'menu_click';
    /**
     * 粉丝点击菜单链接
     */
    const MSG_MENU_VIEW = 'menu_view';
    /**
     * 没有命中任何事件的默认事件
     */
    const MSG_DEFAULT = 'default';

    /**
     * 特定公众号平台的操作对象构造方法
     *
     * @param array $account 公号平台数据
     * @param callable|null $saver 持久化操作
     */
    public function __construct(array $account, callable $saver = null);

    /**
     * 获取当前平台数据
     *
     * @return array
     */
    public function getAccount();

    /**
     * 注册接受消息事件
     *
     * @param string $msg MSG_*
     * @param callable $processor 处理器
     * @return mixed
     */
    public function on($msg, callable $processor);

    /**
     * 反注册接受消息事件
     *
     * @param string $msg MSG_*
     * @param callable|null $processor 处理器
     * @return mixed
     */
    public function off($msg, callable $processor = null);

    /**
     * 开始进行消息监听
     * @param array $config
     * @return mixed
     */
    public function start($config);

    /*
     * 向指定的用户推送消息
     * @param string $openid    指定用户
     * @param array $packet     统一响应结构
     * @return bool 是否成功
     */
    public function push($openid, array $packet);

    /**
     * 向一组用户发送群发消息, 可选的可以指定是否要指定特定组
     *
     * @param array $packet 统一消息结构
     * @param array|null $targets 单独向一组用户群发, 或指定fans列表发送
     */
    public function broadcast(array $packet, array $targets = null);

    /**
     * 向某个用户发送通知消息
     *
     * @param string $openid 用户编号
     * @param array $notification 通知消息结构
     */
    public function notify(string $openid, array $notification);

    /**
     * 为当前公众号创建菜单
     *
     * @param array $menu 统一菜单结构
     * @param array $conditional 菜单生效条件
     * @return bool 是否创建成功
     */
    public function menuCreate(array $menu, array $conditional = null);

    /**
     * 删除当前公众号的菜单
     *
     * @return bool 是否删除成功
     */
    public function menuDelete($menuId = null);

    /**
     * 修改当前公众号的菜单
     *
     * @param array $menu 统一菜单结构
     * @return bool 是否修改成功
     */
    public function menuModify(array $menu);

    /**
     * 查询菜单
     *
     * @return array 统一菜单结构
     */
    public function menuQuery();

    /**
     * 查询当前公号的粉丝标签
     *
     * @return array 统一分组结构集合
     */
    public function tagAll();

    /**
     * 在当前公号记录中创建一个粉丝标签
     *
     * @param array $tag 标签结构
     * @return bool 是否执行成功
     */
    public function tagCreate(array $tag);

    /**
     * 在当前公号记录中修改一条粉丝标签
     *
     * @param array $tag 标签结构
     * @return bool 是否执行成功
     */
    public function tagModify(array $tag);

    /**
     * 查询指定的用户的标签列表
     *
     * @param string $openid 指定用户
     * @return array $tag 标签结构
     */
    public function tagQuery($openid);

    /**
     * 将指定用户列表添加上某个标签
     *
     * @param array $openids 指定用户
     * @param array $tag 标签结构
     * @return bool 是否执行成功
     */
    public function tagFansAdd(array $openids, array $tag);

    /**
     * @param array $openids
     * @param array $tag
     * @return mixed
     */
    public function tagFansRemove(array $openids, array $tag);


    /**
     * 查询指定的用户的基本信息
     *
     * @param string $openid 指定用户
     * @return array 统一粉丝信息结构
     */
    public function fanQueryInfo($openid);

    /**
     * 解密并获取手机号码
     * @param $sessionKey
     * @param $encryptedData
     * @return mixed
     */
    public function fanQueryPhone($sessionKey, $encryptedData);

    /**
     * 解密并获取群标记
     * @param $sessionKey
     * @param $encryptedData
     * @return mixed
     */
    public function fanQueryGroup($sessionKey, $encryptedData);

    /**
     * 查询当前公号的所有粉丝
     *
     * @param string $next 下一个粉丝, 用于分页
     * @param array $raw 返回数据信息
     * @return array 粉丝的OPENID集合
     */
    public function fansAll($next = '', &$raw = []);

    /**
     * 生成临时的二维码
     *
     */
    public function qrCreateDisposable(array $barcode);

    /**
     * 生成永久的二维码
     */
    public function qrCreateFixed(array $barcode);

    /**
     * 创建分享接口参数
     */
    public function jsDataCreate($url = '');

    /**
     * 上传临时素材(多媒体文件)
     *
     * @param $type
     * @param $file
     */
    public function uploadMedia($type, $file);

    /**
     * 下载临时素材(多媒体文件)
     *
     * @param $mediaid
     * @param string $fname
     */
    public function downloadMedia($mediaid, $fname = '');

    /**
     * 下载永久消息素材
     *
     * @param $type
     * @param int $pindex
     * @param int $psize
     * @param int $total
     */
    public function materialQuery($type, $pindex = 1, $psize = 20, &$total = 0);

    /**
     * 创建永久素材
     *
     * @param $packet array 素材内容
     * @return string|error
     */
    public function materialCreateFix($packet);

    /**
     * 获取用户统计情况
     *
     * @param $start
     */
    public function summaryUserGet($start);

    /**
     * 获取图文统计情况
     *
     * @param $date
     */
    public function summaryArticleGet($date);

    /**
     * @param string $code
     * @return \Error|array
     */
    public function authUser(string $code);

    /**
     * @param string $type
     * @param string $host
     * @return mixed
     */
    public function authBuildUrl($type = 'auto', $host = '');

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
     */
    public function payBusiness($openid, array $trade, array $config = null);

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
    public function payRedpacket($openid, array $packet, array $config = null);

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
     * @return array | error
     */
    public function payCreateOrder($openid, array $trade, array $config = null);

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
    public function payConfirmOrder($input, array $config = null);

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
    public function payRefund(array $order, array $config = null);

    /**
     * 创建卡券调用参数
     *
     * @param array $filter
     */
    public function cardDataCreate(array $filter = null, $type = 'choose');

    /**
     * 查询特定用户是拥有(特定)卡券
     *
     * @param $openid
     */
    public function cardQueryUser($openid, $card = '');

    /**
     * 查询特定优惠券详情
     *
     * @param $card
     */
    public function cardQueryInfo($card);

    /**
     * 查询优惠券编码信息
     */
    public function cardQueryCode($code, $isEncrypt = false);

    /**
     * 核销优惠券
     *
     * @param $code
     */
    public function cardConsume($code);

    /**
     * 生成短连接
     *
     * @param $url
     */
    public function shortUrl($url);
}
