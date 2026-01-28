<?php
/**
 * 邮件通知修复模块
 * 
 * 修复 zibpay-msg.php 中的致命错误问题，确保管理员新订单邮件能正常发送
 * 
 * 问题根因：
 * zib_get_wechat_template_id() 函数需要引用传参（&$type），
 * 但 zibpay-msg.php 中直接传入字面量字符串导致 PHP 致命错误，
 * 阻断了后续所有代码执行（包括管理员邮件发送）。
 * 
 * 解决方案：
 * 1. 在原函数执行前移除原有 Hook
 * 2. 注册修复后的函数（带错误保护）
 * 
 * @package Xingxy
 * @subpackage EmailFix
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 移除原有的用户邮件函数，替换为修复版本
 */
add_action('init', 'xingxy_fix_payment_email_hooks', 1);
function xingxy_fix_payment_email_hooks() {
    // 移除原有的用户邮件 Hook
    remove_action('payment_order_success', 'zibpay_mail_payment_order');
    
    // 注册修复后的用户邮件函数（优先级 10）
    add_action('payment_order_success', 'xingxy_fixed_mail_payment_order', 10);
}

/**
 * 修复后的用户邮件发送函数
 * 
 * 与原函数逻辑相同，但添加了 try-catch 保护微信模板消息调用，
 * 并移除了可能导致函数提前退出的 return 语句
 * 
 * @param object $values 订单数据
 */
function xingxy_fixed_mail_payment_order($values) {
    // 根据订单号查询订单
    $pay_order = (array) $values;
    $user_id   = $pay_order['user_id'];

    $udata = get_userdata($user_id);
    if (!$user_id || !$udata) {
        return;
    }

    // 积分不发
    if ($pay_order['pay_type'] === 'points') {
        return;
    }

    $user_name = $udata->display_name;

    if ($pay_order['pay_type'] === 'points') {
        $pay_price = zibpay_get_order_pay_points($pay_order) . '积分';
    } else {
        $pay_price = zibpay_get_order_effective_amount($pay_order);
        $pay_price = $pay_price ? '￥' . $pay_price : '';
    }
    $pay_price  = $pay_price ? '-金额：' . $pay_price : '';
    $pay_time   = $pay_order['pay_time'];
    $blog_name  = get_bloginfo('name');
    $_link      = zib_get_user_center_url('order');
    $order_name = zibpay_get_pay_type_name($pay_order['order_type']);
    $down_link  = '';

    $m_title = '订单支付成功' . $pay_price . '，订单号[' . $pay_order['order_num'] . ']';
    $title   = '[' . $blog_name . '] ' . $m_title;

    $message = '您好！ ' . $user_name . '<br>';
    $message .= '您在【' . $blog_name . '】购买的商品已支付成功' . '<br>';
    $post_title = '';
    if ($pay_order['post_id']) {
        $post = get_post($pay_order['post_id']);
        if (isset($post->post_title)) {
            $post_title = zib_str_cut($post->post_title, 0, 20, '...');
        }
    }
    $message .= '类型：' . $order_name . '<br>';
    $message .= $post_title ? '商品：<a target="_blank" href="' . get_permalink($post) . '">' . $post_title . '</a>' . '<br>' : '';
    $message .= '订单号：' . $pay_order['order_num'] . '<br>';
    $message .= '付款明细：' . zibpay_get_order_pay_detail_lists($pay_order) . '<br>';
    $message .= '付款时间：' . $pay_time . '<br>';
    $message .= '<br>';
    $message .= '您可以点击下方按钮查看订单详情' . '<br>';
    $message .= '<a class="but c-blue" target="_blank" style="margin-top: 20px" href="' . esc_url($_link) . '">查看订单</a>' . '<br>';

    $msg_arge = array(
        'send_user'    => 'admin',
        'receive_user' => $user_id,
        'type'         => 'pay',
        'title'        => $m_title,
        'content'      => $message,
        'meta'         => '',
        'other'        => '',
    );

    // 创建新消息
    if (_pz('message_s', true)) {
        ZibMsg::add($msg_arge);
    }

    // 发送微信模板消息（带错误保护）
    try {
        $type = 'payment_order'; // 使用变量而非字面量，解决引用传参问题
        $wechat_template_id = @zib_get_wechat_template_id($type);
    } catch (Exception $e) {
        $wechat_template_id = false;
    } catch (Error $e) {
        $wechat_template_id = false;
    }
    
    if ($wechat_template_id) {
        $remark    = '您可以登录网站后在用户中心查看订单详细信息';
        $send_data = array(
            'first'    => array(
                'value' => '[' . $blog_name . '] 订单支付成功！',
            ),
            'keyword1' => array(
                'value' => $order_name . ($post_title ? '-' . $post_title : ''),
            ),
            'keyword2' => array(
                'value' => implode("\n", zibpay_get_order_pay_detail_text_args($pay_order)),
            ),
            'keyword3' => array(
                'value' => $pay_order['pay_time'],
            ),
            'remark'   => array(
                'value' => $remark,
            ),
        );
        $send_url = $_link;
        // 发送消息
        zib_send_wechat_template_msg($user_id, $wechat_template_id, $send_data, $send_url);
    }

    // 发送用户邮件
    if (_pz('email_payment_order', true)) {
        $user_email = !empty($udata->user_email) ? $udata->user_email : '';
        // 如果邮箱有效则发送（不再使用 return 中断函数）
        if ($user_email && !stristr($user_email, '@no')) {
            @wp_mail($user_email, $title, $message);
        }
    }
}
