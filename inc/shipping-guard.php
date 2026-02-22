<?php
/**
 * 星盟：发货拦截模块（方案B）
 * 
 * 劫持 Zibll 自动发货链路，在卡密库存不足时执行部分发货 + 补发通知。
 * 
 * 核心原理：
 *   通过 remove_action / add_action 替换 payment_order_success 的回调函数，
 *   在自动发货前校验卡密库存 vs 购买数量，三种情况分流处理。
 * 
 * @package Xingxy
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 劫持 Zibll 的 payment_order_success 回调
 * 
 * 必须在 Zibll 注册之后执行（使用 after_setup_theme 确保父主题先加载完毕）
 */
add_action('after_setup_theme', function () {
    // 摘掉原始回调
    remove_action('payment_order_success', 'zib_shop_order_payment_success', 10);
    // 挂载增强版回调
    add_action('payment_order_success', 'xingxy_order_payment_success_guard', 10, 2);
}, 20); // 优先级 20，确保在 Zibll 的 add_action 之后执行

/**
 * 增强版支付成功回调
 * 
 * 复制原始 zib_shop_order_payment_success 的逻辑，
 * 仅在 shipping_type === 'auto' && auto_delivery.type === 'card_pass' 时
 * 替换为带库存校验的增强发货逻辑。
 */
function xingxy_order_payment_success_guard($order)
{
    $order = zibpay::order_data_map($order);
    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    // 更新发货状态为待发货
    zib_shop_update_order_shipping_status($order['id'], 0);

    // 准备发货
    $shipping_type = zib_shop_get_product_config($order['post_id'], 'shipping_type');
    if ($shipping_type === 'auto') {
        // 获取自动发货配置
        $auto_delivery = zib_shop_get_product_config($order['post_id'], 'auto_delivery');
        $delivery_type = $auto_delivery['type'] ?? '';

        // 仅对卡密发货类型进行拦截
        if ($delivery_type === 'card_pass') {
            xingxy_auto_shipping_guard($order, $auto_delivery);
        } else {
            // 固定内容、邀请码等其他类型走原始逻辑
            zib_shop_auto_shipping($order);
        }
    } else {
        // 手动发货：通知商家
        zib_shop_notify_shipping($order);
    }

    // 更新商品销量
    zib_shop_update_product_sales_volume($order['post_id'], $order['count']);
}

/**
 * 卡密发货拦截核心逻辑
 * 
 * 在执行自动发货前，先查询可用卡密数量并与购买数量对比。
 * 
 * @param array $order           订单数据
 * @param array $auto_delivery   自动发货配置
 */
function xingxy_auto_shipping_guard($order, $auto_delivery)
{
    $order_meta_data = zibpay::get_meta($order['id'], 'order_data');
    $count           = $order_meta_data['count'] ?? 1;
    $card_pass_key   = $auto_delivery['card_pass_key'] ?? '';

    if (!$card_pass_key) {
        // 未配置卡密备注，走原始失败逻辑
        zib_shop_auto_delivery_fail_to_user($order, $order_meta_data);
        zib_shop_notify_shipping($order, $order_meta_data);
        return;
    }

    // 查询可用卡密数量
    $available_count = xingxy_get_available_card_count($card_pass_key);

    if ($available_count >= $count) {
        // 情况一：库存充足 → 走原始自动发货（不干预）
        zib_shop_auto_shipping($order);
        return;
    }

    if ($available_count <= 0) {
        // 情况三：完全无货 → 走原始失败逻辑
        zib_shop_auto_delivery_fail_to_user($order, $order_meta_data);
        zib_shop_notify_shipping($order, $order_meta_data);
        return;
    }

    // 情况二：部分有货（0 < available < count）→ 执行部分发货
    xingxy_partial_shipping($order, $auto_delivery, $order_meta_data, $available_count, $count);
}

/**
 * 查询指定卡密备注下的可用卡密数量
 * 
 * @param string $card_pass_key  卡密备注（other 字段）
 * @return int                   可用数量
 */
function xingxy_get_available_card_count($card_pass_key)
{
    $where = array(
        'other'  => $card_pass_key,
        'status' => '0',
    );

    // ZibCardPass::get 返回匹配的记录数组
    // 我们用一个较大的 limit 来获取所有未使用的记录，然后计数
    $results = ZibCardPass::get($where, 'id', 0, 9999, 'ASC');

    if (!$results || !is_array($results)) {
        return 0;
    }

    return count($results);
}

/**
 * 部分发货处理
 * 
 * 1. 调用原始卡密取出逻辑（会取出 available 个）
 * 2. 在发货内容前追加醒目的部分发货提示
 * 3. 执行虚拟发货
 * 4. 记录 backlog 信息到 order_meta
 * 5. 通知卖家补发
 * 
 * @param array $order             订单数据
 * @param array $auto_delivery     自动发货配置
 * @param array $order_meta_data   订单元数据
 * @param int   $available_count   可用卡密数量
 * @param int   $total_count       购买数量
 */
function xingxy_partial_shipping($order, $auto_delivery, $order_meta_data, $available_count, $total_count)
{
    // 构建发货配置（模拟原始流程的参数）
    $delivery_config = $auto_delivery;
    $delivery_config['order_id']           = $order['id'];
    $delivery_config['options_active_str'] = $order_meta_data['options_active_str'] ?? '';
    $delivery_config['count']              = $available_count; // 关键：只取可用的数量

    // 调用原始卡密取出函数（会取出 available_count 个并标记为已发货）
    $delivery_html = zib_shop_get_auto_delivery_card_pass_content($delivery_config);

    if (!$delivery_html) {
        // 罕见情况：在查询和取出之间卡密被其他订单抢走了
        zib_shop_auto_delivery_fail_to_user($order, $order_meta_data);
        zib_shop_notify_shipping($order, $order_meta_data);
        return;
    }

    $remaining = $total_count - $available_count;

    // 在发货内容前追加部分发货提示
    $notice_html = xingxy_build_partial_notice($total_count, $available_count, $remaining);
    $delivery_html = $notice_html . $delivery_html;

    // 执行虚拟发货（会自动确认收货 + 发送邮件通知买家）
    zib_shop_virtual_shipping($order, $delivery_html, 'card_pass');

    // 记录 backlog 信息到 order_meta
    $backlog = array(
        'status'          => 'pending',
        'total_count'     => $total_count,
        'delivered_count' => $available_count,
        'remaining_count' => $remaining,
        'created_time'    => current_time('mysql'),
    );

    $order_meta_data = zibpay::get_meta($order['id'], 'order_data');
    $order_meta_data['backlog'] = $backlog;
    zibpay::update_meta($order['id'], 'order_data', $order_meta_data);

    // 通知卖家补发
    xingxy_notify_seller_backlog($order, $order_meta_data, $backlog);
}

/**
 * 构建部分发货提示 HTML
 * 
 * @param int $total      总购买数量
 * @param int $delivered   已发货数量
 * @param int $remaining   待补发数量
 * @return string          HTML 提示框
 */
function xingxy_build_partial_notice($total, $delivered, $remaining)
{
    $html  = '<div style="background:linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%); border:1px solid #ffc107; border-radius:10px; padding:14px 18px; margin-bottom:16px; color:#856404; font-size:14px; line-height:1.6;">';
    $html .= '<div style="font-size:15px; font-weight:bold; margin-bottom:8px;">⚠️ 部分发货通知</div>';
    $html .= '<div>您购买了 <b style="color:#d63384;">' . $total . '</b> 张卡密，';
    $html .= '目前库存仅有 <b style="color:#d63384;">' . $delivered . '</b> 张，已优先为您发出。</div>';
    $html .= '<div style="margin-top:6px;">剩余 <b style="color:#d63384;">' . $remaining . '</b> 张将在商家补货后为您补发，请耐心等待。</div>';
    $html .= '<div style="margin-top:8px; font-size:12px; color:#a07800;">如有疑问请联系客服。</div>';
    $html .= '</div>';

    return $html;
}

/**
 * 通知卖家需要补发
 * 
 * 通过邮件 + 站内信通知商品作者（卖家），告知该订单库存不足需要补发。
 * 
 * @param array $order            订单数据
 * @param array $order_meta_data  订单元数据
 * @param array $backlog          补发信息
 */
function xingxy_notify_seller_backlog($order, $order_meta_data, $backlog)
{
    $product_id = $order['post_id'];
    $post_data  = get_post($product_id);

    if (!$post_data) {
        return;
    }

    $author_id   = $order['post_author'] ?: $post_data->post_author;
    $author_data = get_userdata($author_id);

    if (!$author_data || !isset($author_data->display_name)) {
        return;
    }

    $author_email = $author_data->user_email ?? '';
    $post_title   = $order_meta_data['product_title'] ?? '';
    if ($post_data) {
        $post_title = function_exists('zib_str_cut') ? zib_str_cut($post_data->post_title, 0, 20, '...') : mb_substr($post_data->post_title, 0, 20) . '...';
    }

    $options_active_name = $order_meta_data['options_active_name'] ?? '';
    $link = admin_url('admin.php?page=zibpay_page#/shipping');

    // 构建通知内容
    $title   = '⚠️ 卡密库存不足，订单需要补发[商品：' . $post_title . ']';
    $message = '您好！' . $author_data->display_name . '<br>';
    $message .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin:10px 0;color:#856404;">';
    $message .= '<b>⚠️ 卡密库存不足，需要补发</b><br>';
    $message .= '商品：<a href="' . get_the_permalink($product_id) . '">' . $post_title . (!$options_active_name ? '' : '[' . $options_active_name . ']') . '</a><br>';
    $message .= '订单号：' . $order['order_num'] . '<br>';
    $message .= '购买数量：<b style="color:#d63384;">' . $backlog['total_count'] . '</b> 张<br>';
    $message .= '已发货：<b style="color:#28a745;">' . $backlog['delivered_count'] . '</b> 张<br>';
    $message .= '待补发：<b style="color:#dc3545;">' . $backlog['remaining_count'] . '</b> 张<br>';
    $message .= '</div>';
    $message .= '订单金额：' . zib_floatval_round($order['pay_price']) . ($order['pay_type'] === 'points' ? '积分' : '') . '<br>';
    $message .= '付款时间：' . $order['pay_time'] . '<br>';
    $message .= '<br><b>请尽快补充卡密库存，然后到后台订单管理手动补发剩余卡密。</b><br>';
    $message .= '<a target="_blank" style="margin-top:20px;padding:5px 20px;display:inline-block;" class="but jb-blue" href="' . esc_url($link) . '">前往处理</a><br>';

    // 发送邮件
    if (function_exists('zib_send_email')) {
        zib_send_email($author_email, $title, $message);
    }

    // 发送站内信
    if (function_exists('_pz') && _pz('message_s', true) && class_exists('ZibMsg')) {
        ZibMsg::add(array(
            'send_user'    => 'admin',
            'receive_user' => $author_data->ID,
            'type'         => 'pay',
            'title'        => $title,
            'content'      => $message,
        ));
    }

    // 发送微信模板消息（如果支持）
    if (function_exists('zib_wechat_template_send')) {
        $wechat_template_data = array(
            'name'   => $post_title . (!$options_active_name ? '' : '[' . $options_active_name . ']'),
            'num'    => $order['order_num'],
            'time'   => $order['pay_time'],
            'desc'   => '卡密库存不足，需要补发 ' . $backlog['remaining_count'] . ' 张',
            'status' => '待补发',
        );
        zib_wechat_template_send($author_data->ID, 'shop_notify_shipping_to_author', $wechat_template_data, $link);
    }
}
