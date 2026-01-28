/**
 * 邀请好友注册 - 功能增强脚本 (Scheme O: Dynamic Toggle + Fix)
 * 
 * 包含：
 * 1. 布局重构：左(图标) - 中(文案) - 右(积分+Toggle)
 * 2. 交互升级：Dynamic Toggle 动态切换组件
 * 3. 核心修复：SVG 换行符支持 & 强制去除 muted-color
 * 4. BugFix: 强制同步 Radio 状态，防止主题 preventDefault 导致滑块卡住
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

    // 获取 referral 数据
    function getReferralData() {
        if (typeof xingxy_referral !== 'undefined' && xingxy_referral.referral_url) {
            return {
                url: xingxy_referral.referral_url,
                userId: xingxy_referral.user_id
            };
        }
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

    // 创建 Dynamic Toggle 组件
    function createDynamicToggle(referralData) {
        if (!referralData.url || !referralData.userId) return '';

        return `
        <div class="xingxy-toggle-control">
            <div class="xingxy-toggle-track">
                <input type="radio" class="sr-only" name="xingxy-action" id="xingxy-toggle-copy" value="copy" checked>
                <input type="radio" class="sr-only" name="xingxy-action" id="xingxy-toggle-poster" value="poster">
                
                <div class="xingxy-toggle-indicator"></div>
                
                <label for="xingxy-toggle-copy" class="xingxy-toggle-label clip-aut" 
                       data-clipboard-text="${referralData.url}" 
                       data-clipboard-tag="推广链接">
                    <i class="fa fa-link"></i> 复制链接
                </label>
                
                <label for="xingxy-toggle-poster" class="xingxy-toggle-label btn-poster" 
                       poster-share="rebate_${referralData.userId}" 
                       data-user="${referralData.userId}">
                    <i class="fa fa-qrcode"></i> 推广海报
                </label>
            </div>
        </div>
        `;
    }

    // 增强邀请任务项
    function enhanceReferralItem() {
        var referralData = getReferralData();

        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                $item.addClass('xingxy-referral-highlight');

                if (!$item.find('.xingxy-bg-container').length) {
                    $item.prepend(config.bgHtml);
                }

                if (!$item.find('.xingxy-gift-icon').length) {
                    $item.prepend(config.iconHtml);
                }

                if (!$item.find('.xingxy-referral-tag').length) {
                    $item.find('.xingxy-gift-icon').after('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                var $points = $item.find('.focus-color');
                var $pointsContainer = $points.parent();

                $pointsContainer.addClass('xingxy-right-panel');

                if (!$item.find('.xingxy-toggle-control').length) {
                    var toggleHtml = createDynamicToggle(referralData);
                    $pointsContainer.append(toggleHtml);
                }

                var $desc = $item.find('.muted-color, .muted-2-color, [class*="muted"]').not('.xingxy-referral-btns *');
                if ($desc.length) {
                    $desc.removeClass('muted-color muted-2-color');
                    $desc.addClass('xingxy-referral-desc');
                }
            }
        });
    }

    // [FIX] 强制同步 Radio 状态
    // 解决主题 .btn-poster 可能阻止默认行为导致 Input 未被选中的问题
    $(document).on('click', '.xingxy-toggle-label', function () {
        var inputId = $(this).attr('for');
        if (inputId) {
            $('#' + inputId).prop('checked', true);
        }
    });

    // 初始化
    $(document).ready(function () {
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
