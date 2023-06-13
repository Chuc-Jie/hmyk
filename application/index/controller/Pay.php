<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use hehe\QRcode;
use hehe\Trade;
use Monolog\Handler\RotatingFileHandler;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\Root;
use think\Db;


class Pay extends Frontend {

    protected $layout = 'default';

    protected $noNeedRight = ['*'];
    protected $noNeedLogin = ['*'];

    public function _initialize() {
        parent::_initialize(); // TODO: Change the autogenerated stub

        if (!$this->request->isPjax()) {
            //            $this->pjaxLayout();
            $this->view->engine->layout('default/layout/layout_' . $this->layout);
        }

    }

    /**
     * 购买商品
     */
    public function goods() {
        $params = $this->request->param();
        $password = empty($params['password']) ? null : $params['password'];
        if(!$this->user){
            if(in_array('mobile', $this->options['buy_input']) && empty($params['mobile'])) return json(['code' => 400, 'msg' => '游客下单需输入手机号码']);
            if(in_array('email', $this->options['buy_input']) && empty($params['email'])) return json(['code' => 400, 'msg' => '游客下单需输入电子邮件']);
            if(in_array('password', $this->options['buy_input']) && empty($password)) return json(['code' => 400, 'msg' => '游客下单需输入查单密码']);
        }



        db::startTrans();
        try {
            $params['num'] = empty($params['num']) ? 1 : intval($params['num']);
            $params['num'] = $params['num'] < 1 ? 1 : $params['num'];
            $model_goods = new \app\index\model\Goods();
            $goods = $model_goods->with(['sku'])->where(['id' => $params['goods_id']])->find()->toArray();
            $ip = request()->ip();
            if($goods['quota'] > 0){
                if($params['num'] > $goods['quota']) exception("该商品每日限购{$goods['quota']}个");
                $ip_order_num = db::name('goods_order')->where(['ip' => $ip, 'goods_id' => $goods['id']])->whereTime('pay_time', 'today')->whereNotNull('pay_time')->count();
                if($ip_order_num + $params['num'] > $goods['quota']) exception("该商品每日限购{$goods['quota']}个，您今日已购买过{$ip_order_num}个");
            }

            $agency = $this->userAgency();
            $goods = $this->goodsDetail($goods, $agency);
            /**
             * 写入订单前准备
             *  1，验证附加选项
             *  2，验证商品规格
             *  3，验证商品库存
             *  4，验证优惠券
             */
            // 1，验证附加选项
            foreach ($goods['attach'] as $val) {
                foreach ($params['attach'] as $v) {
                    if ($val['title'] == $v['title'] && !empty($val['checked']) && empty($v['value'])) {
                        exception('请输入' . $val['title']);
                    }
                }
            }

            // 2，验证商品规格
            $params['sku_id'] = empty($params['sku_id']) ? null : $params['sku_id'];
            $goodsMoney = $this->getGoodsMoney($goods, $agency, ['sku_id' => $params['sku_id']]);
            $goodsCost = $this->getGoodsCost($goods, ['sku_id' => $params['sku_id']]);
            //			var_dump($goodsMoney); die;
            if ($goodsMoney == -1) {
                exception('商品规格错误，请刷新页面后重试');
            }

            // 3，验证商品库存
            if ($goods['is_sku'] == 0) {
                $stockNum = db::name('sku')->where(['id' => $goods['sku'][0]['id']])->value('stock');
            }
            if ($goods['is_sku'] == 1) {
                $stockNum = db::name('sku')->where(['id' => $params['sku_id']])->value('stock');
            }

            if ($params['num'] > $stockNum) {
                exception('库存不足');
            }
            // 4，验证优惠券

            // 5，处理批发优惠
            // 按照weight字段进行升序排序

            if($goods['wholesale']){
                $wholesale = $goods['wholesale'];
                $weights = array_column($goods['wholesale'], "number");
                array_multisort($weights, SORT_DESC, $wholesale);
                foreach($wholesale as $val){
                    if($params['num'] >= $val['number']){
                        $goodsMoney -= $val['offer'];
                        break;
                    }
                }
            }
            // 6，验证起拍数量
            if($params['num'] < $goods['start_number']) {
                exception("该商品最低购买数量：{$goods['start_number']}");
            }


            // 写入订单
            $orderMoney = $params['num'] * $goodsMoney;
            $out_trade_no = Trade::generateTradeNo();
            $order_insert = [
                'ip' => $ip,
                'out_trade_no' => $out_trade_no,
                'goods_id' => $params['goods_id'],
                'user_id' => empty($this->user['id']) ? null : $this->user['id'],
                'goods_name' => $goods['name'],
                'goods_cover' => $goods['cover'],
                'goods_type' => $goods['type'],
                'create_time' => $this->timestamp,
                'goods_money' => $goodsMoney,
                'goods_cost' => $goodsCost * $params['num'],
                'goods_num' => $params['num'],
                'money' => $orderMoney,
                'attach' => empty($params['attach']) ? null : json_encode($params['attach']),
                'pay_type' => empty($params['pay_type']) || $orderMoney == 0 ? null : $params['pay_type'],
                'sku_id' => empty($params['sku_id']) ? $goods['sku'][0]['id'] : $params['sku_id'],
                'mobile' => empty($params['mobile']) ? null : trim($params['mobile']),
                'email' => empty($params['email']) ? null : trim($params['email']),
                'password' => $password,
            ];
            $sku = db::name('sku')->where(['id' => $params['sku_id']])->find();
            if ($goods['is_sku']) {
                $order_insert['sku_name'] = empty($goods['sku_name']) ? '类型' : $goods['sku_name'];
                $order_insert['sku'] = $sku['sku'];
            }
            //            print_r($order_insert);die;
            db::name('goods_order')->insert($order_insert); //写入订单
            if ($orderMoney == 0) {
                $result = $this->notifyGoodsSuccess($goods, $out_trade_no);
            } else {
                if(empty($params['pay_type'])){
                    exception('请选择支付方式');
                }
                if ($params['pay_type'] == 'balance') {
                    if ($orderMoney > $this->user['money']) {
                        exception('余额不够用啦，先去充值吧！');
                    }
                    db::name('user')->where(['id' => $this->user['id']])->setDec('money', $orderMoney);
                    $result = $this->notifyGoodsSuccess($goods, $out_trade_no);
                } else {
                    // 发起支付
                    $payPlugin = selectPayPlugin($this->plugin, $params['pay_type']);

                    include_once ROOT_PATH . 'content/' . $payPlugin['english_name'] . '/' . $payPlugin['english_name'] . '.php';
                    doAction('goods_pay_before', $goods, $order_insert);
                    //                    echo $goods['name'];die;
                    $result = pay([
                        'subject' => trim($goods['name']),
                        'out_trade_no' => $out_trade_no,
                        'money' => $orderMoney,
                        'hm_type' => 'goods',
                        'pay_type' => $params['pay_type'],
                        'client_ip' => request()->ip(),
                    ], $payPlugin['info']);
                }
            }
            db::commit();
        } catch (\Exception $e) {
            db::rollback();
            return json(['code' => 400, 'msg' => $e->getMessage()]);
//            return json(['code' => 400, 'msg' => $e->getMessage() . ' - ' . $e->getLine()]);
        }

        if($orderMoney == 0){
            if ($result) {
                return json(['code' => 201, 'msg' => '购买成功', 'data' => [
                    'url' => $this->user ? url('/order') : url('/index/notify/ret') . '?hm_type=goods&out_trade_no=' . $out_trade_no
                ]]);
            } else {
                return json(['code' => 400, 'msg' => '购买失败']);
            }
        }
        if($orderMoney > 0){
            if ($params['pay_type'] == 'balance') {
                if ($result) {
                    return json(['code' => 201, 'msg' => '购买成功', 'data' => [
                        'url' => url('/index/notify/ret') . '?hm_type=goods&out_trade_no=' . $out_trade_no
                    ]]);
                } else {
                    return json(['code' => 400, 'msg' => '购买失败']);
                }
            }else{
                return json($result);
            }
        }



        print_r($params);
        die;
    }

    /**
     * 购买商品的支付回调
     */
    public function notifyGoods() {

    }

    public function qrCode(){
        $qr_code = urldecode($this->request->param('qr_code'));
        QRcode::png(urldecode($qr_code),false, 'L', 7, 2);
        die;
    }

    /**
     * 执行购买商品的回调操作
     * 1，写入发货表，更新库存表
     * 2，更新商品库存字段
     * 3，更新商品销量字段
     * 4，更新订单状态
     * 5，返佣给上级
     */
    protected function notifyGoodsSuccess($goods, $out_trade_no) {
        $order = db::name('goods_order')->where(['out_trade_no' => $out_trade_no])->find();
        if($this->user) $order['email'] = $this->user['email'];
        db::name('goods_order')->where(['id' => $order['id']])->update(['pay_time' => $this->timestamp]);
        if ($goods['type'] == 'alone') { //更新库存表并写入发货表
            $stock = db::name('stock')->field('id, content')->where(['sku_id' => $order['sku_id']])->whereNull('sale_time')->limit($order['goods_num'])->select();
            $stock_ids = array_column($stock, 'id');
            db::name('stock')->whereIn('id', $stock_ids)->update(['sale_time' => $this->timestamp]); //更新库存表
            $deliver = [];
            foreach ($stock as $val) {
                $deliver[] = [
                    'content' => $val['content'],
                    'order_id' => $order['id'],
                    'create_time' => $this->timestamp
                ];
            }
            db::name('deliver')->insertAll($deliver);
            doAction('send_goods', $order, $deliver);
        }
        if ($goods['type'] == 'fixed') { //更新库存表并写入发货表
            $stock = db::name('stock')->where(['sku_id' => $order['sku_id']])->limit($order['goods_num'])->find();
            //            print_r($stock);die;
            $deliver = [];
            for ($i = 0; $i < $order['goods_num']; $i++) {
                $deliver[] = [
                    'content' => $stock['content'],
                    'create_time' => $this->timestamp,
                    'order_id' => $order['id']
                ];
            }
            db::name('deliver')->insertAll($deliver); //写入发货表
            db::name('stock')->where(['id' => $stock['id']])->setDec('num', $order['goods_num']); //更新库存表
            doAction('send_goods', $order, $deliver);
        }
        if ($goods['type'] == 'invented') {
            if ($goods['is_sku'] == 0) {
                $stock = db::name('stock')->where(['sku_id' => $goods['sku'][0]['id']])->find();
            }
            if ($goods['is_sku'] == 1) {
                $stock = db::name('stock')->where(['sku_id' => $order['sku_id']])->find();
            }
            db::name('stock')->where(['id' => $stock['id']])->setDec('num', $order['goods_num']); //更新库存表
        }
        db::name('goods')->where(['id' => $goods['id']])->setDec('stock', $order['goods_num']); //更新商品库存字段
        db::name('goods')->where(['id' => $goods['id']])->setInc('sales', $order['goods_num']); //更新商品销量字段
        if($goods['invented_sales']){
            db::name('goods')->where(['id' => $goods['id']])->setInc('invented_sales', $order['goods_num']); //更新商品虚拟销量字段
        }
        db::name('goods_order')->where(['id' => $order['id']])->update(['pay_time' => $this->timestamp]); //更新订单状态
        db::name('sku')->where(['id' => $order['sku_id']])->setDec('stock', $order['goods_num']); //更新库存表

        // 计算该笔订单的利润
        $translate = $order['money'] - ($order['goods_cost'] * $order['goods_num']);
        if ($translate <= 0) {
            return true;
        }

        /**
         * 返佣给子站长
         */
        if (!$this->is_main) { //如果是子站下单
            // 获取返佣比例
            $rebate = db::name('merchant_grade')->where(['id' => $this->merchant['grade_id']])->value('rebate');
            // 计算佣金
            $commission = $translate * ($rebate / 100);
            // 记录分站长账单
            $merchant_user = db::name('user')->where(['id' => $this->merchant['user_id']])->find();
            $bill_insert = [
                'create_time' => $this->timestamp,
                'user_id' => $this->merchant['user_id'],
                'before' => $merchant_user['money'],
                'after' => $merchant_user['money'] + $commission,
                'value' => $commission,
                'content' => '子站用户购买商品返佣'
            ];
            db::name('bill')->insert($bill_insert);
            db::name('user')->where(['id' => $this->merchant['user_id']])->setInc('money', $commission);
        }

        /**
         * 返佣给上级
         * 1，获取返佣比例
         * 2，返佣给上级
         * 3，记录余额账单
         */
        if(empty($order['user_id'])) {
            return true;
        }
//        print_r($order);die;
        $user = db::name('user')->where(['id' => $order['user_id']])->find();
        db::name('user')->where(['id' => $order['user_id']])->setInc('consume', $order['money']);
        //给上级返佣、记录余额账单
        $bill_insert = [
            'create_time' => $this->timestamp,
        ];
        if ($user['p1'] > 0 && $this->options['rebeat_1'] > 0) {
            $commission = $translate * ($this->options['rebeat_1'] / 100);
            $puser = db::name('user')->where(['id' => $user['p1']])->find();
            $bill_insert['user_id'] = $puser['id'];
            $bill_insert['before'] = $puser['money'];
            $bill_insert['after'] = $puser['money'] + $commission;
            $bill_insert['value'] = $commission;
            $bill_insert['content'] = '一级推广好友购买商品返佣';
            db::name('bill')->insert($bill_insert);
            db::name('user')->where(['id' => $user['p1']])->setInc('money', $commission);
        }
        if ($user['p2'] > 0 && $this->options['rebeat_2'] > 0) {
            $commission = $translate * ($this->options['rebeat_2'] / 100); //佣金
            $puser = db::name('user')->where(['id' => $user['p2']])->find();
            $bill_insert['user_id'] = $puser['id'];
            $bill_insert['before'] = $puser['money'];
            $bill_insert['after'] = $puser['money'] + $commission;
            $bill_insert['value'] = $commission;
            $bill_insert['content'] = '二级推广好友购买商品返佣';
            db::name('bill')->insert($bill_insert);
            db::name('user')->where(['id' => $user['p2']])->setInc('money', $commission);
        }
        if ($user['p3'] > 0 && $this->options['rebeat_3'] > 0) {
            $commission = $translate * ($this->options['rebeat_3'] / 100); //佣金
            $puser = db::name('user')->where(['id' => $user['p3']])->find();
            $bill_insert['user_id'] = $puser['id'];
            $bill_insert['before'] = $puser['money'];
            $bill_insert['after'] = $puser['money'] + $commission;
            $bill_insert['value'] = $commission;
            $bill_insert['content'] = '三级推广好友购买商品返佣';
            db::name('bill')->insert($bill_insert);
            db::name('user')->where(['id' => $user['p3']])->setInc('money', $commission);
        }

        return true;
    }


}
