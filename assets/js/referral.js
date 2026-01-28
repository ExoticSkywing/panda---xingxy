/**
 * 邀请好友注册 - 功能增强脚本 (Scheme G: Ultimate Glass)
 * 核心：Bubbles 背景 + Glass 按钮 + 左右布局重构
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        tagText: '福利',
        iconHtml: '<span class="xingxy-gift-icon">🎁</span>',
        // 背景层
        bgHtml: `
        <div class="xingxy-bg-container">
            <svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;">
                <defs>
                    <filter id="goo">
                        <feGaussianBlur in="SourceGraphic" stdDeviation="10" result="blur" />
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
            var url = $refInput.attr('data-clipboard-text');
            var match = url.match(/ref=(\d+)/);
            return {
                url: url,
                userId: match ? match[1] : ''
            };
        }
        return { url: '', userId: '' };
    }

    // 创建升级版 Glass 按钮
    function createButtons(referralData) {
        if (!referralData.url || !referralData.userId) {
            return '';
        }

        // 复制链接 (Glass Style)
        var copyBtn = `
        <div class="xingxy-glass-btn-wrap">
            <button class="xingxy-glass-btn clip-aut" data-clipboard-text="${referralData.url}" data-clipboard-tag="推广链接">
                <span><i class="fa fa-link"></i> 复制链接</span>
            </button>
        </div>
        `;

        // 推广海报 (Glass Style)
        var posterBtn = `
        <div class="xingxy-glass-btn-wrap">
            <button class="xingxy-glass-btn" poster-share="rebate_${referralData.userId}" data-user="${referralData.userId}">
                <span><i class="fa fa-qrcode"></i> 推广海报</span>
            </button>
        </div>
        `;

        return '<div class="xingxy-referral-btns">' + copyBtn + posterBtn + '</div>';
    }

    function enhanceReferralItem() {
        var referralData = getReferralData();

        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                $item.addClass('xingxy-referral-highlight');

                // --- 结构重构 START ---
                // 1. 把原有的内容（除了我们新加的背景等）包裹进 Left Content Wrap
                // 目的：实现 flex 布局（左边文字，右边按钮）
                // 现有的内部元素通常是：div.muted-color (标题), div.flex (积分)

                // 将当前所有子元素包裹起来 (作为左侧内容区)
                $item.wrapInner('<div class="xingxy-content-wrap"></div>');
                var $contentWrap = $item.find('.xingxy-content-wrap');

                // 2. 注入背景层 (在 contentWrap 之外，item 内的最前)
                $item.prepend(config.bgHtml);

                // 3. 注入图标 (绝对定位，可以放在 item 内)
                $item.append(config.iconHtml);

                // 4. 添加标签 (绝对定位)
                $item.append('<span class="xingxy-referral-tag">' + config.tagText + '</span>');

                // 5. 添加按钮 (Flex 布局的右侧元素，追加到 Item 最后)
                if (!$item.find('.xingxy-referral-btns').length) {
                    var buttons = createButtons(referralData);
                    if (buttons) {
                        $item.append(buttons);
                    }
                }
                // --- 结构重构 END ---
            }
        });
    }

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
