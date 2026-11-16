<?php
/**
 * Xingxy 注册安全风控与邮箱清洗模块
 *
 * 负责本站新用户注册时的唯一自然人防护：
 * 1. 限制仅允许白名单邮箱服务商注册（如 QQ 邮箱、Google 邮箱）
 * 2. 规范化清洗 Gmail 别名（点号与加号标签），防止黑产利用别名注册海量小号刷分
 * 3. 将主邮箱记录到 usermeta (_xingxy_canonical_email) 进行双向排重
 *
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

const XINGXY_CANONICAL_EMAIL_META = '_xingxy_canonical_email';

/**
 * 规范化邮箱（提取主邮箱并去除别名标签）
 *
 * @param string $email 原始邮箱
 * @return string 规范化后的唯一主邮箱
 */
function xingxy_normalize_email($email) {
    $email = strtolower(trim((string) $email));
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }

    list($local, $domain) = explode('@', $email, 2);

    // 处理 Gmail / Googlemail：去除点号，去除加号后的 tag
    if (in_array($domain, array('gmail.com', 'googlemail.com'), true)) {
        if (strpos($local, '+') !== false) {
            $local = substr($local, 0, strpos($local, '+'));
        }
        $local = str_replace('.', '', $local);
        $domain = 'gmail.com';
    }

    return $local . '@' . $domain;
}

/**
 * 获取允许注册的邮箱域名白名单
 *
 * @return array
 */
function xingxy_get_allowed_email_domains() {
    $raw = xingxy_pz('allowed_email_domains', "qq.com\nvip.qq.com\nfoxmail.com\ngmail.com\ngooglemail.com");
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $domains = array();

    foreach ($lines as $line) {
        $line = strtolower(trim($line));
        // 去除用户可能误输入的 @ 前缀
        if (strpos($line, '@') === 0) {
            $line = substr($line, 1);
        }
        if ($line !== '') {
            $domains[$line] = true;
        }
    }

    return array_keys($domains);
}

/**
 * 注册流程安全拦截过滤器
 *
 * @param WP_Error $errors
 * @param string   $sanitized_user_login
 * @param string   $user_email
 * @return WP_Error
 */
function xingxy_filter_registration_errors($errors, $sanitized_user_login, $user_email) {
    if (!is_wp_error($errors)) {
        $errors = new WP_Error();
    }

    // 检查后台是否开启严格邮箱过滤
    if (!xingxy_pz('enable_strict_email_filter', false)) {
        return $errors;
    }

    $email = strtolower(trim((string) $user_email));
    if ($email === '') {
        return $errors;
    }

    if (strpos($email, '@') === false) {
        $errors->add('invalid_email_format', '邮箱格式不正确');
        return $errors;
    }

    list($local, $domain) = explode('@', $email, 2);

    // 1. 邮箱域名白名单检查
    $allowed_domains = xingxy_get_allowed_email_domains();
    if (!empty($allowed_domains) && !in_array($domain, $allowed_domains, true)) {
        $allowed_hint = implode('、', array_slice($allowed_domains, 0, 4));
        $errors->add('email_domain_not_allowed', '本站注册仅支持官方允许的邮箱（如 ' . esc_html($allowed_hint) . ' 等）');
        return $errors;
    }

    // 2. Gmail 别名小号拦截（防多账号刷邀请与积分）
    if (xingxy_pz('enable_gmail_alias_guard', true)) {
        $canonical = xingxy_normalize_email($email);
        if ($canonical !== '') {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) {
                return $errors;
            }

            // 检查历史已记录的 canonical 邮箱
            $meta_match = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                XINGXY_CANONICAL_EMAIL_META,
                $canonical
            ));

            if ($meta_match) {
                $errors->add('canonical_email_exists', '该 Google 邮箱主账号或其别名已被注册，请直接登录');
                return $errors;
            }

            // 针对规范化后的邮箱与原始输入不同的场景，检查是否匹配已有的 wp_users.user_email
            if ($canonical !== $email) {
                $user_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE user_email = %s LIMIT 1",
                    $canonical
                ));
                if ($user_exists) {
                    $errors->add('canonical_email_exists', '该 Google 邮箱主账号已被注册，请直接登录');
                    return $errors;
                }
            }
        }
    }

    return $errors;
}
add_filter('registration_errors', 'xingxy_filter_registration_errors', 20, 3);

/**
 * 用户注册成功后记录规范化主邮箱，供后续排重使用
 *
 * @param int $user_id
 */
function xingxy_record_canonical_email($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return;
    }

    $user = get_user_by('id', $user_id);
    if (!$user || empty($user->user_email)) {
        return;
    }

    $canonical = xingxy_normalize_email($user->user_email);
    if ($canonical !== '') {
        update_user_meta($user_id, XINGXY_CANONICAL_EMAIL_META, $canonical);
    }
}
add_action('user_register', 'xingxy_record_canonical_email', 20, 1);
