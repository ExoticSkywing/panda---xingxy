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
    'ID'            => '',
    'post_title'    => '',
    'post_content'  => '',
    'desc'          => '',
    'price'         => '',
    'cover_ids'     => '',
    'shipping_type' => 'manual',
    'auto_type'     => 'fixed',      // 自动发货子类型：fixed / card_pass
    'fixed_content' => '',           // 固定内容
    'card_pass_key' => '',           // 卡密备注关键词
    'tags'          => '',
    'post_status'   => '',
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
        
        // 自动发货配置
        if (isset($config['auto_delivery']) && is_array($config['auto_delivery'])) {
            $ad = $config['auto_delivery'];
            $in['auto_type']     = isset($ad['type']) ? $ad['type'] : 'fixed';
            $in['fixed_content'] = isset($ad['fixed_content']) ? $ad['fixed_content'] : '';
            $in['card_pass_key'] = isset($ad['card_pass_key']) ? $ad['card_pass_key'] : '';
        }
    }
    
    // 标签
    $tags = get_the_terms($edit_post->ID, 'shop_tag');
    if ($tags && !is_wp_error($tags)) {
        $in['tags'] = implode(', ', array_column((array) $tags, 'name'));
    }
}

// 计算卡密库存（仅编辑时且有 card_pass_key）
$card_stock = 0;
if ($in['card_pass_key'] && class_exists('ZibCardPass')) {
    $card_stock = ZibCardPass::get_count(array(
        'other'  => $in['card_pass_key'],
        'status' => '0',
    ));
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
            
            <!-- 发货设置 -->
            <div class="zib-widget mb10-sm dependency-box">
                <div class="title-theme mb10">发货设置</div>
                
                <!-- 发货类型 -->
                <div class="mb10">
                    <label class="badg p2-10 mr10 pointer">
                        <input type="radio" name="shipping_type" value="auto" <?php checked($in['shipping_type'], 'auto'); ?>> 自动发货
                    </label>
                    <label class="badg p2-10 mr10 pointer">
                        <input type="radio" name="shipping_type" value="manual" <?php checked($in['shipping_type'], 'manual'); ?>> 手动发货
                    </label>
                </div>
                
                <!-- 自动发货配置（仅自动发货时显示） -->
                <div id="xingxy-auto-delivery-box" style="<?php echo $in['shipping_type'] !== 'auto' ? 'display:none;' : ''; ?>">
                    
                    <!-- 自动发货子类型 -->
                    <div class="mb10" style="border-bottom:1px dashed var(--muted-border-color);padding-bottom:10px;">
                        <label class="badg badg-sm p2-10 mr6 pointer">
                            <input type="radio" name="auto_type" value="fixed" <?php checked($in['auto_type'], 'fixed'); ?>> 固定内容
                        </label>
                        <label class="badg badg-sm p2-10 mr6 pointer">
                            <input type="radio" name="auto_type" value="card_pass" <?php checked($in['auto_type'], 'card_pass'); ?>> 卡密
                        </label>
                    </div>
                    
                    <!-- 固定内容区 -->
                    <div id="xingxy-fixed-content-box" style="<?php echo $in['auto_type'] !== 'fixed' ? 'display:none;' : ''; ?>">
                        <p class="muted-color em09 mb6"><i class="fa fa-info-circle mr3"></i>所有买家将收到相同内容，支持HTML</p>
                        <textarea class="form-control" name="fixed_content" rows="5" placeholder="输入发送给用户的内容，例如网盘链接、教程地址等"><?php echo esc_textarea($in['fixed_content']); ?></textarea>
                    </div>
                    
                    <!-- 卡密区 -->
                    <div id="xingxy-cardpass-box" style="<?php echo $in['auto_type'] !== 'card_pass' ? 'display:none;' : ''; ?>">
                        
                        <!-- 卡密备注（核心匹配字段） -->
                        <div class="mb10">
                            <p class="muted-color em09 mb6"><i class="fa fa-tag mr3"></i>卡密备注 <span style="color:var(--color-red);">*</span></p>
                            <input type="text" class="form-control" name="card_pass_key" id="xingxy-card-pass-key" value="<?php echo esc_attr($in['card_pass_key']); ?>" placeholder="例如：谷歌账号、苹果ID、VPN月卡">
                            <p class="muted-3-color em09 mt3">用于区分不同商品的卡密，发货时按此备注匹配</p>
                        </div>
                        
                        <!-- 库存显示 -->
                        <div class="flex ac jc mb10" style="padding:8px 12px;border-radius:6px;background:var(--muted-border-color);">
                            <span class="muted-color"><i class="fa fa-database mr3"></i>当前库存</span>
                            <span id="xingxy-card-stock" class="ml10 em12" style="font-weight:bold;color:<?php echo $card_stock > 0 ? 'var(--color-green)' : 'var(--color-red)'; ?>;"><?php echo (int) $card_stock; ?></span>
                            <span class="muted-3-color ml3">张</span>
                        </div>
                        
                        <!-- 导入区 -->
                        <p class="muted-color em09 mb6"><i class="fa fa-upload mr3"></i>导入卡密（一行一条，格式：<code>卡号 密码</code>，用空格分隔）</p>
                        <textarea id="xingxy-cardpass-data" class="form-control" rows="6" placeholder="粘贴卡密数据，一行一条&#10;&#10;示例：&#10;account01@mail.com P@ssw0rd123&#10;account02@mail.com Abc456def&#10;CARD-001 SecretKey-ABC"></textarea>
                        
                        <div class="flex ac mt6">
                            <span class="flex1"></span>
                            <button type="button" id="xingxy-import-cardpass-btn" class="but but-sm c-blue">
                                <i class="fa fa-upload mr3"></i>导入
                            </button>
                        </div>
                        <div id="xingxy-import-result" class="mt6 em09" style="display:none;"></div>
                    </div>
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

<style>
@keyframes xingxy-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}
</style>
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

    // 发货类型切换（自动/手动）
    $('input[name="shipping_type"]').on('change', function() {
        if ($(this).val() === 'auto') {
            $('#xingxy-auto-delivery-box').slideDown(200);
        } else {
            $('#xingxy-auto-delivery-box').slideUp(200);
        }
    });

    // 自动发货子类型切换（固定内容/卡密）
    $('input[name="auto_type"]').on('change', function() {
        var type = $(this).val();
        if (type === 'fixed') {
            $('#xingxy-fixed-content-box').slideDown(200);
            $('#xingxy-cardpass-box').slideUp(200);
        } else {
            $('#xingxy-fixed-content-box').slideUp(200);
            $('#xingxy-cardpass-box').slideDown(200);
        }
    });

    // 卡密输入时实时引导
    $('#xingxy-cardpass-data').on('input', function() {
        var hasData = $(this).val() && $(this).val().trim();
        if (hasData) {
            if (!$('#xingxy-import-hint').length) {
                $(this).after('<div id="xingxy-import-hint" style="margin-top:6px;padding:6px 10px;border-radius:4px;border:1px dashed var(--muted-2-color);background:var(--main-bg-color);font-size:12px;color:var(--color-blue);animation:xingxy-pulse 1.5s infinite;"><i class="fa fa-hand-pointer-o mr3"></i>数据已就绪，请点击右下方「导入」按钮完成导入</div>');
            }
        } else {
            $('#xingxy-import-hint').remove();
        }
    });

    // 卡密导入
    $('#xingxy-import-cardpass-btn').on('click', function() {
        var $btn = $(this);
        var data = $('#xingxy-cardpass-data').val();
        var productId = $('input[name="product_id"]').val();
        var cardPassKey = $('input[name="card_pass_key"]').val();
        
        if (!data || !data.trim()) {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先粘贴卡密数据', 'danger');
            } else {
                alert('请先粘贴卡密数据');
            }
            return;
        }
        
        if (!cardPassKey || !cardPassKey.trim()) {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先填写卡密备注', 'danger');
            } else {
                alert('请先填写卡密备注');
            }
            $('#xingxy-card-pass-key').focus();
            return;
        }
        
        if (!productId || productId === '0') {
            if (typeof notyf_top !== 'undefined') {
                notyf_top('请先保存商品后再导入卡密', 'danger');
            } else {
                alert('请先保存商品后再导入卡密');
            }
            return;
        }
        
        $btn.addClass('loading').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'xingxy_import_cardpass',
                product_id: productId,
                import_data: data,
                card_pass_key: cardPassKey
            },
            dataType: 'json',
            success: function(res) {
                $btn.removeClass('loading').prop('disabled', false);
                var $result = $('#xingxy-import-result');
                if (res.success || res.error == 0) {
                    var d = res.data || res;
                    var resultMsg = '成功导入 ' + d.success_count + ' 条卡密';
                    if (d.error_count > 0) {
                        resultMsg += '，' + d.error_count + ' 条失败';
                    }
                    $result.html('<span style="color:#52c41a;font-weight:bold;"><i class="fa fa-check-circle mr3"></i>' + resultMsg + '</span>').show();
                    // 更新库存数
                    var newStock = d.stock !== undefined ? d.stock : d.success_count;
                    $('#xingxy-card-stock').text(newStock).css('color', newStock > 0 ? 'var(--color-green)' : 'var(--color-red)');
                    // 更新 card_pass_key
                    if (d.card_pass_key) {
                        $('input[name="card_pass_key"]').val(d.card_pass_key);
                    }
                    // 清空输入框
                    $('#xingxy-cardpass-data').val('');
                    $('#xingxy-import-hint').remove();
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(resultMsg, 'success');
                    }
                } else {
                    var msg = res.data || res.msg || '导入失败';
                    $result.html('<span style="color:var(--color-red);"><i class="fa fa-times-circle mr3"></i>' + msg + '</span>').show();
                    if (typeof notyf_top !== 'undefined') {
                        notyf_top(msg, 'danger');
                    }
                }
            },
            error: function() {
                $btn.removeClass('loading').prop('disabled', false);
                alert('网络错误，请稍后重试');
            }
        });
    });

    // 提交表单
    $('.xingxy-product-submit').on('click', function() {
        var $btn = $(this);
        var action = $btn.data('action');
        
        if ($btn.hasClass('loading')) return;
        
        // 检测未导入的卡密数据
        var pendingCardData = $('#xingxy-cardpass-data').val();
        var isCardPassMode = $('input[name="auto_type"]:checked').val() === 'card_pass';
        var isAutoShipping = $('input[name="shipping_type"]:checked').val() === 'auto';
        if (isAutoShipping && isCardPassMode && pendingCardData && pendingCardData.trim()) {
            if (!confirm('检测到卡密输入框中还有未导入的数据，请先点击「导入」按钮导入卡密。\n\n点击「确定」忽略并继续提交，点击「取消」返回导入。')) {
                return;
            }
        }
        
        $btn.addClass('loading').prop('disabled', true);
        
        // 获取 TinyMCE 内容
        var content = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('product_content')) {
            content = tinymce.get('product_content').getContent();
        } else {
            content = $('#product_content').val();
        }
        
        var shippingType = $('input[name="shipping_type"]:checked').val();
        var autoType = $('input[name="auto_type"]:checked').val();
        
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
            shipping_type: shippingType,
            auto_type: shippingType === 'auto' ? autoType : '',
            fixed_content: (shippingType === 'auto' && autoType === 'fixed') ? $('textarea[name="fixed_content"]').val() : '',
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
                if (res.success || res.error == 0) {
                    var data = res.data || res;
                    if (data.msg) {
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

