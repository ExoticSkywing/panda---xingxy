<?php
/**
 * 追踪短链管理后台
 * 
 * 提供全站短链列表、行内编辑、删除、批量清理功能
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===================================================================
// 注册子菜单（挂到星小雅高级定制下面）
// ===================================================================

add_action('admin_menu', function () {
    add_submenu_page(
        'xingxy-options',           // 父菜单 slug（CSF 在 priority 10 创建）
        '追踪短链管理',              // 页面标题
        '追踪短链',                  // 菜单文字
        'manage_options',            // 权限
        'xingxy-share-links',       // slug
        'xingxy_render_share_links_page'
    );
}, 99);

// ===================================================================
// AJAX：更新短链
// ===================================================================

add_action('wp_ajax_xingxy_update_share_link', function () {
    check_ajax_referer('xingxy_manage_links', '_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'xingxy_share_links';

    $id        = intval($_POST['link_id'] ?? 0);
    $tag_slug  = sanitize_text_field($_POST['tag_slug'] ?? '');
    $max_uses  = intval($_POST['max_uses'] ?? 0) ?: null;
    $expires_at = sanitize_text_field($_POST['expires_at'] ?? '');

    if (!$id) {
        wp_send_json_error('缺少 ID');
    }

    $data = [
        'tag_slug' => $tag_slug ?: null,
        'max_uses' => $max_uses,
    ];
    if ($expires_at === '') {
        $data['expires_at'] = null;
    } elseif ($expires_at) {
        $data['expires_at'] = $expires_at;
    }

    $result = $wpdb->update($table, $data, ['id' => $id]);
    if ($result === false) {
        wp_send_json_error('更新失败：' . $wpdb->last_error);
    }

    wp_send_json_success(['id' => $id]);
});

// ===================================================================
// AJAX：删除短链
// ===================================================================

add_action('wp_ajax_xingxy_delete_share_link', function () {
    check_ajax_referer('xingxy_manage_links', '_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'xingxy_share_links';
    $id    = intval($_POST['link_id'] ?? 0);

    if (!$id) {
        wp_send_json_error('缺少 ID');
    }

    $wpdb->delete($table, ['id' => $id]);
    wp_send_json_success(['id' => $id]);
});

// ===================================================================
// AJAX：批量清理过期/用尽链接
// ===================================================================

add_action('wp_ajax_xingxy_purge_share_links', function () {
    check_ajax_referer('xingxy_manage_links', '_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'xingxy_share_links';
    $now   = current_time('mysql');

    $deleted = $wpdb->query(
        "DELETE FROM $table WHERE (expires_at IS NOT NULL AND expires_at < '$now') OR (max_uses IS NOT NULL AND used_count >= max_uses)"
    );

    wp_send_json_success(['deleted' => $deleted]);
});

// ===================================================================
// 渲染管理页面
// ===================================================================

function xingxy_render_share_links_page() {
    if (!current_user_can('manage_options')) {
        wp_die('权限不足');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'xingxy_share_links';
    $now   = current_time('mysql');

    // 分页
    $per_page = 30;
    $paged    = max(1, intval($_GET['paged'] ?? 1));
    $offset   = ($paged - 1) * $per_page;
    $total    = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $pages    = ceil($total / $per_page);

    // 筛选
    $filter = sanitize_text_field($_GET['filter'] ?? '');
    $where  = '1=1';
    if ($filter === 'expired') {
        $where = "expires_at IS NOT NULL AND expires_at < '$now'";
    } elseif ($filter === 'exhausted') {
        $where = "max_uses IS NOT NULL AND used_count >= max_uses";
    } elseif ($filter === 'active') {
        $where = "(expires_at IS NULL OR expires_at >= '$now') AND (max_uses IS NULL OR used_count < max_uses)";
    }

    $links = $wpdb->get_results(
        "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"
    );
    $total_filtered = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");

    $nonce    = wp_create_nonce('xingxy_manage_links');
    $ajax_url = admin_url('admin-ajax.php');
    $page_url = admin_url('admin.php?page=xingxy-share-links');

    // 统计
    $stats = [
        'total'     => $total,
        'active'    => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE (expires_at IS NULL OR expires_at >= '$now') AND (max_uses IS NULL OR used_count < max_uses)"),
        'expired'   => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE expires_at IS NOT NULL AND expires_at < '$now'"),
        'exhausted' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE max_uses IS NOT NULL AND used_count >= max_uses"),
    ];

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🔗 追踪短链管理</h1>
        <hr class="wp-header-end">

        <!-- 统计卡片 -->
        <div style="display:flex;gap:12px;margin:16px 0;">
            <?php
            $cards = [
                ['label' => '全部',  'count' => $stats['total'],     'color' => '#3b82f6', 'f' => ''],
                ['label' => '有效',  'count' => $stats['active'],    'color' => '#22c55e', 'f' => 'active'],
                ['label' => '已过期','count' => $stats['expired'],   'color' => '#ef4444', 'f' => 'expired'],
                ['label' => '已用尽','count' => $stats['exhausted'], 'color' => '#f59e0b', 'f' => 'exhausted'],
            ];
            foreach ($cards as $c):
                $active_style = ($filter === $c['f']) ? 'box-shadow:0 0 0 2px ' . $c['color'] . ';' : '';
            ?>
                <a href="<?php echo esc_url(add_query_arg('filter', $c['f'], $page_url)); ?>"
                   style="text-decoration:none;flex:1;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;<?php echo $active_style; ?>">
                    <div style="font-size:13px;color:#6b7280;"><?php echo $c['label']; ?></div>
                    <div style="font-size:28px;font-weight:700;color:<?php echo $c['color']; ?>;margin-top:4px;">
                        <?php echo $c['count']; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- 操作栏 -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div>
                <span style="color:#6b7280;font-size:13px;">
                    共 <?php echo $total_filtered; ?> 条
                    <?php if ($filter): ?>
                        <a href="<?php echo esc_url($page_url); ?>" style="margin-left:8px;">← 返回全部</a>
                    <?php endif; ?>
                </span>
            </div>
            <div>
                <button type="button" id="xingxy-purge-btn" class="button"
                        style="color:#ef4444;border-color:#ef4444;">
                    🗑 批量清理过期/用尽
                </button>
            </div>
        </div>

        <!-- 数据表格 -->
        <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden;">
            <thead>
                <tr>
                    <th style="width:40px;">ID</th>
                    <th style="width:90px;">短链 Key</th>
                    <th style="width:90px;">标签</th>
                    <th>目标内容</th>
                    <th style="width:70px;">点击</th>
                    <th style="width:90px;">使用/上限</th>
                    <th style="width:130px;">过期时间</th>
                    <th style="width:80px;">状态</th>
                    <th style="width:130px;">创建时间</th>
                    <th style="width:100px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($links)): ?>
                    <tr><td colspan="10" style="text-align:center;padding:30px;color:#999;">暂无数据</td></tr>
                <?php else: ?>
                    <?php foreach ($links as $link):
                        $is_expired   = $link->expires_at && strtotime($link->expires_at) < current_time('timestamp');
                        $is_exhausted = $link->max_uses && $link->used_count >= $link->max_uses;
                        $short_url    = home_url('/go/' . $link->share_key);

                        if ($is_expired) {
                            $status_html = '<span style="background:#fef2f2;color:#ef4444;padding:2px 8px;border-radius:10px;font-size:12px;">已过期</span>';
                        } elseif ($is_exhausted) {
                            $status_html = '<span style="background:#fffbeb;color:#f59e0b;padding:2px 8px;border-radius:10px;font-size:12px;">已用尽</span>';
                        } else {
                            $status_html = '<span style="background:#f0fdf4;color:#22c55e;padding:2px 8px;border-radius:10px;font-size:12px;">有效</span>';
                        }

                        // 剩余时间
                        $remaining_text = '永久';
                        if ($link->expires_at) {
                            $remaining = strtotime($link->expires_at) - current_time('timestamp');
                            if ($remaining <= 0) {
                                $remaining_text = '已过期';
                            } elseif ($remaining < 60) {
                                $remaining_text = $remaining . '秒';
                            } elseif ($remaining < 3600) {
                                $remaining_text = round($remaining / 60) . '分钟';
                            } elseif ($remaining < 86400) {
                                $remaining_text = round($remaining / 3600, 1) . '小时';
                            } else {
                                $remaining_text = round($remaining / 86400) . '天';
                            }
                        }

                        // 截短目标 URL
                        $display_url = str_replace(home_url(), '', $link->content_url);
                        if (strlen($display_url) > 40) {
                            $display_url = substr($display_url, 0, 37) . '...';
                        }
                    ?>
                    <tr id="link-row-<?php echo $link->id; ?>" data-id="<?php echo $link->id; ?>">
                        <td><?php echo $link->id; ?></td>
                        <td>
                            <code style="font-size:12px;cursor:pointer;" title="点击复制"
                                  onclick="navigator.clipboard.writeText('<?php echo esc_js($short_url); ?>').then(function(){this.style.color='#22c55e';setTimeout(function(){this.style.color='';}.bind(this),800);}.bind(this));">
                                <?php echo esc_html($link->share_key); ?>
                            </code>
                        </td>
                        <td>
                            <input type="text" class="xsl-tag" value="<?php echo esc_attr($link->tag_slug); ?>"
                                   style="width:80px;font-size:12px;padding:2px 4px;border:1px solid transparent;background:transparent;border-radius:4px;"
                                   onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';"
                                   onblur="this.style.borderColor='transparent';this.style.background='transparent';">
                        </td>
                        <td>
                            <a href="<?php echo esc_url($link->content_url); ?>" target="_blank"
                               style="font-size:12px;color:#6b7280;text-decoration:none;" title="<?php echo esc_attr($link->content_url); ?>">
                                <?php echo esc_html($display_url); ?>
                            </a>
                        </td>
                        <td style="font-weight:600;"><?php echo intval($link->clicks); ?></td>
                        <td>
                            <span style="font-weight:600;"><?php echo intval($link->used_count); ?></span>
                            /
                            <input type="number" class="xsl-max" value="<?php echo $link->max_uses ? intval($link->max_uses) : ''; ?>"
                                   placeholder="∞" min="0"
                                   style="width:45px;font-size:12px;padding:2px 4px;border:1px solid transparent;background:transparent;border-radius:4px;text-align:center;"
                                   onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';"
                                   onblur="this.style.borderColor='transparent';this.style.background='transparent';">
                        </td>
                        <td>
                            <input type="text" class="xsl-expires" value="<?php echo $link->expires_at ? esc_attr($link->expires_at) : ''; ?>"
                                   placeholder="永久"
                                   style="width:120px;font-size:12px;padding:2px 4px;border:1px solid transparent;background:transparent;border-radius:4px;"
                                   onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';"
                                   onblur="this.style.borderColor='transparent';this.style.background='transparent';">
                            <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                                <?php echo $remaining_text; ?>
                            </div>
                        </td>
                        <td><?php echo $status_html; ?></td>
                        <td style="font-size:12px;color:#9ca3af;"><?php echo $link->created_at; ?></td>
                        <td>
                            <button type="button" class="button button-small xsl-save" title="保存修改"
                                    style="margin-right:4px;">💾</button>
                            <button type="button" class="button button-small xsl-delete" title="删除"
                                    style="color:#ef4444;">✕</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 分页 -->
        <?php if ($pages > 1): ?>
        <div class="tablenav bottom" style="margin-top:12px;">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_filtered; ?> 条</span>
                <?php
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ]);
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function(){
        var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
        var nonce   = <?php echo json_encode($nonce); ?>;

        // 保存
        document.querySelectorAll('.xsl-save').forEach(function(btn){
            btn.addEventListener('click', function(){
                var row = this.closest('tr');
                var id  = row.dataset.id;
                var fd  = new FormData();
                fd.append('action', 'xingxy_update_share_link');
                fd.append('_nonce', nonce);
                fd.append('link_id', id);
                fd.append('tag_slug', row.querySelector('.xsl-tag').value.trim());
                fd.append('max_uses', row.querySelector('.xsl-max').value || '0');
                fd.append('expires_at', row.querySelector('.xsl-expires').value.trim());

                btn.disabled = true;
                btn.textContent = '...';
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        btn.disabled = false;
                        btn.textContent = '💾';
                        if (resp.success) {
                            btn.textContent = '✓';
                            btn.style.color = '#22c55e';
                            setTimeout(function(){ btn.textContent = '💾'; btn.style.color = ''; }, 1200);
                        } else {
                            alert('保存失败：' + (resp.data || '未知错误'));
                        }
                    })
                    .catch(function(){ btn.disabled = false; btn.textContent = '💾'; alert('网络错误'); });
            });
        });

        // 删除
        document.querySelectorAll('.xsl-delete').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!confirm('确定删除此短链？')) return;
                var row = this.closest('tr');
                var id  = row.dataset.id;
                var fd  = new FormData();
                fd.append('action', 'xingxy_delete_share_link');
                fd.append('_nonce', nonce);
                fd.append('link_id', id);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp.success) {
                            row.style.transition = 'opacity .3s';
                            row.style.opacity = '0';
                            setTimeout(function(){ row.remove(); }, 300);
                        } else {
                            alert('删除失败：' + (resp.data || '未知错误'));
                        }
                    });
            });
        });

        // 批量清理
        document.getElementById('xingxy-purge-btn').addEventListener('click', function(){
            if (!confirm('将删除所有已过期和已用尽的短链，确定？')) return;
            var btn = this;
            btn.disabled = true;
            btn.textContent = '清理中...';
            var fd = new FormData();
            fd.append('action', 'xingxy_purge_share_links');
            fd.append('_nonce', nonce);

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    btn.disabled = false;
                    btn.textContent = '🗑 批量清理过期/用尽';
                    if (resp.success) {
                        alert('已清理 ' + resp.data.deleted + ' 条记录');
                        location.reload();
                    } else {
                        alert('清理失败');
                    }
                });
        });
    })();
    </script>
    <?php
}
