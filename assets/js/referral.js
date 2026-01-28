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
        iconHtml: '<div class="xingxy-gift-icon"><svg t="1769583886236" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="31313" width="200" height="200"><path d="M886.784 1016.832H141.312c-24.576 0-44.032-19.456-44.032-44.032V418.816c0-24.576 19.456-44.032 44.032-44.032h746.496c24.576 0 44.032 19.456 44.032 44.032V972.8c-1.024 24.576-20.48 44.032-45.056 44.032z" fill="#FF5E5F" p-id="31314"></path><path d="M759.808 29.696C701.44-4.096 625.664 16.384 591.872 74.752l-76.8 133.12c-2.048 3.072-4.096 7.168-5.12 10.24-2.048-3.072-3.072-7.168-5.12-10.24l-76.8-133.12C393.216 16.384 317.44-4.096 259.072 29.696c-58.368 33.792-78.848 109.568-45.056 167.936l76.8 133.12c33.792 58.368 109.568 78.848 167.936 45.056 23.552-13.312 39.936-32.768 50.176-55.296 10.24 22.528 27.648 41.984 50.176 55.296C617.472 409.6 693.248 389.12 727.04 330.752l76.8-133.12c34.816-58.368 14.336-134.144-44.032-167.936z" fill="#E05162" p-id="31315"></path><path d="M424.96 1016.832V436.224c0-49.152 39.936-89.088 89.088-89.088 49.152 0 89.088 39.936 89.088 89.088v580.608H424.96z" fill="#FFB0D4" p-id="31316"></path><path d="M923.648 443.392H103.424c-49.152 0-89.088-39.936-89.088-89.088 0-49.152 39.936-89.088 89.088-89.088h820.224c49.152 0 89.088 39.936 89.088 89.088 0 49.152-39.936 89.088-89.088 89.088z" fill="#FFC0DA" p-id="31317"></path></svg></div>',
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
