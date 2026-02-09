<?php
/**
 * Xingxy 卡密编辑功能
 * 
 * 为卡密管理添加编辑和批量修改功能
 * 通过 admin_footer 钩子注入到卡密管理页面
 * 
 * @package Xingxy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 在卡密管理页面注入编辑功能
 */
add_action('admin_footer', 'xingxy_card_edit_scripts');
function xingxy_card_edit_scripts() {
    // 只在卡密管理页面加载
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'zibpay_charge_card_page') === false) {
        return;
    }
    
    // 只在列表页加载（非添加/导出页）
    if (!empty($_GET['tab'])) {
        return;
    }
    ?>
    <style>
    .xingxy-card-edit-btn {
        color: #2271b1 !important;
        cursor: pointer;
        margin-left: 5px;
    }
    .xingxy-card-edit-btn:hover {
        color: #135e96 !important;
        text-decoration: underline;
    }
    .xingxy-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.6);
        z-index: 99999;
        display: none;
        justify-content: center;
        align-items: center;
    }
    .xingxy-modal-overlay.active {
        display: flex;
    }
    .xingxy-modal {
        background: #fff;
        border-radius: 8px;
        width: 500px;
        max-width: 90%;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }
    .xingxy-modal-header {
        padding: 15px 20px;
        border-bottom: 1px solid #ddd;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .xingxy-modal-header h3 {
        margin: 0;
        font-size: 16px;
    }
    .xingxy-modal-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: #666;
    }
    .xingxy-modal-close:hover {
        color: #333;
    }
    .xingxy-modal-body {
        padding: 20px;
    }
    .xingxy-modal-body .form-group {
        margin-bottom: 15px;
    }
    .xingxy-modal-body label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
    }
    .xingxy-modal-body input[type="text"],
    .xingxy-modal-body textarea {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .xingxy-modal-body textarea {
        min-height: 80px;
        resize: vertical;
    }
    .xingxy-modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #ddd;
        text-align: right;
    }
    .xingxy-modal-footer .button {
        margin-left: 10px;
    }
    .xingxy-batch-edit-option {
        padding: 5px 10px;
        cursor: pointer;
    }
    .xingxy-batch-edit-option:hover {
        background: #f0f0f0;
    }
    </style>
    
    <!-- 单条编辑弹窗 -->
    <div id="xingxy-edit-modal" class="xingxy-modal-overlay">
        <div class="xingxy-modal">
            <div class="xingxy-modal-header">
                <h3>编辑卡密</h3>
                <button class="xingxy-modal-close">&times;</button>
            </div>
            <div class="xingxy-modal-body">
                <input type="hidden" id="xingxy-edit-id">
                <div class="form-group">
                    <label>卡号</label>
                    <input type="text" id="xingxy-edit-card">
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="text" id="xingxy-edit-password">
                </div>
                <div class="form-group">
                    <label>备注</label>
                    <input type="text" id="xingxy-edit-other">
                </div>
            </div>
            <div class="xingxy-modal-footer">
                <button class="button xingxy-modal-close">取消</button>
                <button class="button button-primary" id="xingxy-edit-save">保存</button>
            </div>
        </div>
    </div>
    
    <!-- 批量修改弹窗 -->
    <div id="xingxy-batch-modal" class="xingxy-modal-overlay">
        <div class="xingxy-modal">
            <div class="xingxy-modal-header">
                <h3>批量修改备注</h3>
                <button class="xingxy-modal-close">&times;</button>
            </div>
            <div class="xingxy-modal-body">
                <div class="form-group">
                    <label>已选中 <span id="xingxy-batch-count">0</span> 条卡密</label>
                </div>
                <div class="form-group">
                    <label>新备注</label>
                    <input type="text" id="xingxy-batch-other" placeholder="输入新的备注内容">
                </div>
            </div>
            <div class="xingxy-modal-footer">
                <button class="button xingxy-modal-close">取消</button>
                <button class="button button-primary" id="xingxy-batch-save">批量保存</button>
            </div>
        </div>
    </div>
    
    <script>
    (function($) {
        'use strict';
        
        // 在备注列添加编辑按钮
        function addEditButtons() {
            $('table.widefat tbody tr, .wp-list-table tbody tr').each(function() {
                var $row = $(this);
                var $checkbox = $row.find('input[type="checkbox"][name="action_id[]"]');
                if (!$checkbox.length) return;
                
                var id = $checkbox.val();
                var $tds = $row.find('td');
                
                // 表格结构：th(checkbox) + 7个td (卡号、密码、类型、创建时间、更新时间、状态、备注)
                // td索引：0=卡号, 1=密码, 2=类型, 3=创建时间, 4=更新时间, 5=状态, 6=备注
                var card = $tds.eq(0).find('[data-clipboard-text]').attr('data-clipboard-text') || '';
                var password = $tds.eq(1).find('[data-clipboard-text]').attr('data-clipboard-text') || '';
                var $otherCell = $tds.eq(6);
                var other = $otherCell.find('a').first().text().trim() || $otherCell.text().trim() || '';
                
                // 添加编辑按钮
                if (!$row.find('.xingxy-card-edit-btn').length) {
                    var $editBtn = $('<a class="xingxy-card-edit-btn">[编辑]</a>');
                    $editBtn.data({
                        id: id,
                        card: card,
                        password: password,
                        other: other
                    });
                    $otherCell.append(' ').append($editBtn);
                }
            });
        }
        
        // 在批量操作添加"批量修改备注"选项
        function addBatchOption() {
            var $select = $('select[name="action"]');
            if ($select.length && !$select.find('option[value="batch_edit"]').length) {
                $select.append('<option value="batch_edit">批量修改备注</option>');
            }
        }
        
        // 打开单条编辑弹窗
        $(document).on('click', '.xingxy-card-edit-btn', function(e) {
            e.preventDefault();
            var data = $(this).data();
            $('#xingxy-edit-id').val(data.id);
            $('#xingxy-edit-card').val(data.card);
            $('#xingxy-edit-password').val(data.password);
            $('#xingxy-edit-other').val(data.other);
            $('#xingxy-edit-modal').addClass('active');
        });
        
        // 关闭弹窗
        $(document).on('click', '.xingxy-modal-close, .xingxy-modal-overlay', function(e) {
            if (e.target === this) {
                $('.xingxy-modal-overlay').removeClass('active');
            }
        });
        
        // 保存单条编辑
        $('#xingxy-edit-save').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('保存中...');
            
            $.post(ajaxurl, {
                action: 'xingxy_card_edit',
                id: $('#xingxy-edit-id').val(),
                card: $('#xingxy-edit-card').val(),
                password: $('#xingxy-edit-password').val(),
                other: $('#xingxy-edit-other').val(),
                _wpnonce: '<?php echo wp_create_nonce('xingxy_card_edit'); ?>'
            }, function(res) {
                if (res.success) {
                    alert('保存成功！');
                    location.reload();
                } else {
                    alert('保存失败：' + (res.data || '未知错误'));
                }
                $btn.prop('disabled', false).text('保存');
            }).fail(function() {
                alert('请求失败，请重试');
                $btn.prop('disabled', false).text('保存');
            });
        });
        
        // 批量操作拦截
        $(document).on('click', '.bulkactions input.button.action', function(e) {
            var $select = $(this).siblings('select[name="action"]');
            var action = $select.val();
            if (action === 'batch_edit') {
                e.preventDefault();
                var ids = [];
                $('input[name="action_id[]"]:checked').each(function() {
                    ids.push($(this).val());
                });
                if (ids.length === 0) {
                    alert('请先选择要修改的卡密');
                    return;
                }
                $('#xingxy-batch-count').text(ids.length);
                $('#xingxy-batch-modal').data('ids', ids).addClass('active');
            }
        });
        
        // 批量保存
        $('#xingxy-batch-save').on('click', function() {
            var $btn = $(this);
            var ids = $('#xingxy-batch-modal').data('ids');
            var other = $('#xingxy-batch-other').val();
            
            if (!other) {
                alert('请输入新的备注内容');
                return;
            }
            
            $btn.prop('disabled', true).text('保存中...');
            
            $.post(ajaxurl, {
                action: 'xingxy_card_batch_edit',
                ids: ids,
                other: other,
                _wpnonce: '<?php echo wp_create_nonce('xingxy_card_batch_edit'); ?>'
            }, function(res) {
                if (res.success) {
                    alert('批量修改成功！共修改 ' + res.data.count + ' 条');
                    location.reload();
                } else {
                    alert('保存失败：' + (res.data || '未知错误'));
                }
                $btn.prop('disabled', false).text('批量保存');
            }).fail(function() {
                alert('请求失败，请重试');
                $btn.prop('disabled', false).text('批量保存');
            });
        });
        
        // 初始化
        $(document).ready(function() {
            addEditButtons();
            addBatchOption();
        });
        
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX: 单条编辑
 */
add_action('wp_ajax_xingxy_card_edit', 'xingxy_ajax_card_edit');
function xingxy_ajax_card_edit() {
    check_ajax_referer('xingxy_card_edit', '_wpnonce');
    
    if (!is_super_admin()) {
        wp_send_json_error('权限不足');
    }
    
    $id = intval($_POST['id']);
    if (!$id) {
        wp_send_json_error('ID 无效');
    }
    
    $data = array(
        'id' => $id,
        'card' => sanitize_text_field($_POST['card']),
        'password' => sanitize_text_field($_POST['password']),
        'other' => sanitize_text_field($_POST['other']),
    );
    
    $result = ZibCardPass::update($data);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('更新失败');
    }
}

/**
 * AJAX: 批量修改
 */
add_action('wp_ajax_xingxy_card_batch_edit', 'xingxy_ajax_card_batch_edit');
function xingxy_ajax_card_batch_edit() {
    check_ajax_referer('xingxy_card_batch_edit', '_wpnonce');
    
    if (!is_super_admin()) {
        wp_send_json_error('权限不足');
    }
    
    $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
    $other = sanitize_text_field($_POST['other']);
    
    if (empty($ids)) {
        wp_send_json_error('未选择卡密');
    }
    
    $count = 0;
    foreach ($ids as $id) {
        $result = ZibCardPass::update(array(
            'id' => $id,
            'other' => $other,
        ));
        if ($result) {
            $count++;
        }
    }
    
    wp_send_json_success(array('count' => $count));
}
