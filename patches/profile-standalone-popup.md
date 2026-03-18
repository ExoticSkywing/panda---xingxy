# 独立画像弹窗（兜底直注册用户）

**日期**: 2026-03-18

## 问题背景

画像问卷系统仅挂钩在 Zibll 的「绑定邮箱」流程（`zib_user_bind_email` action + `profile-capture.js` 监听验证码倒计时）。通过邮箱直接注册的用户（如后台用户 arina），注册时已自带 email，永远不会触发「绑定邮箱」弹窗，因此画像问卷从未展示，用户画像数据中心中无该用户记录。

## 解决方案

新增独立画像弹窗机制：对已登录但无 `xingxy_profile_data` 的用户，在前台页面通过 `wp_footer` 输出 Bootstrap modal，由 JS 自动注入问卷并通过独立 AJAX 端点提交。复用现有问卷 UI、盲盒积分发放逻辑和 confetti 特效。

### Cookie 周期控制

仿照 Zibll `zib_bind_reminder_modal()` 的机制：
- Cookie 名：`xingxy_profile_reminded`
- 过期时间：后台可配置（小时），转换为天写入 `$.cookie`
- 用户完成问卷后 `xingxy_profile_data` meta 写入，此后永不再弹

## 涉及文件

| 文件 | 改动类型 | 说明 |
|------|----------|------|
| `inc/options.php` | MODIFY | 新增 3 个 CSF 配置项：启用开关、弹窗文案、提醒周期 |
| `inc/user-profile.php` | MODIFY | 新增 `xingxy_profile_reminder_modal()`（wp_footer 弹窗）+ `xingxy_submit_profile_standalone()`（AJAX 端点） |
| `assets/js/profile-capture.js` | MODIFY | 新增 `injectStandaloneProfile()` 注入逻辑 + 独立提交按钮事件 + `updateProfileStatus` 兼容独立模式 |

## 后台配置

路径：**星小雅高级定制 → 画像采集 (免感知)**

| 配置项 | ID | 默认值 | 说明 |
|--------|----|--------|------|
| 启用独立画像弹窗 | `profile_popup_enabled` | 开 | 对无画像数据的用户弹窗 |
| 弹窗提示文案 | `profile_popup_text` | 完成下方探索问卷... | 支持 HTML |
| 提醒周期 | `profile_popup_expires` | 24 小时 | 0 = 每次刷新都弹 |

## 用户流程

```
登录 → wp_footer 检测无画像 & 无 Cookie → 1.2s 后弹出 modal
→ JS 自动注入三步问卷 → 用户完成选择 → 点击"🎁 开启盲盒"
→ AJAX POST xingxy_submit_profile_standalone → 积分发放 + confetti
→ 1.5s 后 modal 关闭 → Cookie 写入（周期内不再弹）
```

## 验证

1. 用直注册账号（如 arina）登录前台，应弹出画像问卷弹窗
2. 完成三步问卷后积分到账，confetti 特效播放
3. 刷新页面，Cookie 周期内不再弹出
4. 已有画像数据的用户永不弹出
5. 后台关闭开关后所有用户不弹
6. 原有邮箱绑定流程中的问卷注入不受影响
