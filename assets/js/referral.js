/**
 * 邀请好友注册 - 功能增强脚本 (Scheme Q: Direct Event Binding)
 * 
 * 包含：
 * 1. 交互升级：基于 Class 的 Dynamic Toggle
 * 2. 核心修复：直接绑定 Click 事件，防止 stopPropagation 导致失效
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        tagText: '福利',
        iconHtml: `<div class="xingxy-gift-icon"><svg t="1769583886236" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="31313" width="200" height="200"><path d="M886.784 1016.832H141.312c-24.576 0-44.032-19.456-44.032-44.032V418.816c0-24.576 19.456-44.032 44.032-44.032h746.496c24.576 0 44.032 19.456 44.032 44.032V972.8c-1.024 24.576-20.48 44.032-45.056 44.032z" fill="#FF5E5F" p-id="31314"></path><path d="M759.808 29.696C701.44-4.096 625.664 16.384 591.872 74.752l-76.8 133.12c-2.048 3.072-4.096 7.168-5.12 10.24-2.048-3.072-3.072-7.168-5.12-10.24l-76.8-133.12C393.216 16.384 317.44-4.096 259.072 29.696c-58.368 33.792-78.848 109.568-45.056 167.936l76.8 133.12c33.792 58.368 109.568 78.848 167.936 45.056 23.552-13.312 39.936-32.768 50.176-55.296 10.24 22.528 27.648 41.984 50.176 55.296C617.472 409.6 693.248 389.12 727.04 330.752l76.8-133.12c34.816-58.368 14.336-134.144-44.032-167.936z" fill="#E05162" p-id="31315"></path><path d="M424.96 1016.832V436.224c0-49.152 39.936-89.088 89.088-89.088 49.152 0 89.088 39.936 89.088 89.088v580.608H424.96z" fill="#FFB0D4" p-id="31316"></path><path d="M923.648 443.392H103.424c-49.152 0-89.088-39.936-89.088-89.088 0-49.152 39.936-89.088 89.088-89.088h820.224c49.152 0 89.088 39.936 89.088 89.088 0 49.152-39.936 89.088-89.088 89.088z" fill="#FFC0DA" p-id="31317"></path></svg></div>`,

        bgHtml: `
        <div class="xingxy-bg-container">
            <svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;">
                <defs>
                    <filter id="goo">
                        <feGaussianBlur in="SourceGraphic" stdDeviation="12" result="blur" />
                        <feColorMatrix in="blur" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 18 -8" result="goo" />
                        <feBlend in="SourceGraphic" in2="goo" />
                    </filter>
                </defs>
            </svg>
            <div class="gradients-container">
                <div class="xingxy-bg-bubble g1"></div>
                <div class="xingxy-bg-bubble g2"></div>
                <div class="xingxy-bg-bubble g3"></div>
                <div class="xingxy-bg-bubble g4"></div>
                <div class="xingxy-bg-bubble g5"></div>
            </div>
        </div>
        `
    };

    function getReferralData() {
        if (typeof xingxy_referral !== 'undefined' && xingxy_referral.referral_url) {
            return {
                url: xingxy_referral.referral_url,
                userId: xingxy_referral.user_id
            };
        }
        var $refInput = $('[data-clipboard-text*="?ref="]');
        if ($refInput.length) {
            var match = $refInput.attr('data-clipboard-text').match(/ref=(\d+)/);
            return {
                url: $refInput.attr('data-clipboard-text'),
                userId: match ? match[1] : ''
            };
        }
        return { url: '', userId: '' };
    }

    // Toggle 交互处理函数 (Direct Handler)
    function handleToggleClick(e) {
        // 不阻止冒泡，让业务逻辑通过
        var $btn = $(this);
        var $track = $btn.closest('.xingxy-toggle-track');
        var type = $btn.attr('data-toggle');

        if (type === 'poster') {
            $track.removeClass('state-copy').addClass('state-poster');
        } else {
            $track.removeClass('state-poster').addClass('state-copy');
        }
    }

    function createDynamicToggle(referralData) {
        if (!referralData.url || !referralData.userId) return null;

        // 创建 DOM 对象而不是字符串，以便直接绑定事件
        var $container = $('<div class="xingxy-toggle-control"></div>');
        var $track = $('<div class="xingxy-toggle-track state-copy"></div>'); // 默认 copy 状态

        $track.append('<div class="xingxy-toggle-indicator"></div>');

        var $btnCopy = $('<div class="xingxy-toggle-label clip-aut"></div>')
            .attr('data-toggle', 'copy')
            .attr('data-clipboard-text', referralData.url)
            .attr('data-clipboard-tag', '推广链接')
            .html('<i class="fa fa-link"></i> 复制链接');

        var $btnPoster = $('<div class="xingxy-toggle-label btn-poster"></div>')
            .attr('data-toggle', 'poster')
            .attr('poster-share', 'rebate_' + referralData.userId)
            .attr('data-user', referralData.userId)
            .html('<i class="fa fa-qrcode"></i> 推广海报');

        // [CRITICAL FIX] 直接绑定 click 事件，避开 document 冒泡被拦截的问题
        $btnCopy.on('click', handleToggleClick);
        $btnPoster.on('click', handleToggleClick);

        $track.append($btnCopy);
        $track.append($btnPoster);
        $container.append($track);

        return $container;
    }

    function enhanceReferralItem() {
        var referralData = getReferralData();

        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                $item.addClass('xingxy-referral-highlight');

                if (!$item.find('.xingxy-bg-container').length) $item.prepend(config.bgHtml);
                if (!$item.find('.xingxy-gift-icon').length) $item.prepend(config.iconHtml);
                if (!$item.find('.xingxy-referral-tag').length) {
                    $item.find('.xingxy-gift-icon').after('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                var $points = $item.find('.focus-color');
                var $pointsContainer = $points.parent();

                $pointsContainer.addClass('xingxy-right-panel');

                if (!$item.find('.xingxy-toggle-control').length) {
                    var $toggle = createDynamicToggle(referralData);
                    if ($toggle) {
                        $pointsContainer.append($toggle);
                    }
                }

                var $desc = $item.find('.muted-color, .muted-2-color, [class*="muted"]').not('.xingxy-referral-btns *');
                if ($desc.length) {
                    $desc.removeClass('muted-color muted-2-color');
                    $desc.addClass('xingxy-referral-desc');
                }
            }
        });
    }

    /**
     * 隐藏推广链接参数
     * 防止用户删除参数后绕过推荐关系
     * 使用 history.replaceState 在不刷新页面的情况下移除 URL 参数
     */
    function hideReferralParam() {
        // 检查是否存在 ref 参数
        var urlParams = new URLSearchParams(window.location.search);
        var refParam = urlParams.get('ref');

        if (refParam) {
            // 移除 ref 参数
            urlParams.delete('ref');

            // 构建新的 URL
            var newUrl = window.location.pathname;
            var remainingParams = urlParams.toString();
            if (remainingParams) {
                newUrl += '?' + remainingParams;
            }
            newUrl += window.location.hash;

            // 使用 replaceState 替换 URL，不产生历史记录
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, newUrl);
            }
        }
    }

    // 初始化
    $(document).ready(function () {
        // 立即隐藏推广链接参数（在页面渲染之前）
        hideReferralParam();

        setTimeout(enhanceReferralItem, 300);
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                enhanceReferralItem();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });

    $(document).on('shown.bs.tab', function () {
        setTimeout(enhanceReferralItem, 100);
    });

})(jQuery);
