/**
 * 邀请好友注册 - 功能增强脚本 (Scheme C: Gift Package Style)
 * 
 * 1. 自动识别邀请任务项
 * 2. 添加 "🎁" 礼包图标和 "福利" 标签
 * 3. 复用主题内置的复制链接和推广海报功能
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        // 使用 "福利" 或 "HOT"，配合大礼包感觉 "福利" 更贴切，或者保留 "HOT"
        tagText: '福利',
        iconHtml: '<span class="xingxy-gift-icon">🎁</span>'
    };

    // 获取当前用户的推荐链接和用户ID
    function getReferralData() {
        // 优先使用 PHP 传递的数据
        if (typeof xingxy_referral !== 'undefined' && xingxy_referral.referral_url) {
            return {
                url: xingxy_referral.referral_url,
                userId: xingxy_referral.user_id
            };
        }

        // 降级：尝试从页面推广链接获取
        var $refInput = $('[data-clipboard-text*="?ref="]');
        if ($refInput.length) {
            var url = $refInput.attr('data-clipboard-text');
            var match = url.match(/ref=(\d+)/);
            return {
                url: url,
                userId: match ? match[1] : ''
            };
        }

        return { url: '', userId: '' };
    }

    // 创建按钮（复用主题内置功能，但在CSS中重塑样式）
    function createButtons(referralData) {
        if (!referralData.url || !referralData.userId) {
            return '';
        }

        // 复制链接按钮
        var copyBtn = '<a data-clipboard-text="' + referralData.url + '" data-clipboard-tag="推广链接" ' +
            'class="clip-aut but c-yellow xingxy-btn" href="javascript:;">' +
            '<i class="fa fa-link"></i> 复制链接</a>';

        // 推广海报按钮
        var posterBtn = '<a poster-share="rebate_' + referralData.userId + '" data-user="' + referralData.userId + '" ' +
            'href="javascript:;" class="but c-cyan xingxy-btn">' +
            '<i class="fa fa-qrcode"></i> 推广海报</a>';

        return '<div class="xingxy-referral-btns mt10">' + copyBtn + posterBtn + '</div>';
    }

    // 增强邀请任务项
    function enhanceReferralItem() {
        var referralData = getReferralData();

        // 查找包含"邀请好友注册"的任务项
        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                // 添加高亮样式类
                $item.addClass('xingxy-referral-highlight');

                // 1. 处理标题：插入礼包图标
                // 找到包含文本的节点（通常是直接文本或span）
                // 这里简单处理：在开头插入图标
                var $titleContainer = $item.find('.muted-color').first();
                if ($titleContainer.length) {
                    // 如果标题在 .muted-color (通常是副标题)，尝试找上一级或同级的标题字体
                    // Zibll 结构通常是: div > div(标题)
                    // 也可以直接 prepend 到 $item 内容的最前面，然后通过 CSS 浮动调整
                    // 为了保险，我们插入到 $item 的第一个文本节点前
                    if (!$item.find('.xingxy-gift-icon').length) {
                        // 尝试找到标题元素，通常是字体较大的那个
                        // 简单策略：prepend 到 div 内部
                        $item.prepend(config.iconHtml);
                    }
                } else {
                    $item.prepend(config.iconHtml);
                }

                // 2. 添加标签
                if (!$item.find('.xingxy-referral-tag').length) {
                    // 插入到右上角或标题旁
                    $item.append('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                // 3. 添加按钮
                if (!$item.find('.xingxy-referral-btns').length) {
                    var buttons = createButtons(referralData);
                    if (buttons) {
                        $item.append(buttons);
                    }
                }
            }
        });
    }

    // 页面加载完成后执行
    $(document).ready(function () {
        // 延迟执行
        setTimeout(enhanceReferralItem, 300);

        // 监听 DOM 变化
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                enhanceReferralItem();
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    });

    // 监听 Tab 切换
    $(document).on('shown.bs.tab', function () {
        setTimeout(enhanceReferralItem, 100);
    });

})(jQuery);
