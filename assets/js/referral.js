/**
 * 邀请好友注册 - 功能增强脚本 (Scheme G: Glass Button + New Layout)
 * 
 * 1. 布局重构：左(图标) - 中(文案) - 右(积分+按钮)
 * 2. 按钮升级：Glass Button 结构
 * 3. 夜间模式：灰紫沉浸风
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        tagText: '福利',
        iconHtml: '<div class="xingxy-gift-icon">🎁</div>',
        // 背景保持不变，CSS 中会修改配色
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

    // 生成 Glass Button 结构
    // 结构: .button-wrap > button > span > text
    function createGlassButton(text, iconClass, attrMap, extraClass) {
        var attrs = '';
        for (var key in attrMap) {
            attrs += key + '="' + attrMap[key] + '" ';
        }

        return `
        <div class="button-wrap ${extraClass}" ${attrs}>
            <button class="glass-btn">
                <span><i class="${iconClass}"></i> ${text}</span>
            </button>
            <div class="button-shadow"></div>
        </div>
        `;
    }

    // 创建按钮组
    function createButtons(referralData) {
        if (!referralData.url || !referralData.userId) {
            return '';
        }

        // 复制链接 (Theme Clip Logic)
        // 注意：clip-aut 通常绑定在点击元素上，这里我们需要把 click 事件传递给 wrap
        // 或者直接让 wrap 触发复制
        var copyBtn = createGlassButton('复制链接', 'fa fa-link', {
            'data-clipboard-text': referralData.url,
            'data-clipboard-tag': '推广链接'
        }, 'btn-copy clip-aut'); // 添加 clip-aut 类以触发主题 JS

        // 推广海报
        var posterBtn = createGlassButton('推广海报', 'fa fa-qrcode', {
            'poster-share': 'rebate_' + referralData.userId,
            'data-user': referralData.userId
        }, 'btn-poster');

        return '<div class="xingxy-referral-btns">' + copyBtn + posterBtn + '</div>';
    }

    // 增强邀请任务项
    function enhanceReferralItem() {
        var referralData = getReferralData();

        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                $item.addClass('xingxy-referral-highlight');

                // 1. 注入背景
                if (!$item.find('.xingxy-bg-container').length) {
                    $item.prepend(config.bgHtml);
                }

                // 2. 注入图标
                if (!$item.find('.xingxy-gift-icon').length) {
                    $item.prepend(config.iconHtml);
                }

                // 3. 注入标签
                if (!$item.find('.xingxy-referral-tag').length) {
                    $item.find('.xingxy-gift-icon').after('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                // 4.布局重构：移动积分和添加按钮
                // 找到积分元素 (.focus-color)
                var $points = $item.find('.focus-color');
                var $pointsContainer = $points.parent(); // 积分通常包裹在一个 div 里

                // 为了实现 "按钮在积分下方"，我们需要把积分和新按钮包裹在一个右侧容器中
                // 创建右侧容器
                if (!$item.find('.xingxy-right-panel').length) {
                    // 创建按钮 HTML
                    var buttonsHtml = createButtons(referralData);

                    // 将积分元素移动到新容器 (Clone or Move)
                    // 这里我们为了不破坏原有结构太严重，创建一个绝对定位或 Flex 的右侧面板
                    // Zibll 结构通常是 flex jus-sb (左右分布)
                    // 我们直接插入按钮到积分元素后面，然后用 CSS 强制换行或 Flex Column

                    $pointsContainer.addClass('xingxy-right-panel');
                    $pointsContainer.append(buttonsHtml);
                }
            }
        });
    }

    // 初始化事件绑定 (因为 Glass Button 结构复杂，需要手动代理 click)
    $(document).on('click', '.button-wrap.btn-copy', function () {
        // 复制逻辑由 clipboard.js 自动监听 data-clipboard-text，只要属性在 .button-wrap 上即可
        // 如果不行，可能需要手动触发内部 button 的点击
    });

    $(document).on('click', '.button-wrap.btn-poster', function () {
        // 同样，poster-share 属性在 .button-wrap 上
    });

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
