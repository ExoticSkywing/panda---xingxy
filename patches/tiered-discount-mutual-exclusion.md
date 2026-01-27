# 阶梯优惠互斥功能 - 补丁说明

## 概述
实现阶梯优惠互斥逻辑：当用户数量满足多个阶梯优惠条件时，只应用最高档位的优惠。

## 需求场景
- 3件起每单减1元
- 5件起每单减2元
- 10件起每单减3元

当购买10件时，只应用"减3元"优惠，而不是叠加所有优惠。

## 修改清单

### 1. main.js
**路径**: `/inc/functions/shop/assets/js/main.js`  
**位置**: `syncItemDiscountPrice` 函数  
**修改内容**:
- 在 `$.each(item_discount, ...)` 循环**前**添加预处理逻辑
- 按 `count_limit` 降序排序，找出命中的最高阶梯
- 在循环内跳过低于最高阶梯的优惠

### 2. main.min.js ⚠️ 重要
**路径**: `/inc/functions/shop/assets/js/main.min.js`  
**位置**: `syncItemDiscountPrice` 函数  
**修改内容**:
- 将 `d = a.discount` 重命名为 `item_discount`（避免与内部 `var d = Number(a.reduction_amount)` 冲突）
- 阶梯互斥预处理移到 `$.each` 循环**外部**
- 添加 `is_valid` 检查

### 3. order.php
**路径**: `/inc/functions/shop/inc/order.php`  
**位置**: `zib_shop_order_items_data` 函数（约 303-350 行）  
**修改内容**:
- 添加 `$hit_highest_count_limit` 预处理计算
- 在优惠循环中跳过低阶梯优惠

## 关键代码片段

### main.min.js 修复要点
```javascript
// 修复前（有 Bug）
var d = a.discount;
// ...循环内...
var d = Number(a.reduction_amount); // ❌ 变量覆盖！

// 修复后
var item_discount = a.discount;
// 预处理移到循环外
var hit_highest_count_limit = 0;
$.each(item_discount.slice().sort(...), function(idx, dd) {
    if (!dd.is_valid) return !0; // 检查有效性
    // ...
});
```

## 恢复方法
主题更新后，参考 Git 历史记录恢复：
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/zibll
git log --oneline -5
git diff <commit_hash>~1 <commit_hash> -- inc/functions/shop/
```

## 更新日期
2026-01-28
