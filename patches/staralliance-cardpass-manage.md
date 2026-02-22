# 星盟阶段四：前台卡密管理与 UI 优化

## 概述

在阶段三（卡密导入与发货设置）基础上，增加前台卡密的**列表查看**、**行内编辑**、**批量删除**功能，并对整个发货设置区块进行全面的 UI/UX 优化，提升操作体验和视觉品质。

**更新日期**: 2026-02-22

## 修改文件（子主题 panda/xingxy/）

### 1. inc/action-cardpass.php

**新增 AJAX Handler**:
- `xingxy_list_cardpass` — 获取卡密列表（按 `card_pass_key` 匹配，DESC 排序，最多 200 条）
- `xingxy_delete_cardpass` — 批量删除指定卡密（含权限校验、状态检查）
- `xingxy_edit_cardpass` — 行内编辑单条卡密（仅未使用状态可编辑，含所有权校验）

**优化**:
- 列表返回的 `create_time` 精确到分钟（`substr($item->create_time, 0, 16)`）

### 2. pages/newproduct.php

**布局优化**:
- 发货设置区块从 `flex:1 / flex:1` 平分改为 `flex:2 / flex:3`（左侧导入区 40%，右侧列表区 60%），解决左侧大面积空白
- 右侧区块增加左侧虚线分隔（`border-left: 1px dashed`），视觉分区更清晰
- 卡密输入框行数从 6 行增加到 12 行，完整展示超长组合示例

**库存展示增强**:
- 库存数字从 `font-size: 12px` 升级到 `22px + bold`
- 有库存时显示醒目红色（`#ff4d4f`）+ 弥散发光阴影
- 无库存时显示灰色

**按钮层级优化**:
- "确认导入"按钮：`jb-blue`（蓝色渐变）+ `padding-lg` + `fa-cloud-upload` 图标
- "刷新列表"按钮：`jb-cyan`（青色渐变）+ `white-space: nowrap`
- "批量删除"按钮：`hollow c-red`（红色镂空）+ `white-space: nowrap`
- 所有按钮均防折行，防止小屏时文字竖排

**按钮交互反馈**:
- 刷新列表：点击后图标原地旋转（`fa-refresh fa-spin`）+ 文字变为"加载中..."
- 批量删除：点击后垃圾桶图标旋转（`fa-trash fa-spin`）+ 文字变为"删除中..."
- 操作完成自动恢复原始状态
- 彻底移除了 Zibll 主题 `.loading` class（该 class 会产生异常的转圈伪元素）

**卡密列表功能**:
- 渲染表格含序号、卡号、密码、状态、时间、操作列
- 表尾统计行（总数 / 已用 / 未用）
- 全选未使用 + 选中计数器（动态显示"批量删除 (N)"）
- 列表刷新后自动重置删除按钮文字和勾选状态
- 未勾选时删除按钮呈禁用灰状态

**行内编辑**:
- 未使用的卡密显示 ✏️ 编辑图标
- 点击后卡号/密码列变为 input 输入框
- 操作列变为 ✓保存 / ✕取消
- 保存调用 `xingxy_edit_cardpass` AJAX，成功后刷新列表

**导入文案优化**:
- 提示语强调"自由拼接"能力：长串账号信息作卡号，兑换/登录说明作卡密
- 占位符包含超长组合实例，用户一看就懂
- 移除所有对 textarea 背景色的强覆盖，彻底复用表单全局类 `.form-control` 默认的暗色/透明机制，实现多主题无缝融合并附带主题色底部边框 Focus 动效。

**多端自适应终极防护**:
- 摒弃简陋的 `flex` 均分，重构了底层 `.xingxy-delivery-row` 网格，PC 端稳定实现 2:3 视野分割，移动端（<768px）平滑过渡到全屏上下双堆叠（解决 Flex 父级被涨破问题）。
- 在 AJAX JavaScript 回调生成 `table` 数据时，显式在外围构建了带有 `overflow-x: auto; max-width: 100%;` 的强力防爆套管 `.xingxy-card-table-wrapper`，彻底激活手机原生横向丝滑滑动特性。
- 引入了 JS 生命周期控制的“向左滑动查看更多”动态指引词。在 CSS 层级写入呼吸跳动动画（`xingxy-scroll-pulse`），仅在小屏状态并侦测到有可用卡密明细列表成功渲染后，方准时现身。

## 注意事项

- `action-cardpass.php` 中的三个新 handler 均通过 `wp_ajax_` 钩子注册,仅登录用户可调用
- 编辑/删除操作均校验商品所有权（`post_author` 与当前用户匹配或超级管理员）
- `ZibCardPass::update()` 在更新时会自动清除 `create_time` 并过滤空值
