/**
 * Xingxy 商城优惠码前端集成
 *
 * 在订单确认弹窗中注入优惠码输入区域，
 * 拦截订单提交 AJAX 请求以附加优惠码参数。
 *
 * @package Xingxy
 */
(function ($) {
    'use strict';

    // 优惠码状态管理
    var couponState = {
        code: '',              // 当前优惠码
        verified: false,       // 是否已验证
        discountAmount: 0,     // 折扣金额
        discountedPrice: 0,    // 折后价
        originalPayPrice: 0,   // 原始支付价格（优惠活动折扣后，优惠码折扣前）
        desc: '',              // 折扣描述
        checking: false,       // 是否正在检查
        vueInstance: null,     // Vue 实例引用
    };

    /**
     * 生成优惠码输入区域 HTML
     */
    function getCouponHTML() {
        return '<div id="xingxy-coupon-box" class="zib-widget mb10-sm xingxy-coupon-box">' +
            '<div class="xingxy-coupon-header flex ac jsb">' +
            '<div class="flex ac">' +
            '<i class="fa fa-ticket mr6 xingxy-coupon-icon"></i>' +
            '<span class="muted-color">优惠码</span>' +
            '</div>' +
            '<div class="xingxy-coupon-status" id="xingxy-coupon-status"></div>' +
            '</div>' +
            '<div class="xingxy-coupon-input-row flex ac mt10">' +
            '<input type="text" class="form-control flex1 xingxy-coupon-input" ' +
            'id="xingxy-coupon-input" placeholder="请输入优惠码" maxlength="32" />' +
            '<button type="button" class="but c-blue ml10 xingxy-coupon-check-btn" ' +
            'id="xingxy-coupon-check-btn">检查</button>' +
            '</div>' +
            '<div class="xingxy-coupon-result" id="xingxy-coupon-result" style="display:none;">' +
            '<div class="flex ac jsb mt10">' +
            '<span class="xingxy-coupon-desc muted-color" id="xingxy-coupon-desc"></span>' +
            '<span class="xingxy-coupon-discount c-red" id="xingxy-coupon-discount"></span>' +
            '</div>' +
            '</div>' +
            '<div class="xingxy-coupon-error" id="xingxy-coupon-error" style="display:none;">' +
            '<div class="mt10 c-red em09" id="xingxy-coupon-error-msg"></div>' +
            '</div>' +
            '</div>';
    }

    /**
     * 获取确认弹窗中的 PetiteVue 响应式数据对象
     * 商城弹窗使用 PetiteVue，数据存储在 window.VueShopConfirmData 上
     */
    function getVueInstance() {
        return window.VueShopConfirmData || null;
    }

    /**
     * 注入优惠码 UI 到确认弹窗
     */
    function injectCouponUI() {
        // 防止重复注入
        if ($('#xingxy-coupon-box').length) {
            return;
        }

        // 找到价格信息区域（.order-info-box）并在其后插入
        var $orderInfoBox = $('#shop-confirm-modal .order-info-box').last();
        if ($orderInfoBox.length) {
            $orderInfoBox.after(getCouponHTML());
        } else {
            // 备选：在支付按钮之前插入
            var $payBtn = $('#shop-confirm-modal .order-pay-btn').first();
            if ($payBtn.length) {
                $payBtn.before(getCouponHTML());
            }
        }

        // 重置状态
        resetCouponState();

        // 绑定事件
        bindCouponEvents();
    }

    /**
     * 重置优惠码状态
     */
    function resetCouponState() {
        couponState.code = '';
        couponState.verified = false;
        couponState.discountAmount = 0;
        couponState.discountedPrice = 0;
        couponState.originalPayPrice = 0;
        couponState.desc = '';
        couponState.checking = false;
        couponState.vueInstance = null;
    }

    /**
     * 绑定优惠码相关事件
     */
    function bindCouponEvents() {
        // 检查按钮点击
        $(document).off('click.xingxyCoupon', '#xingxy-coupon-check-btn')
            .on('click.xingxyCoupon', '#xingxy-coupon-check-btn', function () {
                var code = $('#xingxy-coupon-input').val().trim();
                if (couponState.verified && code === couponState.code) {
                    // 已验证同一优惠码，执行取消
                    cancelCoupon();
                    return;
                }
                checkCoupon(code);
            });

        // 回车触发检查
        $(document).off('keypress.xingxyCoupon', '#xingxy-coupon-input')
            .on('keypress.xingxyCoupon', '#xingxy-coupon-input', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#xingxy-coupon-check-btn').trigger('click');
                }
            });

        // 输入变化时清除验证状态（如果修改了已验证的码）
        $(document).off('input.xingxyCoupon', '#xingxy-coupon-input')
            .on('input.xingxyCoupon', '#xingxy-coupon-input', function () {
                if (couponState.verified && $(this).val().trim() !== couponState.code) {
                    cancelCoupon();
                }
            });
    }

    /**
     * 发送 AJAX 验证优惠码
     */
    function checkCoupon(code) {
        if (!code) {
            showError('请输入优惠码');
            return;
        }

        if (couponState.checking) {
            return;
        }

        // 获取 Vue 实例以读取当前价格
        var vue = getVueInstance();
        if (!vue) {
            showError('页面状态异常，请刷新重试');
            return;
        }

        // 获取当前支付价格（优惠活动折扣后的价格）
        var payPrice = vue.total_data ? vue.total_data.pay_price : 0;
        if (payPrice <= 0) {
            showError('订单金额异常');
            return;
        }

        // 获取商品ID列表
        var productIds = [];
        if (vue.product_data) {
            for (var pid in vue.product_data) {
                productIds.push(parseInt(pid));
            }
        }

        couponState.checking = true;
        var $btn = $('#xingxy-coupon-check-btn');
        var $input = $('#xingxy-coupon-input');
        $btn.prop('disabled', true).text('检查中...');
        $input.prop('disabled', true);

        $.ajax({
            url: _win.ajax_url || (window.ajaxurl || '/wp-admin/admin-ajax.php'),
            type: 'POST',
            data: {
                action: 'xingxy_shop_check_coupon',
                coupon: code,
                product_ids: productIds,
                order_price: payPrice,
            },
            dataType: 'json',
            success: function (res) {
                if (res.success && res.data) {
                    applyCoupon(res.data, vue, payPrice);
                } else {
                    showError(res.data ? res.data.msg : '优惠码无效');
                }
            },
            error: function () {
                showError('网络错误，请重试');
            },
            complete: function () {
                couponState.checking = false;
                $btn.prop('disabled', false);
                $input.prop('disabled', false);
                updateCheckButton();
            },
        });
    }

    /**
     * 应用优惠码：更新 UI 和 Vue 数据
     */
    function applyCoupon(data, vue, originalPayPrice) {
        couponState.code = data.coupon_code;
        couponState.verified = true;
        couponState.discountAmount = parseFloat(data.discount_amount);
        couponState.discountedPrice = parseFloat(data.discounted_price);
        couponState.originalPayPrice = originalPayPrice;
        couponState.desc = data.desc;
        couponState.vueInstance = vue;

        // 更新 Vue 组件中的价格
        if (vue.total_data) {
            vue.total_data.pay_price = couponState.discountedPrice;
        }

        // 更新 UI
        $('#xingxy-coupon-result').show();
        $('#xingxy-coupon-error').hide();
        $('#xingxy-coupon-desc').text(data.desc);
        $('#xingxy-coupon-discount').text('-¥' + data.discount_amount);
        $('#xingxy-coupon-status').html('<span class="badg badg-sm c-green"><i class="fa fa-check mr3"></i>已应用</span>');
        $('#xingxy-coupon-input').prop('disabled', true).addClass('xingxy-coupon-verified');

        updateCheckButton();
    }

    /**
     * 取消优惠码
     */
    function cancelCoupon() {
        if (!couponState.verified) return;

        var vue = couponState.vueInstance || getVueInstance();

        // 恢复原价
        if (vue && vue.total_data && couponState.originalPayPrice > 0) {
            vue.total_data.pay_price = couponState.originalPayPrice;
        }

        // 清理 UI
        $('#xingxy-coupon-result').hide();
        $('#xingxy-coupon-error').hide();
        $('#xingxy-coupon-status').html('');
        $('#xingxy-coupon-input').val('').prop('disabled', false).removeClass('xingxy-coupon-verified');

        // 重置状态
        couponState.code = '';
        couponState.verified = false;
        couponState.discountAmount = 0;
        couponState.discountedPrice = 0;
        couponState.originalPayPrice = 0;
        couponState.desc = '';
        couponState.vueInstance = null;

        updateCheckButton();
    }

    /**
     * 更新检查按钮文本
     */
    function updateCheckButton() {
        var $btn = $('#xingxy-coupon-check-btn');
        if (couponState.verified) {
            $btn.text('取消').removeClass('c-blue').addClass('c-yellow');
        } else {
            $btn.text('检查').removeClass('c-yellow').addClass('c-blue');
        }
    }

    /**
     * 显示错误信息
     */
    function showError(msg) {
        $('#xingxy-coupon-result').hide();
        $('#xingxy-coupon-error').show();
        $('#xingxy-coupon-error-msg').text(msg);
        $('#xingxy-coupon-status').html('');
    }

    /**
     * 拦截 AJAX 请求，对 shop_submit_order 追加优惠码参数
     */
    function interceptAjax() {
        // 使用 jQuery 的 ajaxPrefilter 拦截所有 AJAX 请求
        $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
            // 只拦截 shop_submit_order
            if (!options.data || typeof options.data !== 'string') return;
            if (options.data.indexOf('action=shop_submit_order') === -1) return;

            // 如果已验证优惠码，追加到请求数据
            if (couponState.verified && couponState.code) {
                options.data += '&coupon=' + encodeURIComponent(couponState.code);
            }
        });
    }

    /**
     * 监听确认弹窗的显示事件
     */
    function watchConfirmModal() {
        // 使用 MutationObserver 监听弹窗 DOM 变化
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) {
                            // 检查是否是确认弹窗或其内容
                            var $node = $(node);
                            if ($node.attr('id') === 'shop-confirm-modal' ||
                                $node.find('#shop-confirm-modal').length ||
                                $node.find('.order-info-box').length) {
                                // 延迟注入，等待 Vue 渲染完成
                                setTimeout(function () {
                                    injectCouponUI();
                                }, 500);
                            }
                        }
                    });
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });

        // 也监听 modal 的 shown 事件（支持已存在的弹窗重复打开）
        $(document).on('shown.bs.modal shown.zib.drawer', '#shop-confirm-modal', function () {
            setTimeout(function () {
                injectCouponUI();
            }, 300);
        });

        // 弹窗关闭时清理状态
        $(document).on('hidden.bs.modal hidden.zib.drawer', '#shop-confirm-modal', function () {
            resetCouponState();
        });
    }

    /**
     * 初始化
     */
    function init() {
        // 拦截 AJAX
        interceptAjax();

        // 监听弹窗
        watchConfirmModal();

        // 如果弹窗已开启（页面刷新等场景）
        if ($('#shop-confirm-modal').is(':visible')) {
            setTimeout(function () {
                injectCouponUI();
            }, 500);
        }
    }

    // DOM 就绪后初始化
    $(document).ready(function () {
        init();
    });

})(jQuery);
