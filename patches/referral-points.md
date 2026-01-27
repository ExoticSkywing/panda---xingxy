# 邀请注册送积分补丁

## 修改日期
2026-01-28

## 功能说明
当新用户通过推荐链接（`?ref=用户ID`）注册成功后，给推荐人奖励积分。

## 涉及文件

### xingxy 模块（Git 管理）
| 文件 | 操作 | 说明 |
|------|------|------|
| `xingxy/inc/referral.php` | 新增 | 核心逻辑，挂钩 `user_register` 事件 |
| `xingxy/inc/options.php` | 修改 | 添加"推广设置"配置区块 |
| `xingxy/init.php` | 修改 | 加载 referral.php 模块 |

### panda 子主题（非 Git）
| 文件 | 操作 | 说明 |
|------|------|------|
| `zibpay/functions/zibpay-points.php:339-346` | 修改 | 积分任务列表添加"邀请好友注册"项 |

## 后台配置
**路径**: WordPress 后台 → 星小雅高级定制 → 推广设置

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| 启用邀请送积分 | 功能开关 | 开启 |
| 奖励积分数量 | 每次邀请奖励 | 10 |

## 恢复方法

### xingxy 模块
```bash
cd /www/wwwroot/xingxy.manyuzo.com/wp-content/themes/panda/xingxy
git revert HEAD  # 回滚最近的提交
```

### zibpay-points.php
删除第 339-346 行（`// 邀请注册送积分` 代码块）。

## 技术细节

### 推荐人追踪流程
1. 用户访问 `?ref=123` 链接
2. `zibpay_save_referrer` 保存到 `$_SESSION['ZIBPAY_REFERRER_ID']`
3. 用户注册时 `zibpay_register_save_referrer` 保存到 `referrer_id` user meta
4. `xingxy_reward_referrer_on_registration` 给推荐人加积分

### 积分 API
```php
zibpay_update_user_points($referrer_id, array(
    'value' => $points,
    'type'  => '邀请奖励',
    'desc'  => '推荐用户 xxx 成功注册',
));
zibpay_add_user_free_points_date_detail($referrer_id, $points);
```
