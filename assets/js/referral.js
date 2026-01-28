/**
 * 邀请好友注册 - 功能增强脚本 (Gift Box Style)
 * 
 * 1. 自动识别邀请任务项
 * 2. 插入礼包图标 + HOT 标签
 * 3. 复用主题内置的复制链接和推广海报功能
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        tagText: 'HOT'
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

    // 创建按钮（复用主题内置功能）
    function createButtons(referralData) {
        if (!referralData.url || !referralData.userId) {
            return '';
        }

        // 复制链接按钮
        var copyBtn = '<a data-clipboard-text="' + referralData.url + '" data-clipboard-tag="推广链接" ' +
            'class="but c-yellow xingxy-btn" href="javascript:;">' +
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
                // 添加高亮样式
                $item.addClass('xingxy-referral-highlight');

                // 1. 插入礼包图标（在最前面）
                if (!$item.find('.xingxy-gift-icon').length) {
                    $item.prepend('<i class="fa fa-gift xingxy-gift-icon"></i>');
                }

                // 2. 插入HOT标签（在图标后）
                // 为了布局好看，我们尝试把它插在图标后面，或者直接跟在图标后
                if (!$item.find('.xingxy-referral-tag').length) {
                    $item.find('.xingxy-gift-icon').after('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                // 3. 添加复制链接和推广海报按钮
                if (!$item.find('.xingxy-referral-btns').length) {
                    var buttons = createButtons(referralData);
                    if (buttons) {
                        $item.append(buttons);
                        // 确保加载 clipboard 模块
                        if (typeof tbquire !== 'undefined') {
                            tbquire(['clipboard']);
                        }
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
