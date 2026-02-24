# 卡密发货守护：库存校验 & 部分发货 & 自动补发

## 概述

对 Zibll 商城的卡密自动发货流程进行全面增强：支付前校验库存、库存不足时部分发货、新卡密导入后自动补发、买家通知，以及支付成功弹窗的时序修复和发货信息的一键复制功能。

**更新日期**: 2026-02-24

## 核心功能

### 1. 发货守护 — Hook 劫持机制

通过 `init(999)` 时机摘掉 Zibll 原始的 `zib_shop_order_payment_success` 回调，替换为增强版 `xingxy_order_payment_success_guard`。

> ⚠️ 必须使用 `init` hook 而非 `after_setup_theme`，因为 Zibll 的 `pay.php` 在 `after_setup_theme` 之后才加载，过早执行 `remove_action` 会静默失败。

**三种分流逻辑**:
- **库存充足**（available ≥ count）→ 走原始 `zib_shop_auto_shipping`
- **部分有货**（0 < available < count）→ `xingxy_partial_shipping` 部分发货
- **完全无货**（available = 0）→ 走原始失败逻辑，通知商家手动发货

### 2. 部分发货

发出可用数量的卡密，订单显示部分发货通知（含进度条），同时：
- 注册到全局补发队列（`xingxy_pending_backlogs` option）
- 通知卖家补货

### 3. 自动补发

卖家导入新卡密时（`action-cardpass.php`）自动触发 `xingxy_auto_fulfill_backlogs`：
- 扫描补发队列，匹配相同 `card_pass_key` 的待补发订单
- 取出卡密追加到订单的 `delivery_content`
- 更新订单的 backlog 状态
- 全部补完后替换"部分发货通知"为"全部发货完成"通知
- 邮件 + ZibMsg 通知买家

## 新增文件（子主题 panda/xingxy/）

### inc/shipping-guard.php [已有，本次重大修改]

**核心函数**:
| 函数 | 说明 |
|------|------|
| `xingxy_order_payment_success_guard` | 增强版支付成功回调 |
| `xingxy_auto_shipping_guard` | 卡密发货拦截，库存校验+分流 |
| `xingxy_get_available_card_count` | 查询可用卡密数量 |
| `xingxy_partial_shipping` | 部分发货执行 |
| `xingxy_build_partial_notice` | 部分发货通知 UI |
| `xingxy_build_completed_notice` | 全部发货完成通知 UI |
| `xingxy_build_fulfill_notice` | 补发成功通知 UI |
| `xingxy_register_pending_backlog` | 注册补发队列 |
| `xingxy_remove_pending_backlog` | 移除补发队列 |
| `xingxy_auto_fulfill_backlogs` | 自动补发核心逻辑 |
| `xingxy_notify_buyer_fulfilled` | 补发完成通知买家 |
| `xingxy_notify_seller_backlog` | 通知卖家补货 |

## 修改文件（父主题 zibll/）⚠️

### 1. inc/functions/shop/inc/pay.php

**文件路径**: `zibll/inc/functions/shop/inc/pay.php`

**修改内容**:
- 函数 `zib_shop_pay_success_modal_notice_footer` 中的弹窗逻辑（第 217 行附近）
- `shipping_status == 0` 时不再粗暴显示"自动发货失败"
- 优先检查 `delivery_content` 是否已有发货内容，有则直接展示
- 无内容时显示中性提示"正在为您自动发货，请前往订单中心查看"
- 所有自动发货弹窗统一添加"前往订单中心查看"蓝色按钮（点击时清除弹窗 Cookie 避免重复弹出）

### 2. inc/functions/shop/action/action.php

**文件路径**: `zibll/inc/functions/shop/action/action.php`

**修改内容**:
- 函数 `zib_shop_ajax_order_delivery_content_modal`（第 947 行附近）
- 发货内容 div 添加 `id="xingxy-delivery-content"`
- 内容区后追加"复制全部内容"蓝色按钮
- 复制逻辑：优先用 `data-clipboard-text` 精准提取卡密数据（卡号、卡密），无此属性时 fallback 到 `innerText`（排除 `data-no-copy` 元素）

## 修改文件（子主题 panda/xingxy/）

### 3. inc/action-cardpass.php

**修改内容**:
- `xingxy_ajax_import_cardpass` 函数末尾追加 `xingxy_auto_fulfill_backlogs` 触发
- 导入成功消息包含补发结果信息

## 关键设计决策

### Hook 劫持时序
使用 `add_action('init', ..., 999)` 而非 `after_setup_theme`。Zibll 的 shop 模块在 `init` 阶段加载，必须等其注册完毕后 `remove_action` 才有效。

### 通知卡片 data-no-copy 属性
三个通知函数（partial/completed/fulfill）的最外层 div 均带有 `data-no-copy="1"` 属性，复制按钮的 JS 会忽略这些元素。

### 弹窗 Cookie 机制
Zibll 通过 `shop_pay_success_notice` Cookie 触发弹窗。"前往订单中心查看"按钮在跳转前清除此 Cookie，避免新页面再次弹窗。

## 恢复方法

主题更新后，需恢复 `pay.php` 和 `action.php` 的修改：
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/zibll
git diff HEAD~1 -- inc/functions/shop/inc/pay.php
git diff HEAD~1 -- inc/functions/shop/action/action.php
```

子主题文件（`shipping-guard.php`、`action-cardpass.php`）不受主题更新影响。
