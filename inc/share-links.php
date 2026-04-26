<?php
/**
 * Xingxy 管理员分享链接面板
 *
 * 增强 Zibll 分享模态框，为管理员添加带标签的短链生成功能。
 * 管理员点击任意帖子的"分享"按钮时，模态框底部追加：
 *   - 标签选择、有效期、使用次数
 *   - 一键生成 /go/{key} 短链
 *   - 已有短链列表
 *
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===================================================================
// 桌面端 hover 下拉菜单：为管理员注入"追踪短链"按钮
// ===================================================================

add_action('wp_footer', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $ajax_base = admin_url('admin-ajax.php');
    ?>
    <script>
    (function(){
        var base = <?php echo json_encode($ajax_base); ?>;
        // 管理员：把桌面端 hover 下拉分享菜单替换为模态框触发器
        // 这样文章页和商城页体验一致（都弹模态框，含管理面板）
        document.querySelectorAll('.hover-show.dropup').forEach(function(el){
            var menu = el.querySelector('.share-button.dropdown-menu');
            if (!menu) return;
            var poster = menu.querySelector('[poster-share]');
            if (!poster) return;
            var pid = poster.getAttribute('poster-share');
            if (!pid) return;

            var url = base + '?action=share_modal&id=' + encodeURIComponent(pid) + '&type=post';

            // 保留原始图标和文字
            var icon = el.querySelector('svg');
            var text = el.querySelector(':scope > text');
            var inner = (icon ? icon.outerHTML : '') + (text ? text.outerHTML : '<text>分享</text>');

            // 创建模态框触发器，替换整个 hover 下拉
            var trigger = document.createElement('a');
            trigger.href = 'javascript:;';
            trigger.className = el.className.replace('hover-show','').replace('dropup','').trim();
            trigger.setAttribute('data-toggle', 'RefreshModal');
            trigger.setAttribute('data-remote', url);
            trigger.setAttribute('data-class', 'modal-mini');
            trigger.setAttribute('mobile-bottom', 'true');
            trigger.setAttribute('data-height', '500');
            trigger.innerHTML = inner;
            trigger.style.cursor = 'pointer';

            el.parentNode.replaceChild(trigger, el);
        });
    })();
    </script>
    <?php
}, 998);

// ===================================================================
// 增强 Zibll 分享模态框（管理员专属，OB 注入）
// ===================================================================

add_action('wp_ajax_share_modal', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    // 使用 register_shutdown_function 在 Zibll exit 之后追加管理面板
    ob_start();
    register_shutdown_function(function () {
        $buffer = ob_get_contents();
        if (ob_get_level()) {
            ob_end_clean();
        }

        $id   = !empty($_REQUEST['id']) ? $_REQUEST['id'] : 0;
        $type = !empty($_REQUEST['type']) ? $_REQUEST['type'] : 'post';

        if ($id && in_array($type, ['post', 'term'], true)) {
            $buffer .= xingxy_render_admin_share_panel($id, $type);
        }

        echo $buffer;
    });
}, 1);

// ===================================================================
// AJAX：创建分享链接
// ===================================================================

add_action('wp_ajax_xingxy_create_share_link', function () {
    check_ajax_referer('xingxy_share_link', '_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    $content_id   = intval($_POST['content_id'] ?? 0);
    $content_type = sanitize_text_field($_POST['content_type'] ?? 'post');
    $tag_slug     = sanitize_text_field($_POST['tag_slug'] ?? '');
    $max_uses     = intval($_POST['max_uses'] ?? 0) ?: null;
    $expires_sec  = intval($_POST['expires_sec'] ?? 0);

    $content_url = xingxy_resolve_content_url($content_id, $content_type);
    if (!$content_url) {
        wp_send_json_error('内容不存在');
    }

    $share_key = xingxy_generate_share_key();
    $expires_at  = $expires_sec > 0
        ? date('Y-m-d H:i:s', current_time('timestamp') + $expires_sec)
        : null;

    global $wpdb;
    $table  = $wpdb->prefix . 'xingxy_share_links';
    $result = $wpdb->insert($table, [
        'share_key'   => $share_key,
        'user_id'     => get_current_user_id(),
        'tag_slug'    => $tag_slug ?: null,
        'content_url' => $content_url,
        'max_uses'    => $max_uses,
        'expires_at'  => $expires_at,
    ]);

    if (!$result) {
        wp_send_json_error('创建失败：' . $wpdb->last_error);
    }

    $short_url = home_url('/go/' . $share_key);
    wp_send_json_success([
        'share_key'   => $share_key,
        'url'         => $short_url,
        'tag_slug'    => $tag_slug,
        'max_uses'    => $max_uses,
        'expires_sec'  => $expires_sec,
        'expires_at'   => $expires_at,
    ]);
});

// ===================================================================
// 渲染管理员分享面板 HTML
// ===================================================================

/**
 * 根据内容类型和 ID 获取 URL
 */
function xingxy_resolve_content_url($id, $type = 'post') {
    if ($type === 'term') {
        $term = get_term(intval($id));
        return ($term && !is_wp_error($term)) ? get_term_link($term) : '';
    }
    $post = get_post(intval($id));
    return $post ? get_permalink($post) : '';
}

function xingxy_render_admin_share_panel($id, $type = 'post') {
    global $wpdb;
    $table       = $wpdb->prefix . 'xingxy_share_links';
    $content_url = xingxy_resolve_content_url($id, $type);
    if (!$content_url) return '';

    // 查已有的分享链接
    $existing = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE content_url = %s ORDER BY created_at DESC LIMIT 10",
        $content_url
    ));

    $nonce    = wp_create_nonce('xingxy_share_link');
    $ajax_url = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <div class="xingxy-admin-share" style="margin-top:15px;padding-top:15px;border-top:1px dashed #e0e0e0;">
        <div style="display:flex;align-items:center;margin-bottom:12px;">
            <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;margin-right:8px;">管理员</span>
            <b style="font-size:14px;">生成追踪短链</b>
        </div>

        <div id="xingxy-share-form">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                <div>
                    <label style="font-size:12px;color:#888;display:block;margin-bottom:3px;">标签 (tag_slug)</label>
                    <input type="text" id="xingxy-tag" placeholder="如 biz_vip"
                           style="width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:12px;color:#888;display:block;margin-bottom:3px;">使用次数上限</label>
                    <input type="number" id="xingxy-max-uses" placeholder="不限" min="1"
                           style="width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;box-sizing:border-box;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
                <div>
                    <label style="font-size:12px;color:#888;display:block;margin-bottom:3px;">有效期</label>
                    <select id="xingxy-expires"
                            style="width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;box-sizing:border-box;background:#fff;">
                        <option value="0">永久有效</option>
                        <option value="120">⚡ 2 分钟（测试）</option>
                        <option value="300">⚡ 5 分钟（测试）</option>
                        <option value="3600">⚡ 1 小时（测试）</option>
                        <option value="86400">1 天</option>
                        <option value="259200">3 天</option>
                        <option value="604800" selected>7 天</option>
                        <option value="2592000">30 天</option>
                        <option value="7776000">90 天</option>
                        <option value="31536000">365 天</option>
                    </select>
                </div>
                <div style="display:flex;align-items:flex-end;">
                    <button type="button" id="xingxy-gen-btn"
                            style="width:100%;padding:7px 0;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:bold;">
                        生成短链
                    </button>
                </div>
            </div>
        </div>

        <div id="xingxy-share-result" style="display:none;background:#f0f7ff;border-radius:8px;padding:10px 12px;margin-bottom:12px;">
            <div style="font-size:12px;color:#888;margin-bottom:4px;">已生成短链：</div>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="xingxy-result-url" readonly
                       style="flex:1;padding:6px 10px;border:1px solid #c5d8f0;border-radius:6px;font-size:13px;background:#fff;box-sizing:border-box;">
                <button type="button" id="xingxy-copy-btn"
                        style="padding:6px 14px;background:#667eea;color:#fff;border:none;border-radius:6px;font-size:12px;cursor:pointer;white-space:nowrap;">
                    复制
                </button>
            </div>
        </div>

        <?php if ($existing): ?>
        <div style="margin-top:8px;">
            <div style="font-size:12px;color:#999;margin-bottom:6px;">已有短链（最近 10 条）</div>
            <div style="max-height:150px;overflow-y:auto;">
                <table style="width:100%;font-size:11px;border-collapse:collapse;">
                    <tr style="color:#999;text-align:left;">
                        <th style="padding:3px 4px;">短链</th>
                        <th style="padding:3px 4px;">标签</th>
                        <th style="padding:3px 4px;">点击</th>
                        <th style="padding:3px 4px;">使用</th>
                        <th style="padding:3px 4px;">到期</th>
                    </tr>
                    <?php foreach ($existing as $link): ?>
                    <tr style="border-top:1px solid #f0f0f0;">
                        <td style="padding:4px;">
                            <span class="xingxy-copy-existing" data-url="<?php echo esc_attr(home_url('/go/' . $link->share_key)); ?>"
                                  style="color:#667eea;cursor:pointer;text-decoration:underline;" title="点击复制">
                                /go/<?php echo esc_html($link->share_key); ?>
                            </span>
                        </td>
                        <td style="padding:4px;"><?php echo esc_html($link->tag_slug ?: '-'); ?></td>
                        <td style="padding:4px;"><?php echo intval($link->clicks); ?></td>
                        <td style="padding:4px;">
                            <?php
                            echo intval($link->used_count);
                            if ($link->max_uses) {
                                echo '/' . intval($link->max_uses);
                            }
                            ?>
                        </td>
                        <td style="padding:4px;">
                            <?php
                            if ($link->expires_at) {
                                $remaining = strtotime($link->expires_at) - current_time('timestamp');
                                if ($remaining <= 0) {
                                    echo '<span style="color:#e74c3c;">已过期</span>';
                                } elseif ($remaining < 60) {
                                    echo '<span style="color:#e74c3c;">' . $remaining . '秒</span>';
                                } elseif ($remaining < 3600) {
                                    echo '<span style="color:#f39c12;">' . round($remaining / 60) . '分钟</span>';
                                } elseif ($remaining < 86400) {
                                    echo '<span style="color:#f39c12;">' . round($remaining / 3600, 1) . '小时</span>';
                                } else {
                                    echo round($remaining / 86400) . '天';
                                }
                            } else {
                                echo '永久';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
        var nonce   = <?php echo json_encode($nonce); ?>;
        var contentId   = <?php echo json_encode($id); ?>;
        var contentType = <?php echo json_encode($type); ?>;

        var genBtn    = document.getElementById('xingxy-gen-btn');
        var resultBox = document.getElementById('xingxy-share-result');
        var resultUrl = document.getElementById('xingxy-result-url');
        var copyBtn   = document.getElementById('xingxy-copy-btn');

        if (!genBtn) return;

        genBtn.addEventListener('click', function(){
            genBtn.disabled = true;
            genBtn.textContent = '生成中...';

            var fd = new FormData();
            fd.append('action', 'xingxy_create_share_link');
            fd.append('_nonce', nonce);
            fd.append('content_id', contentId);
            fd.append('content_type', contentType);
            fd.append('tag_slug', document.getElementById('xingxy-tag').value.trim());
            fd.append('max_uses', document.getElementById('xingxy-max-uses').value || '0');
            fd.append('expires_sec', document.getElementById('xingxy-expires').value);

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    genBtn.disabled = false;
                    genBtn.textContent = '生成短链';
                    if (resp.success) {
                        resultUrl.value = resp.data.url;
                        resultBox.style.display = 'block';
                    } else {
                        alert('失败：' + (resp.data || '未知错误'));
                    }
                })
                .catch(function(){
                    genBtn.disabled = false;
                    genBtn.textContent = '生成短链';
                    alert('网络错误');
                });
        });

        // 复制新生成的链接
        copyBtn.addEventListener('click', function(){
            resultUrl.select();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(resultUrl.value).then(function(){
                    copyBtn.textContent = '已复制';
                    setTimeout(function(){ copyBtn.textContent = '复制'; }, 1500);
                });
            } else {
                document.execCommand('copy');
                copyBtn.textContent = '已复制';
                setTimeout(function(){ copyBtn.textContent = '复制'; }, 1500);
            }
        });

        // 复制已有链接
        document.querySelectorAll('.xingxy-copy-existing').forEach(function(el){
            el.addEventListener('click', function(){
                var url = this.getAttribute('data-url');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(function(){
                        el.textContent = '已复制!';
                        setTimeout(function(){ el.textContent = el.getAttribute('data-url').replace(/^https?:\/\/[^\/]+/, ''); }, 1200);
                    });
                } else {
                    var tmp = document.createElement('input');
                    tmp.value = url;
                    document.body.appendChild(tmp);
                    tmp.select();
                    document.execCommand('copy');
                    document.body.removeChild(tmp);
                    el.textContent = '已复制!';
                    setTimeout(function(){ el.textContent = el.getAttribute('data-url').replace(/^https?:\/\/[^\/]+/, ''); }, 1200);
                }
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
