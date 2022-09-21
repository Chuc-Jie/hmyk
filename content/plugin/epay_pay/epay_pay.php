<?php
/*
Plugin Name: 易支付
Version: 2.01
Plugin URL:
Description: 支持市面上大多数易支付
Author: 云商学院
Author URL: https://www.ysxue.net/
*/


use app\common\controller\Hm;

!defined('ROOT_PATH') && exit('access deined!');


function pay($order, $goods, $params = []) {
    $plugin_path = ROOT_PATH . "content/plugin/epay_pay/";

    $info = file_get_contents("{$plugin_path}epay_pay_setting.json");
    $info = json_decode($info, true);

    if(isset($_SERVER['REQUEST_SCHEME'])){
        $host = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/';
    }else{
        $host = 'http://' . $_SERVER['HTTP_HOST'] . '/';
    }

    $data = [
        "pid"         => $info['appid'],//商户ID
        "type"       => $params['pay_type'],//支付方式
        "out_trade_no"     => $order['order_no'], //商户订单号
        "notify_url" => $params['notify_url'],//异步通知地址
        "return_url" => $params['return_url'],//同步通知地址
        "name" => $goods['name'], //商品名称
        "money"      => $order['money'],//订单金额
    ];


    $data['sign'] = getSign($data, $info['secret_key']);
    $data['sign_type'] = 'MD5';
    $gateway_url = rtrim($info['gateway_url'], '/') . '/submit.php';

    return [
        'code' => 200,
        'data' => $data,
        'gateway_url' => $gateway_url,
        'mode' => 'form'
    ];

}

/**
 * 验签
 */
function checkSign($data = null){
    $plugin_path = ROOT_PATH . "content/plugin/epay_pay/";
    $info = file_get_contents("{$plugin_path}epay_pay_setting.json");
    $info = json_decode($info, true);
    $data = $data == null ? Hm::getParams('get') : $data;

    $sign = $data['sign'];
    $server_sign = getSign($data, $info['secret_key']);
    if($server_sign == $sign) return $data['out_trade_no'];
    return false;
}


/**
 * 生成签名结果
 * return 签名结果字符串
 */
function getSign($data, $secret_key) {
    foreach($data as $key => $val){
        if($key == "sign" || $key == "sign_type" || $key == "pay_plugin"){
            unset($data[$key]);
        }
    }
    ksort($data);
    reset($data);
    //把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
    $prestr  = "";


    // print_r($data);die;

    foreach ($data as $key=>$val) {
        $prestr.=$key."=".$val."&";
    }
    //去掉最后一个&字符
    $prestr = substr($prestr, 0, -1);

    $sign = md5($prestr . $secret_key);

    return $sign;
}
