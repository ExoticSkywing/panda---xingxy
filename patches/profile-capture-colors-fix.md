# 画像数据后台分维度彩色着色修复

## 问题描述
后台「用户画像数据中心」中，所有用户的问卷证据词汇均以灰色"历史记录"标签展示，无法区分不同维度（选项一/二/三）。期望效果为蓝/紫/橘三色分维度着色。

## 根因分析

### 1. 旧数据缺少 `raw_split` 字段
用户绑定邮箱时提交的画像数据存储结构中，早期版本仅有 `raw`（所有词汇用 ` | ` 拼接的字符串），分维度改造后新增了 `raw_split`（`{dim1: [...], dim2: [...], dim3: [...]}`）。

**但邮箱只能绑定一次**，已绑定的用户永远不会再触发 `zib_user_bind_email` hook，导致旧数据无法被更新。

### 2. 前端 `$.ajaxPrefilter` 类型匹配错误
Zibll 的 `zib_ajax` 函数通过 `$.ajax({data: jsObject})` 发送请求。在 `$.ajaxPrefilter` 执行阶段，`options.data` 可能仍为 Object（jQuery 在 prefilter 之后才做 `$.param()` 字符串化），原有检测条件 `typeof options.data === 'string'` 未能覆盖 Object 类型，导致匹配失败。

## 修复方案

### 后端渲染兼容（核心修复）
**文件**: `inc/admin-profile-dashboard.php`

对没有 `raw_split` 的旧数据，通过读取后台配置的问卷选项（`profile_dimension_1/2/3`），建立"选项名 → 维度"映射表，将 `raw` 中的每个词汇**反向匹配**到 dim1/dim2/dim3：

- **蓝色** = 维度一（偶像/人物）
- **紫色** = 维度二（娱乐/游戏）
- **橘色** = 维度三（消费偏好）
- **灰色** = 无法匹配的词汇

### 前端 ajaxPrefilter 加固
**文件**: `assets/js/profile-capture.js`

将 `$.ajaxPrefilter` 的匹配条件扩展为四种场景全覆盖：
1. `options.data` 为 Object 且 `data.action === 'user_bind_email'`（最常见路径）
2. `options.data` 为字符串（备用）
3. `options.data` 为 FormData（备用）
4. `action` 在 URL 上（备用）

## 涉及文件
| 文件 | 改动 |
|------|------|
| `inc/admin-profile-dashboard.php` | 旧数据兼容层：反向匹配配置选项自动着色 |
| `assets/js/profile-capture.js` | ajaxPrefilter 全类型覆盖 + 代码整理 |

## 验证
- 后台刷新「用户画像数据中心」，旧数据正确展示蓝/紫/橘三色标签 ✅
- 新用户提交的数据通过 `raw_split` 直接渲染，不受影响 ✅
