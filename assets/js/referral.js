/**
 * 邀请好友注册 - 功能增强脚本 (Scheme D: Gift Package + Bubbles Animation)
 * 
 * 1. 自动识别邀请任务项
 * 2. 注入动态气泡背景 (Gooey Effect)
 * 3. 注入 3D 礼包图标
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        tagText: '福利',
        // 3D 礼包图标
        iconHtml: '<span class="xingxy-gift-icon">🎁</span>',
        // 动态气泡背景结构
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

    // 创建按钮
    function createButtons(referralData) {
        if (!referralData.url || !referralData.userId) {
            return '';
        }
        var copyBtn = '<a data-clipboard-text="' + referralData.url + '" data-clipboard-tag="推广链接" ' +
            'class="clip-aut but c-yellow xingxy-btn" href="javascript:;">' +
            '<i class="fa fa-link"></i> 复制链接</a>';
        var posterBtn = '<a poster-share="rebate_' + referralData.userId + '" data-user="' + referralData.userId + '" ' +
            'href="javascript:;" class="but c-cyan xingxy-btn">' +
            '<i class="fa fa-qrcode"></i> 推广海报</a>';
        return '<div class="xingxy-referral-btns mt10">' + copyBtn + posterBtn + '</div>';
    }

    // 增强邀请任务项
    function enhanceReferralItem() {
        var referralData = getReferralData();

        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                $item.addClass('xingxy-referral-highlight');

                // 1. 注入背景层 (Prepend 到最前面)
                if (!$item.find('.xingxy-bg-container').length) {
                    $item.prepend(config.bgHtml);
                }

                // 2. 注入礼包图标 (在背景之后，内容之前)
                if (!$item.find('.xingxy-gift-icon').length) {
                    // 尝试插入到文本内容区之前，或者直接在背景后
                    $item.append(config.iconHtml);
                    // 由于 absolute 定位，append 也可以，主要看 z-index
                }

                // 3. 添加标签
                if (!$item.find('.xingxy-referral-tag').length) {
                    $item.append('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                // 4. 添加按钮
                if (!$item.find('.xingxy-referral-btns').length) {
                    var buttons = createButtons(referralData);
                    if (buttons) {
                        $item.append(buttons);
                    }
                }
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
