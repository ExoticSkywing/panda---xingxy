# 卡密发货守护：库存校验 & 部分发货 & 自动补发

## 概述

对 Zibll 商城的卡密自动发货流程进行全面增强：支付前校验库存、库存不足时部分发货、新卡密导入后自动补发、买家通知，以及支付成功弹窗 AJAX 轮询、发货信息一键复制、订单状态精确控制。

**更新日期**: 2026-02-25

## 核心功能

### 1. 发货守护 — Hook 劫持机制

通过 `init(999)` 时机摘掉 Zibll 原始的 `zib_shop_order_payment_success` 回调，替换为增强版 `xingxy_order_payment_success_guard`。

> ⚠️ 必须使用 `init` hook 而非 `after_setup_theme`，因为 Zibll 的 `pay.php` 在 `after_setup_theme` 之后才加载，过早执行 `remove_action` 会静默失败。

**三种分流逻辑**:
- **库存充足**（available ≥ count）→ 走原始 `zib_shop_auto_shipping`
- **部分有货**（0 < available < count）→ `xingxy_partial_shipping` 部分发货
- **完全无货**（available = 0）→ `xingxy_partial_shipping` 零库存处理

### 2. 部分发货 & 订单状态控制

> **关键决策**：部分发货和零库存均不调用 `zib_shop_virtual_shipping()`，因为该函数会直接触发确认收货导致订单跳到"交易完成"。

统一手动保存 `delivery_content` 到 `order_meta`，保持 `shipping_status = 0`（待发货）：
- 发出可用数量的卡密（部分发货）或仅保存等待通知（零库存）
- 注册到全局补发队列（`xingxy_pending_backlogs` option）
- 通知卖家补货（邮件 + 站内信 + 微信模板消息）

订单生命周期：**部分发货 → 待发货 → 卖家补货 → 系统自动补发 → 全部到齐 → 交易完成**

### 3. 自动补发

卖家导入新卡密时（`action-cardpass.php`）自动触发 `xingxy_auto_fulfill_backlogs`：
- 扫描补发队列，匹配相同 `card_pass_key` 的待补发订单
- 取出卡密追加到订单的 `delivery_content`
- 全部补完后替换"部分发货通知"为"全部发货完成"通知
- 调用 `zib_shop_order_receive_confirm` 完成交易
- 邮件 + ZibMsg 通知买家

### 4. 支付成功弹窗 AJAX 轮询

解决支付成功后弹窗与服务端发货之间的时序问题：
- 弹窗首次渲染时如果 `shipping_status=0` 且无 `delivery_content`，显示 loading 动画
- 每 2 秒通过 AJAX 轮询 `xingxy_ajax_check_shipping` 端点检查发货状态
- 发货完成后动态替换弹窗内容（无需刷新页面）
- 最长等待 15 秒后超时，引导用户前往订单中心

### 5. 一键复制按钮

- 弹窗中始终预渲染复制按钮（无内容时隐藏，AJAX 成功后显示）
- 订单详情弹窗中也有"复制全部内容"按钮
- 优先使用 `data-clipboard-text` 属性精准提取卡密，fallback 到 `innerText`

### 6. 待发货状态下查看已发内容

当 `shipping_status=0` 但 `delivery_content` 不为空时（部分发货场景）：
- 订单详情顶部显示"部分卡密已发出，等待商家补发"（而非"自动发货失败"）
- 发货信息入口可点击，用户可查看已发出的卡密
- 发货时间等详细信息正常展示

## 新增文件（子主题 panda/xingxy/）

### inc/shipping-guard.php

| 函数 | 说明 |
|------|------|
| `xingxy_order_payment_success_guard` | 增强版支付成功回调 |
| `xingxy_auto_shipping_guard` | 卡密发货拦截，库存校验+分流 |
| `xingxy_get_available_card_count` | 查询可用卡密数量 |
| `xingxy_partial_shipping` | 部分发货/零库存统一处理 |
| `xingxy_build_partial_notice` | 部分/等待发货通知 UI |
| `xingxy_build_completed_notice` | 全部发货完成通知 UI |
| `xingxy_build_fulfill_notice` | 补发记录流水 UI |
| `xingxy_register_pending_backlog` | 注册补发队列 |
| `xingxy_remove_pending_backlog` | 移除补发队列 |
| `xingxy_auto_fulfill_backlogs` | 自动补发核心逻辑 |
| `xingxy_notify_buyer_fulfilled` | 补发完成通知买家 |
| `xingxy_notify_seller_backlog` | 通知卖家补货 |
| `xingxy_ajax_check_shipping` | AJAX 端点：弹窗轮询发货状态 |

## 修改文件（父主题 zibll/）⚠️

### 1. inc/functions/shop/inc/pay.php

**修改内容**:
- `shipping_status == 0` 时显示 AJAX 轮询 loading（替代原先的"自动发货失败"误报）
- 有 `delivery_content` 时直接展示内容
- 始终预渲染复制按钮（默认隐藏，AJAX 成功后显示）
- 统一添加"前往订单中心查看"按钮

### 2. inc/functions/shop/action/action.php

**修改内容**:
- 发货内容 div 添加 `id="xingxy-delivery-content"`
- 内容区后追加"复制全部内容"按钮

### 3. inc/functions/shop/inc/user-center.php

**修改内容**:
- `zib_shop_user_order_details_consignee_box`：`shipping_status=0` 但有 `delivery_content` 时，显示"部分卡密已发出，等待商家补发"，并渲染可点击的发货信息链接
- `zib_shop_user_order_details_info`：同上条件下显示发货时间等详细信息

## 修改文件（子主题 panda/xingxy/）

### inc/action-cardpass.php

- `xingxy_ajax_import_cardpass` 函数末尾触发 `xingxy_auto_fulfill_backlogs`
- 导入成功消息包含补发结果

## 关键设计决策

### 部分发货不触发确认收货
`xingxy_partial_shipping` 中无论 `available_count` 是多少，都不调用 `zib_shop_virtual_shipping()`，统一手动保存 `delivery_content` 并保持 `shipping_status=0`。只有 `xingxy_auto_fulfill_backlogs` 在全部补发完毕后才调用 `zib_shop_order_receive_confirm`。

### 两种"待发货"的区分
| 条件 | 含义 | 用户看到 |
|------|------|---------|
| `shipping_status=0` 且 `delivery_content` 为空 | 真正的发货失败 | "自动发货失败，等待商家处理" |
| `shipping_status=0` 且 `delivery_content` 不为空 | 部分发货/零库存补发中 | "部分卡密已发出，等待商家补发"，可查看已发内容 |

### 通知卡片样式
所有通知 UI（partial/completed/fulfill）采用 Zibll 原生 class（`flex`、`muted-2-color`、`em09` 等），无额外背景框，完美适配明暗主题。

### 弹窗 Cookie 机制
Zibll 通过 `shop_pay_success_notice` Cookie 触发弹窗。"前往订单中心查看"按钮在跳转前清除此 Cookie，避免新页面再次弹窗。

## 恢复方法

主题更新后，需恢复三个父主题文件的修改：
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/zibll
git diff HEAD~1 -- inc/functions/shop/inc/pay.php
git diff HEAD~1 -- inc/functions/shop/action/action.php
git diff HEAD~1 -- inc/functions/shop/inc/user-center.php
```

子主题文件（`shipping-guard.php`、`action-cardpass.php`）不受主题更新影响。
