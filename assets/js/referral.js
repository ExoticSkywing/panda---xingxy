/**
 * 邀请好友注册 - 功能增强脚本
 * 
 * 1. 自动识别邀请任务项并添加高亮样式
 * 2. 添加"复制链接"按钮
 * 3. 添加"热门"标签
 */

(function ($) {
    'use strict';

    // 配置
    var config = {
        referralKeyword: '邀请好友注册',
        tagText: '🔥 热门',
        copyBtnText: '复制链接',
        copiedText: '已复制!'
    };

    // 获取当前用户的推荐链接
    function getReferralLink() {
        // 优先使用 PHP 传递的数据
        if (typeof xingxy_referral !== 'undefined' && xingxy_referral.referral_url) {
            return xingxy_referral.referral_url;
        }

        // 降级：尝试从页面获取用户ID
        var userId = typeof zib_user_id !== 'undefined' ? zib_user_id : '';
        if (!userId) {
            // 尝试从推广链接输入框获取
            var $refInput = $('input[value*="?ref="]');
            if ($refInput.length) {
                return $refInput.val();
            }
            // 尝试从页面其他元素获取
            var $userLink = $('.author-link[href*="user_id="]');
            if ($userLink.length) {
                var match = $userLink.attr('href').match(/user_id=(\d+)/);
                if (match) userId = match[1];
            }
        }

        if (userId) {
            return window.location.origin + '/?ref=' + userId;
        }
        return '';
    }

    // 复制到剪贴板
    function copyToClipboard(text, $btn) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopiedFeedback($btn);
            }).catch(function () {
                fallbackCopy(text, $btn);
            });
        } else {
            fallbackCopy(text, $btn);
        }
    }

    // 降级复制方案
    function fallbackCopy(text, $btn) {
        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        document.execCommand('copy');
        $temp.remove();
        showCopiedFeedback($btn);
    }

    // 显示复制成功反馈
    function showCopiedFeedback($btn) {
        var originalText = $btn.html();
        $btn.addClass('copied').html('<i class="fa fa-check"></i> ' + config.copiedText);
        setTimeout(function () {
            $btn.removeClass('copied').html(originalText);
        }, 2000);
    }

    // 增强邀请任务项
    function enhanceReferralItem() {
        // 查找包含"邀请好友注册"的任务项
        $('.border-bottom.padding-h10').each(function () {
            var $item = $(this);
            var text = $item.text();

            if (text.indexOf(config.referralKeyword) !== -1 && !$item.hasClass('xingxy-referral-highlight')) {
                // 添加高亮样式
                $item.addClass('xingxy-referral-highlight');

                // 添加热门标签
                if (!$item.find('.xingxy-referral-tag').length) {
                    $item.prepend('<span class="xingxy-referral-tag">' + config.tagText + '</span>');
                }

                // 添加复制链接按钮
                var $descDiv = $item.find('.muted-2-color.em09');
                if ($descDiv.length && !$item.find('.xingxy-copy-link-btn').length) {
                    var $copyBtn = $('<button class="xingxy-copy-link-btn"><i class="fa fa-link"></i> ' + config.copyBtnText + '</button>');
                    $copyBtn.on('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var link = getReferralLink();
                        copyToClipboard(link, $(this));
                    });
                    $descDiv.after($copyBtn);
                }
            }
        });
    }

    // 页面加载完成后执行
    $(document).ready(function () {
        enhanceReferralItem();

        // 监听 DOM 变化（用于动态加载的内容）
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
