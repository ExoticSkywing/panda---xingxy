# 星盟阶段三：卡密与发货设置

## 概述

为前台商品发布系统增加发货设置功能，支持**固定内容**和**卡密**两种自动发货模式。合作商可在前台直接导入卡密数据，系统自动隔离到独立 type（`partner_custom`），后台通过专属 tab 管理。

**更新日期**: 2026-02-22

## 新增文件（子主题 panda/xingxy/）

### 1. inc/action-cardpass.php [NEW]
- 前台卡密导入 AJAX handler（`xingxy_import_cardpass`）
- 解析 `卡号 密码` 格式，写入 `wp_zibpay_card_password` 表
- type = `partner_custom`（与后台默认卡密隔离）
- `other`（备注）由合作商自定义，用于发货匹配
- `meta` 存储 `author_id` 和 `product_id`

## 修改文件（子主题 panda/xingxy/）

### 2. init.php
- 追加 `require_once XINGXY_PATH . 'inc/action-cardpass.php'`

### 3. pages/newproduct.php
**数据准备（PHP）**:
- 增加 `auto_type`、`fixed_content` 字段
- 编辑模式回填 `auto_delivery` 配置
- 计算卡密库存 `$card_stock`（`ZibCardPass::get_count`）

**UI 变更**:
- 发货设置区块：自动发货/手动发货 radio 切换
- 自动发货子类型：固定内容/卡密 切换
- 卡密备注输入框（用户自定义，必填）
- 库存实时显示
- 卡密导入区 + 输入引导提示条

**JS 变更**:
- 发货类型/子类型联动切换
- 卡密导入 AJAX（含备注必填校验）
- 导入成功后自动刷新库存、清空输入、移除提示
- 提交前检测未导入的卡密数据并提醒

### 4. inc/action-newproduct.php
- 接收 `auto_type`、`fixed_content` 字段
- `auto_delivery` 配置保存支持 `fixed` 和 `card_pass` 两种子类型

## 修改文件（子主题 panda/zibpay/）⚠️

### 5. zibpay/page/charge-card.php
> ⚠️ 此文件是子主题对父主题的覆盖文件

**修改内容**:
- 追加"合作商卡密"筛选 tab（紫色高亮）
- 列表中正确显示 `partner_custom` 类型（含导入者、关联商品链接）
- 默认"全部"列表不受影响（`type_args` 不含 `partner_custom`）

## 注意事项

- 父主题 `zibll/zibpay/page/charge-card.php` **不需要修改**（子主题已覆盖）
- 主题更新时注意检查子主题 `panda/zibpay/page/charge-card.php` 是否需要同步
