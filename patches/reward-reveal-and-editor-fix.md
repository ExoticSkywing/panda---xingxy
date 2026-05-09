# 盲盒奖励揭晓 & 编辑器 beforeunload 修复 & BBS 标签权限优化

**更新日期**: 2026-05-08

## 1. 盲盒奖励揭晓弹窗

### 问题描述
用户完成三步画像问卷后点击"开启盲盒"，交互戛然而止——150 金币已到账但用户完全不知道获得了什么奖励。

### 根因
- **邮箱绑定流程**：Zibll 的 `sign-register.js` 绑定成功后立即执行 `window.location.reload()`，JS 上下文瞬间丢失，任何基于 `ajaxComplete` 的后续操作都来不及执行。
- **独立问卷流程**：AJAX 成功回调仅弹 `tb_msg` toast 后 1.5s 关闭弹窗，无视觉奖励反馈。

### 解决方案
- **Cookie 传递机制**（邮箱绑定流程）：PHP 发奖时写 `xingxy_reward=150` cookie（httpOnly=false，120s 有效），页面 reload 后 JS 在 `document.ready` 中读取 cookie → 清除 cookie → 弹出揭晓弹窗。
- **直接响应**（独立问卷流程）：PHP 返回 `{success: true, data: {reward: 150}}`，JS 根据 `res.data.reward` 直接显示揭晓动画。
- **奖励揭晓 UI**：全屏 overlay + 弹性入场动画 + 渐变金色数字 +  canvas-confetti 撒花 + 8s 自动关闭。

### 涉及文件
| 文件 | 变更 |
|------|------|
| `inc/user-profile.php` | 发奖时 `setcookie()`；新增 `xingxy_check_reward` AJAX 端点；独立流程返回 `reward` 字段 |
| `assets/js/profile-capture.js` | 新增 `showRewardReveal()` 揭晓 UI + `checkRewardCookie()` 页面加载检测 |
| `assets/css/profile-capture.css` | 新增 `.xingxy-reward-overlay` 完整样式（动画 + 暗色模式适配） |
| `init.php` | 版本号 `1.0.0` → `1.1.1` 强制缓存刷新 |

---

## 2. 编辑器 beforeunload 弹窗修复

### 问题描述
发布星讯后，浏览器弹出"您有未保存的更改"确认框，用户体验极差。

### 根因
`panda/functions/action/global-action.php` 中的 `zib_editor_save` 函数通过劫持 `XMLHttpRequest` 判断 AJAX 成功后重置 `hasChanges`。但比较逻辑 `realXHR.responseURL == '../wp-admin/admin-ajax.php'` 使用的是相对路径，而 `responseURL` 实际是绝对 URL，导致条件永远不成立。

### 修复
```javascript
// before
if (realXHR.responseURL == '../wp-admin/admin-ajax.php') {
// after
if (realXHR.responseURL && realXHR.responseURL.indexOf('admin-ajax.php') !== -1) {
```

### 涉及文件
| 文件 | 变更 |
|------|------|
| `panda/functions/action/global-action.php` | 修复 `responseURL` 比较逻辑 |

---

## 3. BBS 标签权限系统优化

### 问题描述
1. 标签限制在 `allow_view=signin`（登录可查看）时仍生效，导致已登录用户被错误拦截。
2. 标签与 VIP/等级/认证是 AND 关系，用户必须同时满足所有条件才能访问。

### 修复逻辑
- 标签检查仅在 `allow_view=roles` 时才生效
- 标签匹配成功 → 直接显示内容（OR 关系，绕过 VIP/等级检查）
- 标签不匹配但存在真实 VIP/等级限制 → 交给 Zibll 原生判断
- 标签不匹配且无其他限制 → 自定义拒绝信息

### 涉及文件
| 文件 | 变更 |
|------|------|
| `xingxy/inc/bbs-segment-access.php` | 重写前台权限判断逻辑（新文件） |
