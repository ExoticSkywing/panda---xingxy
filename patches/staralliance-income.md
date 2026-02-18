# 星盟阶段一：创作分成支持商城商品

## 项目代号

星盟（StarAlliance）

## 问题描述

用户中心创作分成面板的"我的商品" tab 不显示商城商品（`shop_product`），导致合作方无法查看自己的商城商品销售数据。

## 根因分析

`zibpay_get_user_income_posts_query()` 函数存在两个问题：

1. **`post_type` 数组不含 `shop_product`**
   - 原值：`['forum_post', 'plate', 'post', 'page']`
   - 缺少 `shop_product` 类型

2. **`meta_query` 条件不兼容商城商品**
   - 原条件要求 `zibpay_type >= 1`，但商城商品没有 `zibpay_type` meta
   - 商城商品只有 `zibpay_price` meta

## 修改文件

### panda/zibpay/functions/zibpay-income.php（主要）

**函数**: `zibpay_get_user_income_posts_query()`（第310-340行）

```diff
- 'post_type' => ['forum_post', 'plate', 'post', 'page'],
+ 'post_type' => ['forum_post', 'plate', 'post', 'page', 'shop_product'],
```

```diff
  'meta_query' => array(
-     array(
-         'key'     => 'zibpay_type',
-         'value'   => 1,
-         'compare' => '>=',
-     ),
      array(
          'key'     => 'zibpay_price',
          'value'   => 0,
          'compare' => '>',
      ),
  ),
```

### zibll/zibpay/functions/zibpay-income.php（同步修改）

保持与 panda 子主题一致的修改。

## 恢复方法

```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/zibll
git diff ea70820~1..ea70820 -- zibpay/functions/zibpay-income.php
```

## 注意事项

- 实际运行时加载的是 **panda 子主题** 的文件，不是 zibll 父主题的
- 移除 `zibpay_type` 条件对付费文章没有影响，因为所有付费文章都有 `zibpay_price > 0`
- `zibpay_type` 条件原本是冗余的——有 `zibpay_price > 0` 就足以筛选付费内容

**更新日期**: 2026-02-18
