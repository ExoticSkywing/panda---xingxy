/**
 * 推广链接参数隐藏脚本
 * 
 * 在页面加载时立即隐藏 URL 中的 ref 参数
 * 防止用户删除参数后绕过推荐关系
 * 
 * 技术原理：使用 history.replaceState 在不刷新页面的情况下修改 URL
 * 此时 Cookie/Session 已由服务器端处理完毕，隐藏参数不影响功能
 * 
 * @package Xingxy
 */

(function () {
    'use strict';

    // 立即执行，不等待 DOMContentLoaded
    function hideReferralParam() {
        try {
            // 检查浏览器支持
            if (!window.URLSearchParams || !window.history || !window.history.replaceState) {
                return;
            }

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
                window.history.replaceState({}, document.title, newUrl);
            }
        } catch (e) {
            // 静默失败，不影响页面功能
        }
    }

    // 立即执行
    hideReferralParam();
})();
