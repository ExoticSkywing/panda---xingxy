# TG Bot 引流卡片 & 积分互通

**更新日期**: 2026-03-01

## 概述

在积分任务页面新增 TG Bot 引流卡片，引导用户前往 Telegram Bot（小芽精灵）领取额外积分奖励。同时打通 TG Bot 与站点的积分兑换通道。

## 新增功能

### 1. TG Bot 引流大卡片（积分页）

在 `zibpay-points.php` 的积分任务列表中注入 TG Bot 引流卡片，展示 Bot 的签到/邀请/绑定奖励信息，并提供「前往领取」按钮。

### 2. 后台配置项

在 xingxy 配置面板 (`options.php`) 中新增 `tg_bot_url` 配置项，管理员可在后台设置 TG Bot 链接。

### 3. 积分互通 REST 端点（zibll-oauth 侧）

通过 `zibll-oauth` 插件新增的 REST 端点实现双向数据查询：

| 端点 | 方法 | 用途 |
|------|------|------|
| `/points/add` | POST | TG 积分兑换为站点积分 |
| `/points/balance` | GET | TG Bot 查询用户站点积分余额 |
| `/user/profile` | GET | TG Bot 查询用户站点昵称和推荐人数 |

## 涉及文件

### 父主题（panda/zibll）修改

| 文件 | 修改内容 |
|------|----------|
| `zibpay/functions/zibpay-points.php` | 注入 TG Bot 引流卡片 HTML |

### 子主题（xingxy）修改

| 文件 | 修改内容 |
|------|----------|
| `inc/options.php` | 新增 `tg_bot_url` 配置字段 |
| `assets/css/referral.css` | TG 卡片样式（赛博科技风 + 玻璃质感按钮） |

### 插件（zibll-oauth）修改

| 文件 | 修改内容 |
|------|----------|
| `includes/rest-points.php` | 新增 `add()` / `balance()` / `profile()` 端点 |
| `includes/rest.php` | 注册 `/points/add`、`/points/balance`、`/user/profile` 路由 |

## 恢复方法

主题更新后，TG 引流卡片相关代码在 `zibpay-points.php` 中，搜索 `xingxy-tg-card` 定位插入位置：

```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/panda
git diff HEAD -- zibpay/functions/zibpay-points.php
```
