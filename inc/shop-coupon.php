<?php
/**
 * Xingxy 商城优惠码集成
 *
 * 将 zibpay 优惠码系统集成到商城购买流程中。
 * 商城的 shop_submit_order 流程独立于 zibpay 的通用订单流程，
 * 本模块通过 WordPress hooks 注入优惠码验证和折扣逻辑。
 *
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ====================================================================
 * 1. AJAX 端点：验证优惠码（前端"检查优惠码"按钮调用）
 * ====================================================================
 */
function xingxy_ajax_shop_check_coupon()
{
    $coupon_code = !empty($_POST['coupon']) ? sanitize_text_field($_POST['coupon']) : '';
    $product_ids = !empty($_POST['product_ids']) ? array_map('intval', (array) $_POST['product_ids']) : [];
    $order_price = !empty($_POST['order_price']) ? floatval($_POST['order_price']) : 0;
    $order_type  = function_exists('zib_shop_get_order_type') ? zib_shop_get_order_type() : 10;

    if (empty($coupon_code)) {
        wp_send_json_error(['msg' => '请输入优惠码']);
    }

    if ($order_price <= 0) {
        wp_send_json_error(['msg' => '订单金额异常']);
    }

    // 商城优惠码不依赖 zibpay 的全局开关 coupon_post_s
    // 直接验证优惠码本身是否有效即可

    // 验证优惠码是否可用
    if (!function_exists('zibpay_is_coupon_available')) {
        wp_send_json_error(['msg' => '优惠码功能不可用']);
    }

    $coupon_data = zibpay_is_coupon_available($coupon_code, $order_type, 0);

    if (!empty($coupon_data['error'])) {
        wp_send_json_error(['msg' => $coupon_data['msg']]);
    }

    // 计算折扣后价格
    if (!function_exists('zibpay_get_coupon_order_price')) {
        wp_send_json_error(['msg' => '优惠码计算功能不可用']);
    }

    $discounted_price = zibpay_get_coupon_order_price($order_price, $coupon_data);
    $discounted_price = max($discounted_price, 0);
    $discount_amount  = $order_price - $discounted_price;

    // 构建返回数据
    $result = [
        'coupon_code'      => $coupon_code,
        'discount_type'    => $coupon_data['discount']['type'],  // 'multiply' 或 'subtract'
        'discount_val'     => $coupon_data['discount']['val'],
        'discount_amount'  => zib_floatval_round($discount_amount),
        'discounted_price' => zib_floatval_round($discounted_price),
        'original_price'   => $order_price,
        'msg'              => '优惠码可用',
    ];

    // 构建折扣描述文本
    if ($coupon_data['discount']['type'] === 'multiply') {
        $discount_percent = round((1 - $coupon_data['discount']['val']) * 100);
        $result['desc']   = '优惠 ' . $discount_percent . '%，减 ¥' . $result['discount_amount'];
    } else {
        $result['desc'] = '立减 ¥' . $result['discount_amount'];
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_xingxy_shop_check_coupon', 'xingxy_ajax_shop_check_coupon');
add_action('wp_ajax_nopriv_xingxy_shop_check_coupon', 'xingxy_ajax_shop_check_coupon');

/**
 * ====================================================================
 * 2. 拦截 shop_submit_order：在订单创建前注入优惠码折扣
 * ====================================================================
 *
 * 策略：移除原 handler，注册我们的包装函数。
 * 包装函数中：
 *   - 如果没有优惠码，直接调用原函数
 *   - 如果有优惠码，验证后修改价格数据，再调用原函数
 */
function xingxy_shop_coupon_hijack_submit_order()
{
    // 移除原始 handler（优先级10是 WordPress 默认）
    remove_action('wp_ajax_shop_submit_order', 'zib_shop_ajax_submit_order');
    remove_action('wp_ajax_nopriv_shop_submit_order', 'zib_shop_ajax_submit_order');

    // 注册我们的包装函数
    add_action('wp_ajax_shop_submit_order', 'xingxy_shop_coupon_submit_order_wrapper');
    add_action('wp_ajax_nopriv_shop_submit_order', 'xingxy_shop_coupon_submit_order_wrapper');
}
// 在 after_setup_theme 之后执行，确保原 handler 已注册
add_action('init', 'xingxy_shop_coupon_hijack_submit_order', 20);

/**
 * 包装函数：处理优惠码后调用原始订单提交
 */
function xingxy_shop_coupon_submit_order_wrapper()
{
    $coupon_code = !empty($_POST['coupon']) ? sanitize_text_field($_POST['coupon']) : '';

    // 没有优惠码，直接走原流程
    if (empty($coupon_code)) {
        zib_shop_ajax_submit_order();
        return;
    }

    // ---- 有优惠码，开始处理 ----

    $products = $_POST['products'] ?? [];
    if (empty($products)) {
        zib_send_json_error(['code' => 'products_error', 'msg' => '未选择商品']);
    }

    // 获取确认数据（与原函数相同）
    $confirm_data = zib_shop_get_confirm_data($products);
    if (!$confirm_data) {
        zib_send_json_error(['code' => 'products_error', 'msg' => '未选择商品，或商品不存在']);
    }

    $is_points     = $confirm_data['pay_modo'] === 'points';
    $order_type    = zib_shop_get_order_type();
    $payment_price = $is_points
        ? (int) $confirm_data['total_data']['pay_points']
        : zib_floatval_round($confirm_data['total_data']['pay_price']);

    // 积分商品不支持优惠码
    if ($is_points) {
        zib_send_json_error(['code' => 'coupon_error', 'msg' => '积分商品不支持使用优惠码', 'type' => 'warning']);
    }

    // 商城优惠码不依赖 zibpay 全局开关，直接验证优惠码本身

    // 验证优惠码
    $coupon_data = zibpay_is_coupon_available($coupon_code, $order_type, 0);
    if (!empty($coupon_data['error'])) {
        zib_send_json_error(['code' => 'coupon_error', 'msg' => $coupon_data['msg'], 'type' => 'warning']);
    }

    // 计算折后价
    $discounted_price = zibpay_get_coupon_order_price($payment_price, $coupon_data);
    $discounted_price = max($discounted_price, 0);
    $coupon_discount  = zib_floatval_round($payment_price - $discounted_price);

    // 将优惠码信息存储到全局变量，供后续在订单创建后使用
    global $xingxy_shop_coupon_context;
    $xingxy_shop_coupon_context = [
        'coupon_code'      => $coupon_code,
        'coupon_data'      => $coupon_data,
        'coupon_discount'  => $coupon_discount,
        'original_price'   => $payment_price,
        'discounted_price' => zib_floatval_round($discounted_price),
    ];

    // 修改 $_POST 中的价格，使原函数的金额校验通过
    // 原函数中：$_post_price = $_POST['price'] ?? 0
    // 原函数中：$payment_price = $confirm_data['total_data']['pay_price']
    // 我们需要让两者都等于折后价
    //
    // $_POST['price'] 由前端传入，JS 端会传折后价
    // $payment_price 由 confirm_data 计算，不含优惠码折扣
    //
    // 解决方案：不调用原函数，而是复制其核心逻辑并注入优惠码处理
    xingxy_shop_coupon_execute_submit_order($confirm_data, $coupon_code, $coupon_data, $coupon_discount, $discounted_price);
}

/**
 * 执行带优惠码的订单提交（基于原 zib_shop_ajax_submit_order 逻辑改造）
 */
function xingxy_shop_coupon_execute_submit_order($confirm_data, $coupon_code, $coupon_data, $coupon_discount, $discounted_price)
{
    $user_id = get_current_user_id();
    $products = $_POST['products'] ?? [];

    // 登录判断
    if (!$user_id) {
        foreach ($confirm_data['product_data'] as $product_data) {
            if (!$product_data['guest_buy']) {
                zib_send_json_error(['code' => 'login_error', 'msg' => '请先登录']);
            }
        }
    }

    // 判断是否混合支付
    if ($confirm_data['is_mix']) {
        zib_send_json_error(['code' => 'mix_error', 'msg' => '积分商品和现金商品不能同时支付，请返回购物车重新选择']);
    }

    // 判断用户地址
    if ($confirm_data['shipping_has_express']) {
        if (empty($_POST['address_data']['name']) || empty($_POST['address_data']['phone']) || empty($_POST['address_data']['address'])) {
            zib_send_json_error(['code' => 'address_error', 'msg' => '请先设置收货地址']);
        }
    }

    // 判断邮箱
    if ($confirm_data['shipping_has_auto']) {
        if (empty($_POST['user_email'])) {
            zib_send_json_error(['code' => 'email_error', 'msg' => '请输入邮箱']);
        }
        if (!filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)) {
            zib_send_json_error(['code' => 'email_error', 'msg' => '邮箱格式错误']);
        }
    }

    // 商品参数判断
    if (!empty($confirm_data['error_data'])) {
        foreach ($confirm_data['error_data'] as $error_data) {
            $error_data['msg']  = $error_data['error_msg'] ?? '商品参数错误';
            $error_data['code'] = $error_data['error_type'] ?? 'product_error';
            zib_send_json_error($error_data);
        }
    }

    // 价格和支付方式
    $is_points      = $confirm_data['pay_modo'] === 'points';
    $payment_method = $is_points ? 'points' : ($_POST['payment_method'] ?? '');

    // 使用折后价作为支付金额
    $payment_price = zib_floatval_round($discounted_price);
    $_post_price   = $_POST['price'] ?? 0;
    $order_type    = zib_shop_get_order_type();

    // 金额校验：前端传入的必须等于折后价
    if (round((float) $_post_price, 2) !== round((float) $payment_price, 2)) {
        zib_send_json_error(['code' => 'price_error', 'msg' => '订单金额发生变化，请重新提交']);
    }

    if (!$is_points && (float) $payment_price > 0) {
        if (!$payment_method) {
            zib_send_json_error(['code' => 'payment_method_error', 'msg' => '请选择支付方式']);
        }
        $pay_methods = zib_shop_get_payment_methods();
        if (!isset($pay_methods[$payment_method])) {
            zib_send_json_error(['code' => 'payment_method_error', 'msg' => '支付方式错误']);
        }
    }

    // 金额为0的处理
    if ((float) $payment_price <= 0) {
        $payment_method = $is_points ? 'points' : 'balance';
        $payment_price  = 0;
    }

    // 创建支付记录
    $order_data     = [];
    $zibpay_payment = zibpay::add_payment([
        'method' => $payment_method,
        'price'  => $payment_price,
    ]);

    if (!$zibpay_payment) {
        zib_send_json_error(['code' => 'add_payment_error', 'msg' => '支付数据创建失败']);
    }

    // 计算优惠码在每个子订单中的分摊
    // 按比例分配优惠金额到各个子订单
    $original_total_price = zib_floatval_round($confirm_data['total_data']['pay_price']);
    $coupon_ratio         = $original_total_price > 0 ? $coupon_discount / $original_total_price : 0;

    $zibpay_payment_id = $zibpay_payment['id'];
    foreach ($confirm_data['item_data'] as $author_id => $product_data_item) {
        foreach ($product_data_item as $product_id => $opt_items) {
            foreach ($opt_items as $opt_key => $item_data_item) {
                // 验证必填项
                if ($item_data_item['user_required']) {
                    $user_required_error = [];
                    foreach ($item_data_item['user_required'] as $key => $user_required_item) {
                        if (!$user_required_item['value']) {
                            $user_required_error[] = $user_required_item['name'];
                        }
                        unset($item_data_item['user_required'][$key]['key']);
                        unset($item_data_item['user_required'][$key]['desc']);
                    }
                    if ($user_required_error) {
                        zib_send_json_error(['code' => 'user_required_error', 'msg' => '请填写' . implode(',', $user_required_error)]);
                    }
                }

                $__order_price = zib_shop_format_price($item_data_item['prices']['total_price'], $is_points);
                $__pay_price   = zib_shop_format_price($item_data_item['prices']['pay_price'], $is_points);

                // 按比例计算该子订单的优惠码折扣
                $__item_coupon_discount = zib_floatval_round($__pay_price * $coupon_ratio);
                $__pay_price_after_coupon = zib_floatval_round(max($__pay_price - $__item_coupon_discount, 0));

                $__pay_detail                   = [];
                $__pay_detail['payment_method'] = $payment_method;
                $__pay_detail[$payment_method]  = $__pay_price_after_coupon;

                if (!empty($item_data_item['prices']['total_discount'])) {
                    $__pay_detail['discount'] = (string) zib_shop_format_price($item_data_item['prices']['total_discount'], $is_points);
                }

                // 记录优惠码折扣
                $__pay_detail['coupon'] = $__item_coupon_discount;

                if ($is_points) {
                    $__pay_detail['points'] = $__pay_price_after_coupon;
                }

                $__mate_order_data = $item_data_item;
                foreach (['stock_all', 'limit_buy'] as $key) {
                    if (isset($__mate_order_data[$key])) {
                        unset($__mate_order_data[$key]);
                    }
                }

                // 发货信息
                if ($item_data_item['shipping_type'] === 'auto') {
                    $__mate_order_data['consignee'] = [
                        'email' => $_POST['user_email'],
                    ];
                } elseif ($item_data_item['shipping_type'] === 'express') {
                    $__mate_order_data['consignee'] = [
                        'address_data' => $_POST['address_data'],
                    ];
                }

                // 推广返佣（基于折后价计算）
                $referrer_id  = '';
                $rebate_price = '';
                if (!$is_points) {
                    $rebate_data  = zib_shop_get_order_rebate_data($item_data_item['product_id'], $user_id, $__pay_price_after_coupon);
                    $referrer_id  = $rebate_data['referrer_id'];
                    $rebate_price = $rebate_data['rebate_price'];
                }

                // 优惠码数据写入订单 meta
                $__meta = [
                    'order_data' => $__mate_order_data,
                    'pay_modo'   => $is_points ? 'points' : 'price',
                ];

                // 在 meta 中记录优惠码信息
                $__meta['coupon_data'] = [
                    'coupon_id'       => $coupon_data['id'],
                    'coupon_code'     => $coupon_code,
                    'discount'        => $coupon_data['discount'],
                    'coupon_discount' => $__item_coupon_discount,
                ];

                $__order_data = [
                    'count'        => $__mate_order_data['count'] ?? 1,
                    'post_id'      => $item_data_item['product_id'],
                    'post_author'  => $author_id,
                    'user_id'      => $user_id,
                    'product_id'   => $item_data_item['options_active_str'],
                    'order_type'   => $order_type,
                    'order_price'  => $__order_price,
                    'pay_price'    => $__pay_price_after_coupon, // 使用折后价
                    'payment_id'   => $zibpay_payment_id,
                    'referrer_id'  => $referrer_id,
                    'rebate_price' => $rebate_price,
                    'pay_detail'   => $__pay_detail,
                    'meta'         => $__meta,
                ];

                $add_order_data = zibpay::add_order($__order_data);
                if (is_wp_error($add_order_data)) {
                    zib_send_json_error(['code' => $add_order_data->get_error_code(), 'msg' => $add_order_data->get_error_message()]);
                }

                if ($add_order_data) {
                    $order_data[] = [
                        'order_id'   => $add_order_data['id'],
                        'payment_id' => $zibpay_payment_id,
                        'order_num'  => $add_order_data['order_num'],
                        'pay_price'  => $add_order_data['pay_price'],
                        'product_id' => $item_data_item['product_id'],
                        'opt_key'    => $item_data_item['options_active_str'],
                    ];
                } else {
                    zib_send_json_error(['code' => 'add_order_error', 'msg' => '订单创建失败']);
                }
            }
        }
    }

    // 标记优惠码使用次数（直接操作 ZibCardPass 表）
    // 注意：不能调用 zibpay_payment_order_use_coupon，它接收的是 order 对象参数
    if (!empty($coupon_data['id']) && class_exists('ZibCardPass')) {
        $coupon_meta = maybe_unserialize($coupon_data['meta']);
        $coupon_meta['used_count'] = ($coupon_meta['used_count'] ?? 0) + 1;

        // 记录使用此优惠码的订单号
        if (!isset($coupon_meta['used_order_num'])) {
            $coupon_meta['used_order_num'] = [];
        }
        foreach ($order_data as $od) {
            if (!empty($od['order_num'])) {
                $coupon_meta['used_order_num'][] = $od['order_num'];
            }
        }

        $update_data = [
            'id'   => $coupon_data['id'],
            'meta' => $coupon_meta,
        ];

        // 判断是否达到使用次数上限
        $reuse_limit = $coupon_data['reuse'] ?? 0;
        if ($reuse_limit && $coupon_meta['used_count'] >= $reuse_limit) {
            $update_data['status'] = 'used';
        }

        // 单次使用的优惠码，直接记录订单号并标记为已使用
        if ($reuse_limit == 1) {
            $update_data['status'] = 'used';
            if (!empty($order_data[0]['order_num'])) {
                $update_data['order_num'] = $order_data[0]['order_num'];
            }
        }

        ZibCardPass::update($update_data);
    }

    // 购物车移除
    if ($confirm_data['config']['is_cart']) {
        zib_shop_cart_remove_multi($order_data, $user_id);
    }

    $send_data = [
        'order_data'   => $order_data,
        'payment_data' => $zibpay_payment,
    ];

    zib_send_json_success($send_data);
}
