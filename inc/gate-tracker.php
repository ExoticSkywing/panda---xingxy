<?php
/**
 * Xingxy 分享链接追踪 & 用户分群打标模块
 *
 * 核心功能：
 * 1. /go/{key} 短链重定向（设 Cookie + 302 到内容页）
 * 2. JS Fragment 双向桥接（Cookie↔Fragment，跨浏览器信号传递）
 * 3. 注册表单隐藏字段注入（兼容 Zibll AJAX 注册）
 * 4. 注册时自动打标（优先级链：_sk > _gate > _s > general）
 * 5. xingxy_share_links 表自动创建
 *
 * 架构文档：patches/antigravity/Audience Segmentation Architecture.md §3.1.1
 *
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===================================================================
// 数据库表：xingxy_share_links（自动创建/升级）
// ===================================================================

function xingxy_ensure_share_links_table() {
    $installed_ver = get_option('xingxy_share_links_db_version', '0');
    $current_ver   = '1.0';

    if ($installed_ver === $current_ver) {
        return;
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'xingxy_share_links';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        share_key   VARCHAR(12) NOT NULL,
        user_id     BIGINT NOT NULL,
        tag_slug    VARCHAR(50) NULL,
        content_url VARCHAR(500) NOT NULL,
        max_uses    INT NULL,
        used_count  INT DEFAULT 0,
        expires_at  DATETIME NULL,
        clicks      INT DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY share_key (share_key),
        KEY user_id (user_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('xingxy_share_links_db_version', $current_ver);
}
add_action('after_setup_theme', 'xingxy_ensure_share_links_table');

// ===================================================================
// WordPress Rewrite：/go/{key}
// ===================================================================

add_action('init', function () {
    add_rewrite_rule('^go/([A-Za-z0-9]+)/?$', 'index.php?xingxy_sk=$matches[1]', 'top');

    // 首次激活后刷新一次 rewrite rules
    if (!get_option('xingxy_gate_tracker_rewrite_flushed')) {
        flush_rewrite_rules(false);
        update_option('xingxy_gate_tracker_rewrite_flushed', '1');
    }
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'xingxy_sk';
    return $vars;
});

// ===================================================================
// 第一层：PHP 短链重定向
// ===================================================================

add_action('template_redirect', function () {
    $sk = get_query_var('xingxy_sk');
    if (empty($sk)) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'xingxy_share_links';
    $link  = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE share_key = %s", $sk
    ));

    if (!$link) {
        wp_redirect(home_url('/'));
        exit;
    }

    // 设置分群追踪 Cookie（httpOnly=false，JS Fragment 桥接需读取）
    setcookie('_xsk', $sk, time() + 7 * 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);

    // 同时建立推荐关系：复用 referral-tracker 的 _xref Cookie
    if ($link->user_id && function_exists('xingxy_get_user_ref_code')) {
        $ref_code = xingxy_get_user_ref_code($link->user_id);
        if ($ref_code) {
            $hours   = (int) xingxy_pz('referral_cookie_hours', 24);
            $expires = $hours > 0 ? time() + $hours * 3600 : 0;
            setcookie('_xref', $ref_code, $expires, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    // 记录点击
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET clicks = clicks + 1 WHERE id = %d", $link->id
    ));

    // 302 重定向到内容页（干净 URL，无追踪参数）
    wp_redirect($link->content_url);
    exit;
}, 0);

// ===================================================================
// 第二层 + 第三层：JS Fragment 桥接 + 注册表单隐藏字段注入
// ===================================================================

add_action('wp_footer', function () {
    // 只对未登录用户输出（已登录不需要注册追踪）
    if (is_user_logged_in()) {
        return;
    }

    $sk = isset($_COOKIE['_xsk']) ? sanitize_text_field($_COOKIE['_xsk']) : '';
    ?>
    <script>
    (function(){
        var ck = <?php echo json_encode($sk); ?>;
        var h = location.hash.slice(1);

        // === Fragment 双向桥接 ===
        if (ck) {
            // Cookie → Fragment：确保地址栏带信号（"用浏览器打开"时渡信号）
            if (h !== ck) {
                history.replaceState(null, '', location.pathname + location.search + '#' + ck);
            }
        } else if (/^[A-Za-z0-9]{6,12}$/.test(h)) {
            // Fragment → Cookie：系统浏览器着陆时恢复信号
            ck = h;
            var exp = new Date(Date.now() + 7*864e5).toUTCString();
            document.cookie = '_xsk=' + h
                + ';path=<?php echo esc_js(COOKIEPATH); ?>'
                + ';expires=' + exp
                + '<?php echo is_ssl() ? ";secure" : ""; ?>'
                + ';samesite=lax';
        }

        // === 注册表单隐藏字段注入（兼容 Zibll AJAX 注册） ===
        if (ck) {
            var injectField = function() {
                var forms = document.querySelectorAll('form#sign-up, #sign-up form');
                for (var i = 0; i < forms.length; i++) {
                    if (!forms[i].querySelector('input[name="_sk"]')) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = '_sk';
                        inp.value = ck;
                        forms[i].appendChild(inp);
                    }
                }
            };
            // 立即执行一次，并在 DOM 变化时重试（模态框可能延迟渲染）
            injectField();
            if (window.MutationObserver) {
                var mo = new MutationObserver(injectField);
                mo.observe(document.body, {childList: true, subtree: true});
                // 30秒后停止观察，避免性能浪费
                setTimeout(function(){ mo.disconnect(); }, 30000);
            }
        }
    })();
    </script>
    <?php
}, 999);

// ===================================================================
// 注册时打标（优先级链：_sk > _gate > _s > general）
// 优先级 4，在 referral-tracker 的 user_register(5) 之前
// ===================================================================

add_action('user_register', function ($user_id) {
    $tagged = false;

    // ① 分享链接 _sk
    $sk = sanitize_text_field($_POST['_sk'] ?? $_COOKIE['_xsk'] ?? '');
    if ($sk) {
        global $wpdb;
        $table = $wpdb->prefix . 'xingxy_share_links';
        $link  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE share_key = %s", $sk
        ));

        if ($link && $link->tag_slug) {
            $alive = true;
            if ($link->expires_at && strtotime($link->expires_at) < current_time('timestamp')) {
                $alive = false;
            }
            if ($link->max_uses && $link->used_count >= $link->max_uses) {
                $alive = false;
            }

            if ($alive) {
                xingxy_add_segment($user_id, $link->tag_slug);
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET used_count = used_count + 1 WHERE id = %d",
                    $link->id
                ));
                update_user_meta($user_id, '_source_sk', $sk);
                $tagged = true;
            }
        }
        // 无论是否打标成功，推荐关系由 _xref Cookie + referral-tracker 处理
    }

    // ② 口令 _gate（预留，延后启用）
    // if (!$tagged) {
    //     $gate = sanitize_text_field($_POST['_gate'] ?? $_COOKIE['_xgate'] ?? '');
    //     if ($gate) {
    //         update_user_meta($user_id, '_source_gate', $gate);
    //         $mapping = xingxy_pz('gate_segment_mapping', []);
    //         $segment = $mapping[$gate] ?? 'general';
    //         xingxy_add_segment($user_id, $segment);
    //         $tagged = true;
    //     }
    // }

    // ③ 推荐链继承：继承推荐人的标签（带 >ref 子标签标记）
    if (!$tagged) {
        $ref_code = isset($_COOKIE['_xref']) ? sanitize_text_field($_COOKIE['_xref']) : '';
        if ($ref_code && function_exists('xingxy_decode_ref_code')) {
            $referrer_id = xingxy_decode_ref_code($ref_code);
            if ($referrer_id && $referrer_id != $user_id) {
                $referrer_segments = get_user_meta($referrer_id, '_xingxy_segments', true);
                if (is_array($referrer_segments)) {
                    foreach ($referrer_segments as $seg) {
                        if ($seg !== 'general') {
                            // 去掉父标签可能已有的 >ref 后缀，取根标签
                            $root_seg = preg_replace('/>ref$/', '', $seg);
                            xingxy_add_segment($user_id, $root_seg . '>ref');
                            $tagged = true;
                        }
                    }
                }
                if ($tagged) {
                    update_user_meta($user_id, '_source_ref', $referrer_id);
                }
            }
        }
    }

    // ④ 兜底
    if (!$tagged) {
        xingxy_add_segment($user_id, 'general');
    }

    // 清除分群追踪 Cookie（一次性使用）
    setcookie('_xsk', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);
}, 4);

// ===================================================================
// 辅助函数
// ===================================================================

/**
 * 为用户添加 segment 标签
 *
 * @param int    $user_id 用户ID
 * @param string $slug    标签 slug
 */
function xingxy_add_segment($user_id, $slug) {
    $segments = get_user_meta($user_id, '_xingxy_segments', true);
    if (!is_array($segments)) {
        $segments = [];
    }
    if (!in_array($slug, $segments, true)) {
        $segments[] = $slug;
    }
    update_user_meta($user_id, '_xingxy_segments', $segments);
}

/**
 * 生成唯一随机 share_key
 *
 * @param int $length 长度（默认8位）
 * @return string
 */
function xingxy_generate_share_key($length = 8) {
    // 排除易混淆字符 0OoIl1
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    global $wpdb;
    $table = $wpdb->prefix . 'xingxy_share_links';

    do {
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE share_key = %s", $key
        ));
    } while ($exists);

    return $key;
}
