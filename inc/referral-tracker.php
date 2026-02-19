<?php
/**
 * Xingxy 推广链接伪装追踪模块
 *
 * 核心功能：
 * 1. 为每个用户生成唯一随机推广码（存 user_meta，数据库映射，100% 可靠）
 * 2. 生成伪装链接：?utm_source=share&v=2&_s={code}&_t={timestamp}
 * 3. 用户打开链接时静默写入持久化 Cookie（无任何用户感知）
 * 4. 注册时从 Cookie 读取，同步写入 Zibll Session，触发推荐关系
 * 5. 拦截 Zibll 佣金详情页，将旧格式链接替换为伪装链接
 *
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===================================================================
// 推广码生成与解析（数据库映射方案，彻底可靠）
// ===================================================================

/**
 * 获取或生成用户的专属推广码
 * 随机码存储在 user_meta，不依赖任何编解码数学
 *
 * @param int $user_id 用户ID
 * @return string 5位随机推广码，如 "bdep3"
 */
function xingxy_get_user_ref_code($user_id) {
    if (!$user_id) {
        return '';
    }

    // 已有推广码则直接返回
    $code = get_user_meta($user_id, '_xingxy_ref_code', true);
    if ($code) {
        return $code;
    }

    // 生成唯一的 5 位随机码（避开易混淆字符）
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    do {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        // 确保全局唯一（极低概率冲突）
        $exists = xingxy_decode_ref_code($code);
    } while ($exists);

    update_user_meta($user_id, '_xingxy_ref_code', $code);
    return $code;
}

/**
 * 将推广码解析回用户ID（数据库查询，不依赖编解码算法）
 *
 * @param string $code 推广码
 * @return int|false 用户ID，或 false（无效）
 */
function xingxy_decode_ref_code($code) {
    if (empty($code) || strlen($code) < 3) {
        return false;
    }

    global $wpdb;
    $user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM $wpdb->usermeta 
         WHERE meta_key = '_xingxy_ref_code' AND meta_value = %s 
         LIMIT 1",
        sanitize_text_field($code)
    ));

    return $user_id ? (int) $user_id : false;
}

// ===================================================================
// 推广链接生成
// ===================================================================

/**
 * 生成伪装推广链接
 * 格式：?utm_source=share&v=2&_s={code}&_t={timestamp}
 * _s 是真正推广码，其余全是伪装参数（UTM来源、版本号、时间戳）
 *
 * @param int    $user_id 推广者用户ID
 * @param string $url     落地页URL，默认首页
 * @return string 伪装后的完整推广链接
 */
function xingxy_generate_referral_url($user_id = 0, $url = '') {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    if (!$user_id) {
        return home_url('/');
    }
    if (!$url) {
        $url = home_url('/');
    }

    $code = xingxy_get_user_ref_code($user_id);
    if (!$code) {
        return $url;
    }

    return add_query_arg(array(
        'utm_source' => 'share',   // 伪装：看起来像正常来源追踪
        'v'          => '2',       // 伪装：看起来像版本号
        '_s'         => $code,     // 真正推广码（藏在中间）
        '_t'         => time(),    // 伪装：看起来像防缓存时间戳
    ), $url);
}

// ===================================================================
// 追踪拦截：静默种 Cookie + 同步 Session
// ===================================================================

/**
 * 在页面加载时解析推广链接，静默写入 Cookie
 * 优先级 0，确保在 Zibll 的 template_redirect（优先级1）之前执行
 */
function xingxy_intercept_referral_tracking() {
    $code = isset($_REQUEST['_s']) ? sanitize_text_field($_REQUEST['_s']) : '';
    if (empty($code)) {
        return;
    }

    // 解码推广码 → 推荐人用户ID
    $referrer_id = xingxy_decode_ref_code($code);
    if (!$referrer_id) {
        return;
    }

    // 防止自推
    $current_user_id = get_current_user_id();
    if ($current_user_id && $current_user_id == $referrer_id) {
        return;
    }

    // 读取后台配置的追踪窗口（小时），默认 24 小时
    $hours   = (int) xingxy_pz('referral_cookie_hours', 24);
    $expires = $hours > 0 ? time() + $hours * 3600 : 0;

    // 静默写入持久化 Cookie（HttpOnly，用户完全无感知）
    setcookie(
        '_xref',        // Cookie 名（短而不显眼）
        $code,          // 存推广码，非明文用户ID
        $expires,
        COOKIEPATH,
        COOKIE_DOMAIN,
        is_ssl(),
        true            // HttpOnly：防止 JS 读取篡改
    );

    // 同时写入 Session，兼容 Zibll 底层当次会话追踪
    @session_start();
    if (empty($_SESSION['ZIBPAY_REFERRER_ID'])) {
        $_SESSION['ZIBPAY_REFERRER_ID'] = $referrer_id;
    }
}
add_action('template_redirect', 'xingxy_intercept_referral_tracking', 0);

// ===================================================================
// 注册时兜底：从 Cookie 恢复推荐人到 Session
// ===================================================================

/**
 * 用户注册时，如果 Session 中没有推荐人（例如浏览器重启后访问），
 * 则从持久化 Cookie 读取推广码，恢复推荐人到 Session
 * 确保 Zibll 底层的 zibpay_register_save_referrer（优先级10）能正确读取
 */
function xingxy_restore_referrer_from_cookie($user_id) {
    @session_start();

    // Session 中已有推荐人，无需处理
    if (!empty($_SESSION['ZIBPAY_REFERRER_ID'])) {
        return;
    }

    // 从 Cookie 读取推广码
    $code = isset($_COOKIE['_xref']) ? sanitize_text_field($_COOKIE['_xref']) : '';
    if (empty($code)) {
        return;
    }

    // 解码推广码
    $referrer_id = xingxy_decode_ref_code($code);
    if (!$referrer_id || $referrer_id == $user_id) {
        return;
    }

    // 写入 Session，供 Zibll 底层读取
    $_SESSION['ZIBPAY_REFERRER_ID'] = $referrer_id;
}
// 优先级 5，在 Zibll 的 zibpay_register_save_referrer（默认优先级10）之前执行
add_action('user_register', 'xingxy_restore_referrer_from_cookie', 5);

// ===================================================================
// 修复：拦截 Zibll 佣金详情页，替换旧格式推广链接为伪装链接
// ===================================================================

/**
 * 过滤 Zibll 推荐奖励页面的 HTML 输出
 * 将页面中所有旧格式 ?ref=用户ID 链接替换为新的伪装链接
 */
add_filter('main_user_tab_content_rebate', function ($html) {
    $user_id = get_current_user_id();
    if (!$user_id || empty($html)) {
        return $html;
    }

    // 生成新格式伪装链接
    $new_url   = xingxy_generate_referral_url($user_id);
    // 旧格式链接（Zibll 底层生成的格式）
    $old_url   = add_query_arg('ref', $user_id, home_url('/'));

    // 替换 HTML 中所有出现的旧格式链接（包括 esc_attr 版本）
    $html = str_replace(esc_attr($old_url), esc_attr($new_url), $html);
    $html = str_replace($old_url, $new_url, $html);

    return $html;
}, 20);

// ===================================================================
// 修复：商城返佣参数继承 Bug
// ===================================================================

/**
 * 修复 Zibll 商城的参数继承 Bug
 *
 * 问题：当商品的推广返佣设置为"默认"（继承上级配置）时，
 * product_config meta 中存储了 rebate.type = ""（空字符串），
 * 导致 zib_shop_get_product_in_turn_config 认为商品有自己的返佣配置，
 * 不再向分类/全局配置 fallback，佣金计算最终返回 0。
 *
 * 修复方式：拦截 product_config meta 的读取，
 * 当 rebate.type 为空时，删除整个 rebate 子数组，
 * 让 fallback 机制正常工作。
 */
add_filter('get_post_metadata', function ($value, $object_id, $meta_key, $single) {
    // 仅拦截 product_config 的读取
    if ($meta_key !== 'product_config') {
        return $value;
    }

    // 防止递归：直接从数据库读取原始值
    static $is_filtering = false;
    if ($is_filtering) {
        return $value;
    }

    $is_filtering = true;
    $raw = get_post_meta($object_id, 'product_config', true);
    $is_filtering = false;

    if (!is_array($raw)) {
        return $value;
    }

    // 修复返佣配置：如果 rebate.type 为空（代表"默认"选项）
    // 则用全局配置值替换，实现正确的参数继承
    if (isset($raw['rebate']) && is_array($raw['rebate']) && empty($raw['rebate']['type'])) {
        $global_rebate = _pz('shop_rebate');
        if (is_array($global_rebate) && !empty($global_rebate['type'])) {
            $raw['rebate'] = $global_rebate;
        }
    }

    // 返回修正后的值（需包装为数组，因为 WordPress get_metadata filter 的约定）
    return $single ? array($raw) : array(array($raw));
}, 10, 4);

