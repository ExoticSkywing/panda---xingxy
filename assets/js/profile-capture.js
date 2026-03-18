jQuery(document).ready(function ($) {
    if (typeof xingxy_profile === 'undefined') return;

    let profileData = null;
    let selectedIds = { dim1: [], dim2: [], dim3: [] };
    let reqLimits = { dim1: 4, dim2: 2, dim3: 1 }; // 默认必须达标的数量

    // 获取后台选项数据
    function fetchProfileOptions(callback) {
        if (profileData) {
            callback(profileData);
            return;
        }
        $.ajax({
            url: xingxy_profile.ajaxurl,
            type: 'POST',
            data: { action: 'xingxy_get_profile_options' },
            success: function (res) {
                if (res.success && res.data) {
                    profileData = res.data;

                    // 根据后台上传的元素总数动态降级要求，避免卡死
                    reqLimits.dim1 = Math.min(4, profileData.dim1 ? profileData.dim1.length : 0);
                    reqLimits.dim2 = Math.min(2, profileData.dim2 ? profileData.dim2.length : 0);
                    reqLimits.dim3 = Math.min(1, profileData.dim3 ? profileData.dim3.length : 0); // 应用户要求调回 1

                    callback(profileData);
                }
            }
        });
    }

    // 构建 HTML (分布滑屏版)
    function buildProfileHtml(data) {
        let t1 = (data.titles && data.titles.dim1) ? data.titles.dim1 : '1. 说实话，看到谁让你心动过？';
        let t2 = (data.titles && data.titles.dim2) ? data.titles.dim2 : '2. 如果有台时光机，你想穿越回哪儿？';
        let t3 = (data.titles && data.titles.dim3) ? data.titles.dim3 : '3. 回忆一下，第一次充钱给了谁？';
        // ================= 全新原生风福利横幅 =================
        let rewardBanner = `
        <div class="xingxy-native-banner" style="margin-bottom: 20px;">
            <div class="x-nb-icon">🎁</div>
            <div class="x-nb-content">
                <h4 class="x-nb-title">专属盲盒福利 <span class="x-nb-tag">彩蛋</span></h4>
                <p class="x-nb-desc"><span class="x-nb-highlight">填验证码</span> 并完成下方探索即可解锁！</p>
            </div>
        </div>
        `;

        let html = `
        <div class="xingxy-profile-capture-wrap">
            ${rewardBanner}
            <div class="xingxy-profile-steps-container">
        `;

        // 步骤 1：维度一
        if (data.dim1 && data.dim1.length > 0) {
            html += `
            <div class="xingxy-profile-step step-1 is-active" data-step="1">
                <div class="xingxy-profile-dim dim1-wrap">
                    <div class="xingxy-profile-dim-title">
                        <span>${t1}</span>
                        <span class="dim-req" data-req="${reqLimits.dim1}">至少选 ${reqLimits.dim1} 个 (支持多选)</span>
                    </div>
                    <div class="xingxy-profile-grid dim1-items">
            `;
            data.dim1.forEach(item => {
                html += `
                        <div class="xingxy-profile-item" data-dim="1" data-id="${item.id}">
                            <div class="xingxy-profile-badge dim1-badge"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#ff4d4f" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></div>
                            <img src="${item.image}" class="xingxy-profile-img" alt="${item.name}">
                            <span class="xingxy-profile-name">${item.name}</span>
                        </div>
                `;
            });
            html += `</div></div></div>`;
        }

        // 步骤 2：维度二
        if (data.dim2 && data.dim2.length > 0) {
            html += `
            <div class="xingxy-profile-step step-2" data-step="2">
                <div class="xingxy-profile-dim dim2-wrap">
                    <div class="xingxy-profile-dim-title">
                        <span>${t2}</span>
                        <span class="dim-req" data-req="${reqLimits.dim2}">至少选 ${reqLimits.dim2} 个 (支持多选)</span>
                    </div>
                    <div class="xingxy-profile-grid dim2-items">
            `;
            data.dim2.forEach(item => {
                html += `
                        <div class="xingxy-profile-item" data-dim="2" data-id="${item.id}">
                            <div class="xingxy-profile-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></div>
                            <img src="${item.image}" class="xingxy-profile-img" alt="${item.name}">
                            <span class="xingxy-profile-name">${item.name}</span>
                        </div>
                `;
            });
            html += `</div></div></div>`;
        }

        // 步骤 3：维度三
        if (data.dim3 && data.dim3.length > 0) {
            html += `
            <div class="xingxy-profile-step step-3" data-step="3">
                <div class="xingxy-profile-dim dim3-wrap">
                    <div class="xingxy-profile-dim-title">
                        <span>${t3}</span>
                        <span class="dim-req" data-req="${reqLimits.dim3}">请选出 ${reqLimits.dim3} 个</span>
                    </div>
                    <div class="dim3-items">
            `;
            data.dim3.forEach(item => {
                let iconHtml = '';
                if (item.icon.indexOf('zibsvg-') > -1) {
                    let svgName = item.icon.replace('zibsvg-', '');
                    // 尝试从 Zibll 全局变量中提取真实 SVG path 数据，免去 sprite 加载前置条件
                    let rawPath = '';
                    if (typeof zib_svgs !== 'undefined' && zib_svgs[svgName]) rawPath = zib_svgs[svgName];
                    else if (typeof _win !== 'undefined' && _win.svgs && _win.svgs[svgName]) rawPath = _win.svgs[svgName];

                    if (rawPath) {
                        iconHtml = `<svg class="icon" aria-hidden="true" viewBox="0 0 1024 1024">${rawPath}</svg>`;
                    } else {
                        iconHtml = `<svg class="icon" aria-hidden="true"><use xlink:href="#icon-${svgName}"></use></svg>`;
                    }
                } else {
                    iconHtml = `<i class="${item.icon}"></i>`;
                }

                html += `
                        <div class="xingxy-profile-item is-icon-item" data-dim="3" data-id="${item.id}">
                            <div class="xingxy-profile-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></div>
                            <div class="xingxy-profile-icon">${iconHtml}</div>
                            <span class="xingxy-profile-name">${item.name}</span>
                        </div>
                `;
            });
            html += `</div></div></div>`;
        }

        html += `
            </div> <!-- 结束 steps-container -->
            <button type="button" class="xingxy-profile-next-btn disabled" disabled>继续探索 <i class="fas fa-arrow-right"></i></button>
        </div>`;
        return html;
    }

    // ✨ 惊喜彩蛋：庆祝撒花特效 ✨
    function xingxyPlayConfetti() {
        if (typeof confetti !== 'function') return;

        var count = 200;
        var defaults = {
            origin: { y: 0.7 },
            zIndex: 9999999 // 提升层级，确保在一切弹窗之上
        };

        function fire(particleRatio, opts) {
            confetti(Object.assign({}, defaults, opts, {
                particleCount: Math.floor(count * particleRatio)
            }));
        }

        fire(0.25, {
            spread: 26,
            startVelocity: 55,
        });
        fire(0.2, {
            spread: 60,
        });
        fire(0.35, {
            spread: 100,
            decay: 0.91,
            scalar: 0.8
        });
        fire(0.1, {
            spread: 120,
            startVelocity: 25,
            decay: 0.92,
            scalar: 1.2
        });
        fire(0.1, {
            spread: 120,
            startVelocity: 45,
        });
    }

    // 更新选中状态和计数
    function updateProfileStatus(formContext) {
        if (!formContext || formContext.length === 0) formContext = $('.xingxy-profile-capture-wrap.is-loaded').parents('form');

        formContext.each(function () {
            let context = $(this);
            let wrap = context.find('.xingxy-profile-capture-wrap');
            let currentStepEl = wrap.find('.xingxy-profile-step.is-active');
            let currentStepIdx = parseInt(currentStepEl.data('step')) || 1;

            // 动态确保表单内永远挂载有隐藏域（不在包裹器内，直接插在表单最底部防止被屏蔽）
            if (context.find('input[name="profile_dim1"]').length === 0) {
                context.append('<input type="hidden" name="profile_dim1" class="profileDim1Input" value="">');
                context.append('<input type="hidden" name="profile_dim2" class="profileDim2Input" value="">');
                context.append('<input type="hidden" name="profile_dim3" class="profileDim3Input" value="">');
            }

            let dim_count = 0;
            let dim_req_num = 0;

            if (currentStepIdx === 1) {
                dim_count = selectedIds.dim1.length;
                dim_req_num = reqLimits.dim1;
                let req_el = context.find('.dim1-wrap .dim-req');
                if (dim_req_num > 0) {
                    req_el.text(`已选 ${dim_count} 个 / 需 ${dim_req_num} 个`);
                    if (dim_count >= dim_req_num) req_el.addClass('is-ok').text(`✔ 达标 (共选 ${dim_count} 个)`); else req_el.removeClass('is-ok');
                }
            } else if (currentStepIdx === 2) {
                dim_count = selectedIds.dim2.length;
                dim_req_num = reqLimits.dim2;
                let req_el = context.find('.dim2-wrap .dim-req');
                if (dim_req_num > 0) {
                    req_el.text(`已选 ${dim_count} 个 / 需 ${dim_req_num} 个`);
                    if (dim_count >= dim_req_num) req_el.addClass('is-ok').text(`✔ 达标 (共选 ${dim_count} 个)`); else req_el.removeClass('is-ok');
                }
            } else if (currentStepIdx === 3) {
                dim_count = selectedIds.dim3.length;
                dim_req_num = reqLimits.dim3;
                let req_el = context.find('.dim3-wrap .dim-req');
                if (dim_req_num > 0) {
                    req_el.text(`已选 ${dim_count} / 至少选 ${dim_req_num} 个`);
                    if (dim_count >= dim_req_num) req_el.addClass('is-ok').text(`✔ 达标 (已选 ${dim_count} 个)`); else req_el.removeClass('is-ok');
                }
            }

            // 写入隐藏域供提交
            context.find('.profileDim1Input').val(selectedIds.dim1.join(','));
            context.find('.profileDim2Input').val(selectedIds.dim2.join(','));
            context.find('.profileDim3Input').val(selectedIds.dim3.join(','));

            // 按钮控制策略更新：
            // 如果不是最后一步，则控制“下一步”按钮。
            // 如果是最后一步，则同时允许真实的提交按钮解禁。
            let isCurrentStepValid = dim_count >= dim_req_num;
            let nextBtn = wrap.find('.xingxy-profile-next-btn');
            let isStandalone = context.hasClass('xingxy-standalone-profile-form');
            let submitBtn = isStandalone
                ? context.find('.xingxy-standalone-submit')
                : context.find('button.signsubmit-loader[type="button"], button[type="submit"]').last();

            if (isCurrentStepValid) {
                nextBtn.prop('disabled', false).removeClass('disabled').text('继续探索 🚀');

                // 如果且仅如果所有维度都满了，放行真实提交按钮
                if (selectedIds.dim1.length >= reqLimits.dim1 &&
                    selectedIds.dim2.length >= reqLimits.dim2 &&
                    selectedIds.dim3.length >= reqLimits.dim3) {
                    submitBtn.prop('disabled', false).removeClass('disabled').css({ 'opacity': '1', 'cursor': 'pointer' }).removeAttr('data-xingxy-disabled');

                    // 放弃模拟点击，直接劫持真实按钮的视觉，让用户真正点到 Zibll 的原生提交按钮上！
                    submitBtn.text(isStandalone ? '🎁 开启盲盒' : '🎁 开启盲盒并完成绑定').addClass('btn-primary').show();

                    // 将这最后一步的引导下一步按钮隐藏，用户只需要直接点击 submitBtn
                    nextBtn.hide();
                } else {
                    submitBtn.text(submitBtn.data('original-text') || '提交保存'); // 还原
                    nextBtn.show();
                }
            } else {
                nextBtn.prop('disabled', true).addClass('disabled').text('继续探索 →');
                submitBtn.attr('data-original-text', submitBtn.text() || '提交保存');
                submitBtn.prop('disabled', true).addClass('disabled').css({ 'opacity': '0.5', 'cursor': 'not-allowed' }).attr('data-xingxy-disabled', '1').hide();
            }
        });
    }

    // 交互绑定
    function bindProfileEvents() {
        $(document).on('click', '.xingxy-profile-item', function () {
            let $this = $(this);
            let dim = $this.data('dim');
            let id = $this.data('id').toString();
            let is_active = $this.hasClass('is-active');

            if (dim == 1) {
                if (!is_active && selectedIds.dim1.length >= reqLimits.dim1 && reqLimits.dim1 > 0) return tb_msg(`最多只能选择 ${reqLimits.dim1} 个人物`, 'warning');
            } else if (dim == 2) {
                if (!is_active && selectedIds.dim2.length >= 3) return tb_msg('最多只能选择 3 组游戏', 'warning');
            } else if (dim == 3) {
                if (!is_active && selectedIds.dim3.length >= 2) return tb_msg('最多只能选择 2 组事件', 'warning');
            }

            if (is_active) {
                $this.removeClass('is-active');
                selectedIds['dim' + dim] = selectedIds['dim' + dim].filter(val => val !== id);
            } else {
                $this.addClass('is-active');
                selectedIds['dim' + dim].push(id);
            }

            updateProfileStatus($this.closest('form'));
        });

        // 绑定下一步按钮事件
        $(document).on('click', '.xingxy-profile-next-btn', function (e) {
            e.preventDefault();
            if ($(this).prop('disabled') || $(this).hasClass('disabled')) return;

            let wrap = $(this).closest('.xingxy-profile-capture-wrap');
            let currentStepEl = wrap.find('.xingxy-profile-step.is-active');
            let nextStepEl = currentStepEl.next('.xingxy-profile-step');

            // 如果还有下一题
            if (nextStepEl.length > 0) {
                // 滑出当前
                currentStepEl.removeClass('is-active').addClass('is-hiding');
                setTimeout(() => {
                    currentStepEl.hide().removeClass('is-hiding');
                    // 滑入下一个
                    nextStepEl.show();
                    setTimeout(() => {
                        nextStepEl.addClass('is-active');
                        updateProfileStatus(wrap.closest('form'));
                    }, 50);
                }, 300); // 配合 CSS 动画时长
            }
        });

        // ✨ 绑定原生提交按钮（实装盲盒开启特效） 
        $(document).on('click', '.xingxy-profile-capture-wrap ~ button.signsubmit-loader, .xingxy-profile-capture-wrap ~ button[type="submit"], form.is-xingxy-injecting button.signsubmit-loader, form.is-xingxy-injecting button[type="submit"]', function (e) {
            let $btn = $(this);
            // 只有当该按钮解除了我们的禁用锁定并且真正触发了“开启盲盒”时，才播放烟花
            if (!$btn.prop('disabled') && !$btn.hasClass('disabled') && $btn.attr('data-xingxy-disabled') !== '1') {
                xingxyPlayConfetti();
            }
        });

        // ✨ 独立弹窗提交按钮：直接 AJAX 提交问卷数据
        $(document).on('click', '.xingxy-standalone-submit', function (e) {
            e.preventDefault();
            let $btn = $(this);
            if ($btn.prop('disabled') || $btn.hasClass('disabled')) return;

            // 所有维度必须达标
            if (selectedIds.dim1.length < reqLimits.dim1 ||
                selectedIds.dim2.length < reqLimits.dim2 ||
                selectedIds.dim3.length < reqLimits.dim3) return;

            $btn.prop('disabled', true).text('提交中...');

            $.ajax({
                url: xingxy_profile.ajaxurl,
                type: 'POST',
                data: {
                    action: 'xingxy_submit_profile_standalone',
                    profile_dim1: selectedIds.dim1.join(','),
                    profile_dim2: selectedIds.dim2.join(','),
                    profile_dim3: selectedIds.dim3.join(',')
                },
                success: function (res) {
                    if (res.success) {
                        xingxyPlayConfetti();
                        if (typeof tb_msg === 'function') tb_msg(res.data.message || '画像采集完成！', 'success');
                        setTimeout(function () {
                            $('#xingxy_profile_popup').modal('hide');
                        }, 1500);
                    } else {
                        $btn.prop('disabled', false).text('🎁 开启盲盒');
                        if (typeof tb_msg === 'function') tb_msg(res.data || '提交失败，请重试', 'error');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text('🎁 开启盲盒');
                    if (typeof tb_msg === 'function') tb_msg('网络异常，请重试', 'error');
                }
            });
        });
    }

    // 监听 Zibll 模态框打开 / 或特定表单渲染
    function injectProfileDOM() {
        let captureClass = '.xingxy-profile-capture-wrap';

        let bindForms = $('form').filter(function () {
            let $el = $(this);
            return $el.find('input[name="email"], input[type="email"], input[name="phone"]').length > 0 &&
                $el.find('.captchsubmit, .send-capt-code').length > 0;
        });

        if (bindForms.length === 0) return;

        bindForms.each(function () {
            let bindForm = $(this);

            // 寻找发送验证码的按钮
            let captBtn = bindForm.find('.captchsubmit, .send-capt-code');
            let btnText = captBtn.text() || '';

            // 关键逻辑：是否已经且正在发送验证码（倒计时中/禁用中）
            let isSent = btnText.indexOf('秒') > -1 || captBtn.prop('disabled') || captBtn.hasClass('disabled');

            if (!isSent) return;

            // 这里使用 class 查找该 form 内是否已经注入过了
            if (bindForm.find(captureClass).length === 0 && !bindForm.hasClass('is-xingxy-injecting')) {
                bindForm.addClass('is-xingxy-injecting'); // 添加竞态锁
                fetchProfileOptions(function (data) {
                    if (reqLimits.dim1 === 0 && reqLimits.dim2 === 0 && reqLimits.dim3 === 0) {
                        bindForm.removeClass('is-xingxy-injecting');
                        return; // 全空无需渲染
                    }

                    // 二次检查保障
                    if (bindForm.find(captureClass).length > 0) return;

                    let injectedNode = $(buildProfileHtml(data));

                    let targetPoint = bindForm.find('button.signsubmit-loader[type="button"], button[type="submit"], button.btn-primary').last();

                    if (targetPoint.length) {
                        targetPoint.before(injectedNode);
                    } else {
                        bindForm.append(injectedNode);
                    }

                    // 用变量精准给刚才插入的节点赋予显示动画，永不发生 ID 冲突选取错误元素的问题
                    injectedNode.addClass('is-loaded');
                    selectedIds = { dim1: [], dim2: [], dim3: [] };

                    // Zibll 的提交按钮通常是没有 type="submit" 而是 type="button" class="signsubmit-loader"
                    let submitBtn = bindForm.find('button.signsubmit-loader[type="button"], button[type="submit"]').last();
                    submitBtn.prop('disabled', true).addClass('disabled');
                    // 修正 Zibll 自带的置灰样式
                    submitBtn.css({ 'opacity': '0.5', 'cursor': 'not-allowed' });

                    // 挂载一个原始的验证检查引用
                    submitBtn.attr('data-xingxy-disabled', '1');

                    updateProfileStatus(bindForm);
                });
            }
        });
    }

    // 当验证码按钮被点击或者文本发生变化时 (用 setInterval 监控 DOM 变化)
    // Zibll 开始倒计时
    let btnMonitor = setInterval(function () {
        let captBtn = $('.captchsubmit, .send-capt-code');
        if (captBtn.length > 0) {
            let txt = captBtn.text() || '';
            if (txt.indexOf('秒') > -1) {
                injectProfileDOM();
            }
        }
    }, 1000);

    // 每当弹窗 DOM 发生变化或 AJAX 加载时，尝试注入
    // Zibll 弹窗通常通过 AJAX 加载完成
    $(document).ajaxComplete(function (event, xhr, settings) {
        setTimeout(injectProfileDOM, 200);
    });

    // 监听 Zibll 原生的 modal 事件
    $(document).on('shown.bs.modal', '.modal', function () {
        setTimeout(injectProfileDOM, 100);
    });

    // 防止子比原本的 js 把我们的禁用状态冲掉
    $(document).on('change keyup', 'form input', function () {
        let form = $(this).closest('form');
        let submitBtn = form.find('button.signsubmit-loader[type="button"], button[type="submit"]').last();
        if (submitBtn.attr('data-xingxy-disabled') === '1') {
            setTimeout(() => {
                submitBtn.prop('disabled', true).addClass('disabled').css({ 'opacity': '0.5', 'cursor': 'not-allowed' });
            }, 50);
        }
    });

    // ✨ 终极必杀技：全局拦截 Zibll 的 AJAX 请求，把我们的隐藏数据强行塞进去 ✨
    // 【核心发现】Zibll 的 zib_ajax 函数用 $.ajax({data: jsObject}) 发送请求
    // 在 $.ajaxPrefilter 执行阶段，options.data 依然是 JavaScript Object（不是字符串！）
    // jQuery 会在 prefilter 之后才调用 $.param() 将 Object 转为查询字符串
    // 所以我们必须用 options.data.action 来匹配！
    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        let isTarget = false;

        // 情况1：data 是纯 Object（Zibll zib_ajax 的标准模式，最常见！）
        if (options.data && typeof options.data === 'object' && !(options.data instanceof FormData) && options.data.action === 'user_bind_email') {
            isTarget = true;
        }
        // 情况2：data 已经被转为字符串（备用）
        else if (typeof options.data === 'string' && options.data.indexOf('action=user_bind_email') !== -1) {
            isTarget = true;
        }
        // 情况3：FormData 对象（备用）
        else if (options.data instanceof FormData && options.data.get && options.data.get('action') === 'user_bind_email') {
            isTarget = true;
        }
        // 情况4：action 在 URL 上（备用）
        else if (options.url && options.url.indexOf('action=user_bind_email') !== -1) {
            isTarget = true;
        }

        if (isTarget && selectedIds.dim1 && selectedIds.dim1.length > 0) {
            let dim1str = selectedIds.dim1.join(',');
            let dim2str = selectedIds.dim2.join(',');
            let dim3str = selectedIds.dim3.join(',');

            // 纯 Object —— 直接挂属性（最常见路径）
            if (typeof options.data === 'object' && !(options.data instanceof FormData) && options.data !== null) {
                options.data.profile_dim1 = dim1str;
                options.data.profile_dim2 = dim2str;
                options.data.profile_dim3 = dim3str;
            }
            // 字符串拼接
            else if (typeof options.data === 'string') {
                options.data += `&profile_dim1=${encodeURIComponent(dim1str)}&profile_dim2=${encodeURIComponent(dim2str)}&profile_dim3=${encodeURIComponent(dim3str)}`;
            }
            // FormData append
            else if (options.data instanceof FormData) {
                options.data.append('profile_dim1', dim1str);
                options.data.append('profile_dim2', dim2str);
                options.data.append('profile_dim3', dim3str);
            }
        }
    });

    // ====== 独立画像弹窗注入 ======
    function injectStandaloneProfile() {
        let standaloneForm = $('.xingxy-standalone-profile-form');
        if (standaloneForm.length === 0) return;
        if (standaloneForm.find('.xingxy-profile-capture-wrap').length > 0) return; // 已注入

        fetchProfileOptions(function (data) {
            if (reqLimits.dim1 === 0 && reqLimits.dim2 === 0 && reqLimits.dim3 === 0) return;
            if (standaloneForm.find('.xingxy-profile-capture-wrap').length > 0) return;

            let injectedNode = $(buildProfileHtml(data));
            let submitBtn = standaloneForm.find('.xingxy-standalone-submit');
            submitBtn.before(injectedNode);
            injectedNode.addClass('is-loaded');
            selectedIds = { dim1: [], dim2: [], dim3: [] };

            // 初始状态：禁用提交按钮
            submitBtn.prop('disabled', true).addClass('disabled').css({ 'opacity': '0.5', 'cursor': 'not-allowed' }).attr('data-xingxy-disabled', '1');
            updateProfileStatus(standaloneForm);
        });
    }

    // 监听独立弹窗打开
    $(document).on('shown.bs.modal', '#xingxy_profile_popup', function () {
        setTimeout(injectStandaloneProfile, 100);
    });

    // 页面加载时如果弹窗已存在也尝试注入
    if ($('#xingxy_profile_popup').length > 0) {
        setTimeout(injectStandaloneProfile, 1500);
    }

    // 初始化绑定点击事件
    bindProfileEvents();

});
