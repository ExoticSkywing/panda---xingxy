<?php
/**
 * Template name: 星盟-发布商品
 * Description:   合作方前台发布商城商品的页面模板
 * 
 * @package Xingxy
 * @subpackage StarAlliance
 */

// 引入核心
require_once get_theme_file_path('/inc/code/require.php');
require_once get_theme_file_path('/inc/code/file.php');

$cuid = get_current_user_id();

// 未登录跳转
if (!$cuid) {
    wp_safe_redirect(home_url());
    exit;
}

// 权限检查
if (!xingxy_can_publish_product($cuid)) {
    get_header();
    echo '<main role="main" class="container"><div class="zib-widget" style="padding:60px 20px;text-align:center;">';
    echo '<div class="em12 mb20">🚫 暂无发布商品的权限</div>';
    echo '<p class="muted-2-color">请联系管理员开通合作方权限</p>';
    echo '</div></main>';
    get_footer();
    exit;
}

// 编辑模式
$edit_id   = !empty($_REQUEST['edit']) ? (int) $_REQUEST['edit'] : 0;
$edit_post = null;
$is_edit   = false;

if ($edit_id) {
    $edit_post = get_post($edit_id);
    if (empty($edit_post->ID) || $edit_post->post_type !== 'shop_product' || !xingxy_can_edit_product($edit_post, $cuid)) {
        wp_safe_redirect(home_url());
        exit;
    }
    $is_edit = true;
}

// 准备表单数据
$in = array(
    'ID'           => '',
    'post_title'   => '',
    'post_content' => '',
    'desc'         => '',
    'price'        => '',
    'cover_ids'    => '',
    'shipping_type'=> 'manual',
    'card_pass_key'=> '',
    'tags'         => '',
    'post_status'  => '',
);

if ($is_edit) {
    $in['ID']           = $edit_post->ID;
    $in['post_title']   = $edit_post->post_title;
    $in['post_content'] = $edit_post->post_content;
    $in['post_status']  = $edit_post->post_status;
    
    $config = get_post_meta($edit_post->ID, 'product_config', true);
    if (is_array($config)) {
        $in['desc']          = isset($config['desc']) ? $config['desc'] : '';
        $in['price']         = isset($config['start_price']) ? $config['start_price'] : '';
        $in['cover_ids']     = isset($config['cover_images']) ? $config['cover_images'] : '';
        $in['shipping_type'] = isset($config['shipping_type']) ? $config['shipping_type'] : 'manual';
        if (isset($config['auto_delivery']['card_pass_key'])) {
            $in['card_pass_key'] = $config['auto_delivery']['card_pass_key'];
        }
    }
    
    // 标签
    $tags = get_the_terms($edit_post->ID, 'shop_tag');
    if ($tags && !is_wp_error($tags)) {
        $in['tags'] = implode(', ', array_column((array) $tags, 'name'));
    }
}

// 封面图片预览数据
$cover_preview_html = '';
if ($in['cover_ids']) {
    $ids = explode(',', $in['cover_ids']);
    foreach ($ids as $aid) {
        $aid = (int) trim($aid);
        if ($aid) {
            $img_url = wp_get_attachment_image_url($aid, 'medium');
            if ($img_url) {
                $cover_preview_html .= '<div class="xingxy-gallery-item" data-id="' . $aid . '">';
                $cover_preview_html .= '<img src="' . esc_url($img_url) . '" alt="">';
                $cover_preview_html .= '<span class="xingxy-gallery-remove" title="移除">&times;</span>';
                $cover_preview_html .= '</div>';
            }
        }
    }
}

// 编辑器按钮 —— 复用文章发布页的 TinyMCE 自定义工具栏
// 图片上传
if (zib_current_user_can('new_post_upload_img')) {
    add_filter('tinymce_upload_img', '__return_true');
}
// 视频上传
if (zib_current_user_can('new_post_upload_video')) {
    add_filter('tinymce_upload_video', '__return_true');
}
// 文件上传
if (zib_current_user_can('new_post_upload_file')) {
    add_filter('tinymce_upload_file', '__return_true');
}
// 嵌入视频
if (zib_current_user_can('new_post_iframe_video')) {
    add_filter('tinymce_iframe_video', '__return_true');
}
// 隐藏内容
if (zib_current_user_can('new_post_hide')) {
    add_filter('tinymce_hide', '__return_true');
}

// 不显示悬浮按钮
remove_action('wp_footer', 'zib_float_right');
remove_action('wp_footer', 'zib_footer_tabbar');

// 建议搜索引擎不抓取
add_filter('wp_robots', 'zib_robots_no_robots');

// 强制启用 sidebar 两栏布局（Zibll 默认根据 zib_is_show_sidebar 决定）
add_filter('zib_is_show_sidebar', '__return_true');

// 加载编辑文章的 CSS
add_filter('featured_image_edit', '__return_true');

// 修复暗色模式编辑器文字颜色
// Zibll 原生只对 editor_id='post_content' 注入暗色 body_class，
// 商品编辑器用的是 'product_content'，需要扩展支持
add_filter('tiny_mce_before_init', function($mceInit, $editor_id) {
    if ('product_content' === $editor_id) {
        $mceInit['body_class'] .= ' ' . zib_get_theme_mode();
    }
    return $mceInit;
}, 10, 2);


/**
 * 递归渲染分类复选框
 */
if (!function_exists('xingxy_render_term_checkboxes')) {
function xingxy_render_term_checkboxes($terms, $checked_ids, $taxonomy, $depth = 0) {
    foreach ($terms as $term) {
        $indent = $depth > 0 ? ' style="margin-left:' . ($depth * 20) . 'px;"' : '';
        $checked = in_array($term->term_id, $checked_ids) ? ' checked="checked"' : '';
        echo '<div' . $indent . '><label class="muted-color font-normal pointer">';
        echo '<input value="' . $term->term_id . '"' . $checked . ' type="checkbox" name="shop_cat[]">';
        echo '<span class="ml6">' . esc_html($term->name) . '</span>';
        echo '</label></div>';
        
        // 递归子分类
        $children = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'parent'     => $term->term_id,
        ));
        if ($children && !is_wp_error($children)) {
            xingxy_render_term_checkboxes($children, $checked_ids, $taxonomy, $depth + 1);
        }
    }
}
} // end function_exists check

get_header();
?>
<main role="main" class="container">
        <div class="content-wrap newposts-wrap">
            <div class="content-layout">
                <div class="zib-widget full-widget-sm editor-main-box" style="min-height:60vh;">
                    
                    <?php
                    // 非编辑模式时展示"我的商品"完整管理列表
                    if (!$is_edit):
                        $my_products = new WP_Query(array(
                            'post_type'      => 'shop_product',
                            'post_status'    => array('publish', 'pending', 'draft'),
                            'author'         => $cuid,
                            'posts_per_page' => -1,
                            'orderby'        => 'modified',
                            'order'          => 'DESC',
                        ));
                        
                        // 保存发布页 URL（循环中 $post 会被覆盖）
                        $page_url = get_permalink();
                        
                        if ($my_products->have_posts()):
                    ?>
                    <div class="mb20" id="xingxy-my-products">
                        <div class="flex ac jsb mb10">
                            <span class="title-theme">我的商品 <span class="muted-3-color em09">(<?php echo $my_products->found_posts; ?>个)</span></span>
                        </div>
                        <?php while ($my_products->have_posts()): $my_products->the_post();
                            $p_id = get_the_ID();
                            $p_status = get_post_status();
                            $p_edit_url = add_query_arg('edit', $p_id, $page_url);
                            
                            // 状态标签
                            $s_text = '';
                            $s_class = '';
                            switch ($p_status) {
                                case 'pending':  $s_text = '待审核'; $s_class = 'c-yellow'; break;
                                case 'draft':    $s_text = '草稿';   $s_class = 'muted-2-color'; break;
                                case 'publish':  $s_text = '已上架'; $s_class = 'c-green'; break;
                            }
                            
                            // 销量
                            $sales = (int) get_post_meta($p_id, 'sales_volume', true);
                        ?>
                        <div class="flex ac jsb padding-h8 border-bottom">
                            <div class="flex1 text-ellipsis mr10">
                                <a href="<?php echo esc_url($p_edit_url); ?>" class="muted-color"><?php the_title(); ?></a>
                            </div>
                            <div class="flex ac flex0">
                                <?php if ($sales > 0): ?>
                                <span class="muted-3-color em09 mr10"><?php echo $sales; ?>售</span>
                                <?php endif; ?>
                                <span class="badg badg-sm mr6 <?php echo $s_class; ?>"><?php echo $s_text; ?></span>
                                <a href="<?php echo esc_url($p_edit_url); ?>" class="em09 c-blue" title="编辑"><i class="fa fa-pencil"></i></a>
                                <?php if ($p_status === 'publish'): ?>
                                <a href="<?php echo get_permalink($p_id); ?>" class="em09 ml6 muted-2-color" target="_blank" title="查看"><i class="fa fa-external-link"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; wp_reset_postdata(); ?>
                    </div>
                    
                    <div class="text-center mt10 mb20" style="padding:12px 0;border-top:2px dashed var(--muted-3-color,#555);">
                        <span class="em12" style="color:var(--color-blue,#2193f7);"><i class="fa fa-plus-circle mr6"></i>发布新商品</span>
                    </div>
                    <?php
                        endif; // have_posts
                    endif; // !$is_edit
                    ?>
                    
                    <!-- 商品名称 -->
                    <div class="relative newposts-title">
                        <textarea type="text" class="line-form-input input-lg new-title" name="product_title" tabindex="1" rows="1" autoHeight="true" maxHeight="78" placeholder="请输入商品名称"><?php echo esc_attr($in['post_title']); ?></textarea>
                        <i class="line-form-line"></i>
                    </div>
                    
                    <!-- 商品简介 -->
                    <div class="mt10 mb20">
                        <textarea class="form-control" name="product_desc" rows="2" placeholder="一句话介绍商品（选填）" tabindex="2"><?php echo esc_textarea($in['desc']); ?></textarea>
                    </div>
                    
                    <!-- 商品详情（TinyMCE 编辑器） -->
                    <?php
                    $editor_settings = array(
                        'textarea_rows'  => 15,
                        'editor_height'  => (wp_is_mobile() ? 350 : 400),
                        'media_buttons'  => false,
                        'default_editor' => 'tinymce',
                        'quicktags'      => false,
                        'editor_css'     => '<link rel="stylesheet" href="' . ZIB_TEMPLATE_DIRECTORY_URI . '/css/new-posts.min.css?ver=' . THEME_VERSION . '" type="text/css">',
                        'teeny'          => false,
                        'tinymce'        => array(
                            'placeholder' => '请输入商品详情描述',
                        ),
                    );
                    wp_editor($in['post_content'], 'product_content', $editor_settings);
                    ?>
                    
                    <?php if ($is_edit): ?>
                    <div class="em09 flex ac hh mt10">
                        <span class="view-btn mr6 mt6">
                            <a class="but c-blue" href="<?php echo get_permalink($edit_post); ?>"><i class="fa fa-eye"></i> 预览商品</a>
                        </span>
                        <span class="modified-time mt6">
                            <span class="badg">最后保存：<?php echo $edit_post->post_modified; ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 侧边栏 -->
        <div class="sidebar show-sidebar">
            
            <!-- 封面图片 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">商品封面 <span class="muted-3-color em09">（必填）</span></div>
                <div id="xingxy-gallery-container">
                    <div id="xingxy-gallery-preview" class="xingxy-gallery-grid">
                        <?php echo $cover_preview_html; ?>
                    </div>
                    <input type="hidden" name="cover_image_ids" id="cover_image_ids" value="<?php echo esc_attr($in['cover_ids']); ?>">
                    <button type="button" id="xingxy-gallery-btn" class="but hollow c-blue mt10" style="width:100%;">
                        <i class="fa fa-plus mr6"></i>选择/上传图片
                    </button>
                    <p class="muted-3-color em09 mt6">正方形图片效果最佳，可多选</p>
                </div>
            </div>
            
            <!-- 商品分类 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">商品分类 <span class="muted-3-color em09">（必填）</span></div>
                <div class="mini-scrollbar" style="max-height:200px;overflow-y:auto;">
                    <?php
                    $checked_cat_ids = array();
                    if ($is_edit) {
                        $terms = get_the_terms($edit_post->ID, 'shop_cat');
                        if ($terms && !is_wp_error($terms)) {
                            $checked_cat_ids = array_column((array) $terms, 'term_id');
                        }
                    }
                    $all_cats = get_terms(array(
                        'taxonomy'   => 'shop_cat',
                        'hide_empty' => false,
                        'parent'     => 0,
                    ));
                    if ($all_cats && !is_wp_error($all_cats)) {
                        xingxy_render_term_checkboxes($all_cats, $checked_cat_ids, 'shop_cat');
                    } else {
                        echo '<p class="muted-3-color em09">暂无可选分类</p>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- 商品标签 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">商品标签</div>
                <textarea class="form-control" rows="2" name="product_tags" placeholder="每个标签用逗号隔开" tabindex="5"><?php echo esc_textarea($in['tags']); ?></textarea>
            </div>
            
            <!-- 价格设置 -->
            <div class="zib-widget mb10-sm">
                <div class="title-theme mb10">价格设置 <span class="muted-3-color em09">（必填）</span></div>
                <div class="flex ab">
                    <div class="muted-color mb6 flex0">
                        <i class="fa fa-rmb mr6"></i>商品价格
                    </div>
                    <input type="number" name="product_price" value="<?php echo esc_attr($in['price']); ?>" step="0.01" min="0" style="padding: 0;" class="line-form-input em2x key-color text-right" placeholder="0.00" tabindex="3">
                    <i class="line-form-line"></i>
                </div>
                <p class="muted-3-color em09 mt6">实际售价，管理员可在后台调整 VIP 价格等</p>
            </div>
            
            <!-- 发货方式 -->
            <div class="zib-widget mb10-sm dependency-box">
                <div class="title-theme mb10">发货方式</div>
                <div>
                    <label class="badg p2-10 mr10 pointer">
                        <input type="radio" name="shipping_type" value="auto" <?php checked($in['shipping_type'], 'auto'); ?>> 自动发货（卡密）
                    </label>
                    <label class="badg p2-10 mr10 pointer">
                        <input type="radio" name="shipping_type" value="manual" <?php checked($in['shipping_type'], 'manual'); ?>> 手动发货
                    </label>
                </div>
                
                <!-- 卡密备注（自动发货时显示） -->
                <div id="xingxy-card-pass-box" class="mt10" style="<?php echo $in['shipping_type'] !== 'auto' ? 'display:none;' : ''; ?>">
                    <p class="muted-3-color em09">卡密备注关键词（用于匹配已创建的卡密）</p>
                    <input type="text" class="form-control" name="card_pass_key" value="<?php echo esc_attr($in['card_pass_key']); ?>" placeholder="输入卡密备注">
                    <p class="muted-3-color em09 mt6">请提前在后台创建好卡密，此处填写备注用于匹配</p>
                </div>
            </div>
            
            <!-- 提交按钮 -->
            <div class="zib-widget">
                <div class="text-center">
                    <p class="separator muted-3-color theme-box">准备好了吗？</p>
                    <input type="hidden" name="product_id" value="<?php echo (int) $in['ID']; ?>">
                    <div class="but-average">
                        <?php if ($in['post_status'] !== 'publish' && $in['post_status'] !== 'pending'): ?>
                        <button type="button" class="but jb-green xingxy-product-submit padding-lg" data-action="product_draft">
                            <i class="fa fa-fw fa-dot-circle-o"></i>保存草稿
                        </button>
                        <?php endif; ?>
                        <button type="button" class="but jb-blue xingxy-product-submit padding-lg ml10" data-action="product_save">
                            <i class="fa fa-fw fa-check-square-o"></i>提交<?php echo ($in['post_status'] === 'publish' || $in['post_status'] === 'pending') ? '保存' : '审核'; ?>
                        </button>
                    </div>
                    <?php if (!is_super_admin()): ?>
                    <p class="em09 muted-3-color mt10">提交后需等待管理员审核通过</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
</main>

<script>
jQuery(function($) {
    // Gallery 上传
    $('#xingxy-gallery-btn').on('click', function(e) {
        e.preventDefault();
        var frame = wp.media({
            title: '选择商品封面图片',
            button: { text: '使用选中的图片' },
            multiple: true,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            var selection = frame.state().get('selection');
            var ids = $('#cover_image_ids').val() ? $('#cover_image_ids').val().split(',') : [];
            selection.each(function(attachment) {
                var data = attachment.toJSON();
                if (ids.indexOf(String(data.id)) === -1) {
                    ids.push(data.id);
                    var src = data.sizes && data.sizes.medium ? data.sizes.medium.url : data.url;
                    var html = '<div class="xingxy-gallery-item" data-id="' + data.id + '">';
                    html += '<img src="' + src + '" alt="">';
                    html += '<span class="xingxy-gallery-remove" title="移除">&times;</span>';
                    html += '</div>';
                    $('#xingxy-gallery-preview').append(html);
                }
            });
            $('#cover_image_ids').val(ids.join(','));
        });
        frame.open();
    });

    // 移除图片
    $(document).on('click', '.xingxy-gallery-remove', function() {
        var item = $(this).closest('.xingxy-gallery-item');
        var removeId = String(item.data('id'));
        item.remove();
        var ids = $('#cover_image_ids').val().split(',').filter(function(id) {
            return id !== removeId;
        });
        $('#cover_image_ids').val(ids.join(','));
    });

    // 发货方式切换
    $('input[name="shipping_type"]').on('change', function() {
        if ($(this).val() === 'auto') {
            $('#xingxy-card-pass-box').slideDown(200);
        } else {
            $('#xingxy-card-pass-box').slideUp(200);
        }
    });

    // 提交表单
    $('.xingxy-product-submit').on('click', function() {
        var $btn = $(this);
        var action = $btn.data('action');
        
        if ($btn.hasClass('loading')) return;
        $btn.addClass('loading').prop('disabled', true);
        
        // 获取 TinyMCE 内容
        var content = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('product_content')) {
            content = tinymce.get('product_content').getContent();
        } else {
            content = $('#product_content').val();
        }
        
        var formData = {
            action: action,
            product_id: $('input[name="product_id"]').val(),
            product_title: $('textarea[name="product_title"]').val(),
            product_desc: $('textarea[name="product_desc"]').val(),
            product_content: content,
            product_price: $('input[name="product_price"]').val(),
            'shop_cat[]': [],
            product_tags: $('textarea[name="product_tags"]').val(),
            cover_image_ids: $('#cover_image_ids').val(),
            shipping_type: $('input[name="shipping_type"]:checked').val(),
            card_pass_key: $('input[name="card_pass_key"]').val()
        };
        
        // 收集分类
        var cats = [];
        $('input[name="shop_cat[]"]:checked').each(function() {
            cats.push($(this).val());
        });
        
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: $.param(formData) + '&' + $.param({'shop_cat': cats}),
            dataType: 'json',
            success: function(res) {
                $btn.removeClass('loading').prop('disabled', false);
                if (res.success || res.error === 0) {
                    var data = res.data || res;
                    if (data.msg) {
                        // 使用 Zibll 的通知系统
                        if (typeof notyf_top !== 'undefined') {
                            notyf_top(data.msg, 'success');
                        } else {
                            alert(data.msg);
                        }
                    }
                    if (data.reload && data.goto) {
                        setTimeout(function() { window.location.href = data.goto; }, 1000);
                    } else if (data.product_id) {
                        $('input[name="product_id"]').val(data.product_id);
                    }
                } else {
                    var msg = res.data || res.msg || '保存失败';
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(msg, 'danger');
                    } else {
                        alert(msg);
                    }
                }
            },
            error: function() {
                $btn.removeClass('loading').prop('disabled', false);
                alert('网络错误，请稍后重试');
            }
        });
    });
});
</script>

<?php get_footer(); ?>

