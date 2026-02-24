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
add_action('init', function () {
    // 摘掉原始回调
    remove_action('payment_order_success', 'zib_shop_order_payment_success', 10);
    // 挂载增强版回调
    add_action('payment_order_success', 'xingxy_order_payment_success_guard', 10, 2);
}, 999); // 用 init + 极高优先级，确保 Zibll 所有模块已加载完毕

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
        $auto_delivery = zib_shop_get_product_config($order['post_id'], 'auto_delivery');
        $delivery_type = $auto_delivery['type'] ?? '';

        // 仅对卡密发货类型进行拦截
        if ($delivery_type === 'card_pass') {
            xingxy_auto_shipping_guard($order, $auto_delivery);
        } else {
            zib_shop_auto_shipping($order);
        }
    } else {
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
        zib_shop_auto_delivery_fail_to_user($order, $order_meta_data);
        zib_shop_notify_shipping($order, $order_meta_data);
        return;
    }

    // 查询可用卡密数量
    $available_count = xingxy_get_available_card_count($card_pass_key);

    if ($available_count >= $count) {
        // 情况一：库存充足 → 走原始自动发货
        zib_shop_auto_shipping($order);
        return;
    }

    // 情况二/三：库存不足或完全无货 → 统一走部分发货（含零发货）+ 补发队列
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
    // 提取卡密备注（用于后续注册补发队列）
    $card_pass_key = $auto_delivery['card_pass_key'] ?? '';

    // 构建发货配置（模拟原始流程的参数）
    $delivery_config = $auto_delivery;
    $delivery_config['order_id']           = $order['id'];
    $delivery_config['options_active_str'] = $order_meta_data['options_active_str'] ?? '';
    $delivery_config['count']              = $available_count; // 关键：只取可用的数量

    $delivery_html = '';

    if ($available_count > 0) {
        // 有部分库存：调用原始卡密取出函数
        $delivery_html = zib_shop_get_auto_delivery_card_pass_content($delivery_config);

        if (!$delivery_html) {
            // 罕见情况：在查询和取出之间卡密被其他订单抢走了（降级为零库存处理）
            $available_count = 0;
        }
    }

    $remaining = $total_count - $available_count;

    // 在发货内容前追加部分发货提示
    $notice_html = xingxy_build_partial_notice($total_count, $available_count, $remaining);
    $delivery_html = $notice_html . $delivery_html;

    if ($available_count > 0) {
        // 有部分卡密发出：走正常虚拟发货流程（确认收货 + 通知买家）
        zib_shop_virtual_shipping($order, $delivery_html, 'card_pass');
    } else {
        // 零库存：仅保存发货内容到 order_meta，不触发确认收货
        // 保持 shipping_status = 0（待发货），订单留在"待收货"列表
        $order_meta_data['shipping_data'] = array_merge($order_meta_data['shipping_data'] ?? [], [
            'delivery_time'    => current_time('mysql'),
            'delivery_content' => $delivery_html,
            'delivery_type'    => 'card_pass',
        ]);
        zibpay::update_meta($order['id'], 'order_data', $order_meta_data);
    }

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

    // 注册到全局补发队列（用于导入卡密时自动检索）
    xingxy_register_pending_backlog($order['id'], $card_pass_key, $remaining);

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
    $percent = round(($delivered / $total) * 100);
    $html = '<!-- XINGXY_PARTIAL_NOTICE_START -->';
    $html .= '
    <div data-no-copy="1" style="
        background: var(--main-bg-color, #1a1d23);
        border: 1px solid rgba(255, 193, 7, 0.3);
        border-left: 3px solid #ffc107;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 12px;
        position: relative;
        overflow: hidden;
    ">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <div style="display:flex; align-items:center;">
                <span style="
                    display:inline-flex; align-items:center; justify-content:center;
                    width:20px; height:20px; border-radius:50%;
                    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
                    margin-right:8px; font-size:12px; flex-shrink:0;
                ">' . ($delivered > 0 ? '📦' : '⏳') . '</span>
                <span style="font-size:14px; font-weight:700; color:var(--color-text, #e0e0e0);">' . ($delivered > 0 ? '部分发货通知' : '等待发货通知') . '</span>
            </div>
            <div style="font-size:12px; color:var(--muted-3-color, #888);">' . $delivered . '/' . $total . ' (' . $percent . '%)</div>
        </div>
        
        <div style="font-size:13px; line-height:1.5; color:var(--muted-2-color, #b0b0b0); margin-bottom:10px;">' .
            ($delivered > 0
                ? '您购买 <b style="color:#ffc107;">' . $total . '</b> 张，当前发出 <b style="color:#52c41a;">' . $delivered . '</b> 张，剩余 <b style="color:#ff6b6b;">' . $remaining . '</b> 张待补发。'
                : '您购买的 <b style="color:#ffc107;">' . $total . '</b> 张卡密暂时缺货，商家正在备货中，到货后将自动为您发出。'
            ) . '
        </div>
        
        <div style="
            width:100%; height:4px; border-radius:2px;
            background: var(--muted-border-color, rgba(255,255,255,0.08));
            overflow:hidden; margin-bottom:8px;
        ">
            <div style="
                width:' . $percent . '%; height:100%; border-radius:2px;
                background: linear-gradient(90deg, #52c41a 0%, #95de64 100%);
                transition: width 0.6s ease;
            "></div>
        </div>
        
        <div style="
            font-size:11px; color: var(--muted-3-color, #999);
            padding-top:6px; border-top: 1px dashed var(--muted-border-color, rgba(255,255,255,0.1));
        ">
            💬 商家已收到补货通知，补发后您将收到邮件提醒。
        </div>
    </div>';
    $html .= '<!-- XINGXY_PARTIAL_NOTICE_END -->';

    return $html;
}

/**
 * 构建「全部到齐」提示（替换原来的黄色部分发货提示）
 */
function xingxy_build_completed_notice($total)
{
    $html = '
    <div data-no-copy="1" style="
        background: var(--main-bg-color, #1a1d23);
        border: 1px solid rgba(82, 196, 26, 0.3);
        border-left: 3px solid #52c41a;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 12px;
        position: relative;
    ">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <div style="display:flex; align-items:center;">
                <span style="
                    display:inline-flex; align-items:center; justify-content:center;
                    width:20px; height:20px; border-radius:50%;
                    background: linear-gradient(135deg, #52c41a 0%, #95de64 100%);
                    margin-right:8px; font-size:12px; flex-shrink:0;
                ">🎉</span>
                <span style="font-size:14px; font-weight:700; color:var(--color-text, #e0e0e0);">全部发货完成</span>
            </div>
            <div style="font-size:12px; color:var(--muted-3-color, #888);">' . $total . '/' . $total . ' (100%)</div>
        </div>
        <div style="font-size:13px; color:var(--muted-2-color, #b0b0b0);">
            您购买的 <b style="color:#52c41a;">' . $total . '</b> 张卡密已全部发出！感谢耐心等待！
        </div>
    </div>';

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
    $message .= '付款时间：' . $order['pay_time'] . '<br><br>';

    // 补货操作引导
    $message .= '<div style="background:#e8f5e9;border:1px solid #4caf50;border-radius:8px;padding:14px 16px;margin:10px 0;color:#2e7d32;">';
    $message .= '<b>📋 补货操作指引（仅需两步）</b><br><br>';
    $message .= '<b>第一步：补充卡密</b><br>';
    $message .= '进入商品编辑页 → 发货设置 → 导入新的卡密数据<br><br>';
    $message .= '<b>第二步：自动补发</b><br>';
    $message .= '卡密导入成功后，系统会<b style="color:#1b5e20;">自动检测</b>待补发订单并完成发货，无需手动操作<br><br>';
    $message .= '<span style="font-size:12px;color:#558b2f;">💡 提示：请确保导入卡密时使用的「备注」与原商品一致，否则系统无法自动匹配</span>';
    $message .= '</div>';

    $message .= '<a target="_blank" style="margin-top:20px;padding:5px 20px;display:inline-block;" class="but jb-blue" href="' . esc_url(get_edit_post_link($product_id, '')) . '">前往补货</a><br>';

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

// =========================================================================
//  自动补发系统
// =========================================================================

/**
 * 将订单注册到全局待补发队列
 * 
 * 使用 WordPress option 维护一个轻量级索引：
 *   xingxy_pending_backlogs = [
 *     { order_id, card_pass_key, remaining_count, created_time }
 *   ]
 */
function xingxy_register_pending_backlog($order_id, $card_pass_key, $remaining_count)
{
    $backlogs = get_option('xingxy_pending_backlogs', array());

    // 防止重复注册
    foreach ($backlogs as $item) {
        if ($item['order_id'] == $order_id) {
            return;
        }
    }

    $backlogs[] = array(
        'order_id'        => $order_id,
        'card_pass_key'   => $card_pass_key,
        'remaining_count' => $remaining_count,
        'created_time'    => current_time('mysql'),
    );

    update_option('xingxy_pending_backlogs', $backlogs, false);
}

/**
 * 从全局队列中移除已完成的补发订单
 */
function xingxy_remove_pending_backlog($order_id)
{
    $backlogs = get_option('xingxy_pending_backlogs', array());
    $backlogs = array_filter($backlogs, function ($item) use ($order_id) {
        return $item['order_id'] != $order_id;
    });
    update_option('xingxy_pending_backlogs', array_values($backlogs), false);
}

/**
 * 自动补发核心逻辑
 * 
 * 当商家导入新卡密后调用此函数。
 * 扫描该 card_pass_key 下所有 pending 的 backlog 订单，逐个处理。
 * 
 * @param string $card_pass_key  补货的卡密备注
 * @return array                 补发结果摘要
 */
function xingxy_auto_fulfill_backlogs($card_pass_key)
{
    $backlogs = get_option('xingxy_pending_backlogs', array());

    if (empty($backlogs)) {
        return array('fulfilled' => 0);
    }

    // 筛选出匹配当前 card_pass_key 的待补发订单
    $matching = array_filter($backlogs, function ($item) use ($card_pass_key) {
        return $item['card_pass_key'] === $card_pass_key;
    });

    if (empty($matching)) {
        return array('fulfilled' => 0);
    }

    $fulfilled_count = 0;

    foreach ($matching as $backlog_item) {
        $order_id        = $backlog_item['order_id'];
        $remaining_count = $backlog_item['remaining_count'];

        // 检查当前可用库存
        $available = xingxy_get_available_card_count($card_pass_key);
        if ($available <= 0) {
            break; // 库存耗尽，停止处理后续订单
        }

        // 取出所需数量（不超过可用库存）
        $to_fulfill = min($remaining_count, $available);

        // 构建发货配置
        $order = zibpay::get_order($order_id);
        if (!$order) {
            xingxy_remove_pending_backlog($order_id);
            continue;
        }

        $order_meta_data = zibpay::get_meta($order_id, 'order_data');

        $delivery_config = array(
            'type'               => 'card_pass',
            'card_pass_key'      => $card_pass_key,
            'order_id'           => $order_id,
            'options_active_str' => $order_meta_data['options_active_str'] ?? '',
            'count'              => $to_fulfill,
        );

        // 取出卡密
        $new_delivery_html = zib_shop_get_auto_delivery_card_pass_content($delivery_config);
        if (!$new_delivery_html) {
            continue;
        }

        // 构建补发提示
        $fulfill_notice = xingxy_build_fulfill_notice($to_fulfill, $remaining_count);

        // 追加到原发货内容
        $old_content = $order_meta_data['shipping_data']['delivery_content'] ?? '';
        $new_content = $old_content . $fulfill_notice . $new_delivery_html;

        // 计算补发后的剩余数量（必须在使用前计算）
        $new_remaining = $remaining_count - $to_fulfill;

        // 如果补发完毕，将头部黄色"部分发货通知"替换为绿色"全部到齐"版本
        if ($new_remaining <= 0) {
            $total_count = $order_meta_data['backlog']['total_count'] ?? 0;
            $completed_notice = xingxy_build_completed_notice($total_count);
            $new_content = preg_replace(
                '/<!-- XINGXY_PARTIAL_NOTICE_START -->.*?<!-- XINGXY_PARTIAL_NOTICE_END -->/s',
                $completed_notice,
                $new_content
            );
        }

        // 更新发货内容
        $order_meta_data['shipping_data']['delivery_content'] = $new_content;

        // 更新 backlog 状态
        $old_delivered  = $order_meta_data['backlog']['delivered_count'] ?? 0;

        $order_meta_data['backlog']['delivered_count'] = $old_delivered + $to_fulfill;
        $order_meta_data['backlog']['remaining_count'] = $new_remaining;
        $order_meta_data['backlog']['fulfilled_time']  = current_time('mysql');

        if ($new_remaining <= 0) {
            $order_meta_data['backlog']['status'] = 'fulfilled';
            xingxy_remove_pending_backlog($order_id);
        } else {
            // 还没补完，更新队列中的剩余数量
            $order_meta_data['backlog']['status'] = 'partial';
            $all_backlogs = get_option('xingxy_pending_backlogs', array());
            foreach ($all_backlogs as &$bl) {
                if ($bl['order_id'] == $order_id) {
                    $bl['remaining_count'] = $new_remaining;
                    break;
                }
            }
            update_option('xingxy_pending_backlogs', $all_backlogs, false);
        }

        zibpay::update_meta($order_id, 'order_data', $order_meta_data);

        // 如果补发完毕且订单尚未确认收货，触发确认收货
        $current_shipping_status = zib_shop_get_order_shipping_status($order_id);
        if ($new_remaining <= 0 && $current_shipping_status == 0) {
            zib_shop_order_receive_confirm($order_id, 'auto', '补发完成自动确认收货', $order_meta_data);
        }

        // 通知买家补发完成
        xingxy_notify_buyer_fulfilled($order, $order_meta_data, $to_fulfill, $new_remaining);

        $fulfilled_count++;
    }

    return array('fulfilled' => $fulfilled_count);
}

/**
 * 构建补发成功提示 HTML（追加在原内容后面）
 */
function xingxy_build_fulfill_notice($fulfilled_count, $was_remaining)
{
    $is_complete = ($fulfilled_count >= $was_remaining);

    $html = '
    <div data-no-copy="1" style="
        background: var(--main-bg-color, #1a1d23);
        border: 1px dashed rgba(82, 196, 26, 0.4);
        border-radius: 8px;
        padding: 10px 14px;
        margin: 12px 0;
    ">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center;">
                <span style="font-size:13px; margin-right:6px;">✅</span>
                <span style="font-size:13px; font-weight:700; color:#52c41a;">' . ($is_complete ? '商品补发完成' : '商品部分补发') . '</span>
            </div>
            <div style="font-size:11px; color:var(--muted-3-color, #999);">' . current_time('m-d H:i') . '</div>
        </div>
        <div style="font-size:12px; color:var(--muted-2-color, #b0b0b0); margin-top:4px;">
            已补发 <b style="color:#52c41a;">' . $fulfilled_count . '</b> 张卡密' . ($is_complete ? '，全单已完结。' : '。') . '
        </div>
    </div>';

    return $html;
}

/**
 * 通知买家补发完成
 */
function xingxy_notify_buyer_fulfilled($order, $order_meta_data, $fulfilled_count, $remaining)
{
    $product_id = $order['post_id'];
    $post_data  = get_post($product_id);
    $user_data  = get_userdata($order['user_id']);

    if (!$user_data) {
        return;
    }

    $post_title = $order_meta_data['product_title'] ?? '';
    if ($post_data) {
        $post_title = function_exists('zib_str_cut') ? zib_str_cut($post_data->post_title, 0, 20, '...') : mb_substr($post_data->post_title, 0, 20) . '...';
    }

    $is_complete = ($remaining <= 0);
    $order_link  = function_exists('zib_get_user_center_url') ? zib_get_user_center_url('order') : home_url('/user/order');

    $title   = ($is_complete ? '✅ 补发完成' : '📦 部分补发') . '：[' . $post_title . ']';
    $message = '您好！' . $user_data->display_name . '<br>';
    $message .= '<div style="background:var(--main-bg-color,#f0f9eb);border:1px solid rgba(82,196,26,0.3);border-left:4px solid #52c41a;border-radius:8px;padding:12px 16px;margin:10px 0;color:var(--color-text,#333);">';
    $message .= '<b>' . ($is_complete ? '✅ 您的购买已全部发出！' : '📦 商家已为您补发部分卡密') . '</b><br>';
    $message .= '商品：' . $post_title . '<br>';
    $message .= '本次补发：<b style="color:#52c41a;">' . $fulfilled_count . '</b> 张<br>';
    if (!$is_complete) {
        $message .= '仍待补发：<b style="color:#ff6b6b;">' . $remaining . '</b> 张<br>';
    }
    $message .= '</div>';
    $message .= '您可以在订单详情的「发货信息」中查看完整的卡密内容。<br>';
    $message .= '<a target="_blank" style="margin-top:20px;padding:5px 20px;display:inline-block;" class="but jb-green" href="' . esc_url($order_link) . '">查看订单</a><br>';

    // 发送邮件
    if (function_exists('zib_send_email')) {
        $user_email = $user_data->user_email ?? '';
        zib_send_email($user_email, $title, $message);
    }

    // 发送站内信
    if (function_exists('_pz') && _pz('message_s', true) && class_exists('ZibMsg')) {
        ZibMsg::add(array(
            'send_user'    => $post_data ? $post_data->post_author : 'admin',
            'receive_user' => $user_data->ID,
            'type'         => 'pay',
            'title'        => $title,
            'content'      => $message,
        ));
    }
}
