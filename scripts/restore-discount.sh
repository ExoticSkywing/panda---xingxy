#!/bin/bash
# ========================================
# 星小雅高级定制 - 数量限制功能恢复脚本
# 
# 主题更新后运行此脚本恢复自定义修改
# ========================================

set -e

ZIBLL_PATH="/www/wwwroot/xingxy.manyuzo.com/wp-content/themes/zibll"
SHOP_PATH="$ZIBLL_PATH/inc/functions/shop"

echo "=========================================="
echo "  星小雅高级定制 - 功能恢复脚本"
echo "=========================================="
echo ""

# 检查 Zibll 主题目录是否存在
if [ ! -d "$ZIBLL_PATH" ]; then
    echo "❌ 错误：找不到 Zibll 主题目录"
    exit 1
fi

echo "📁 Zibll 目录: $ZIBLL_PATH"
echo ""
echo "⚠️  此脚本需要手动恢复以下文件的修改："
echo ""
echo "1. term-option.php - 后台添加\"数量限制\"输入框"
echo "   路径: $SHOP_PATH/admin/options/term-option.php"
echo ""
echo "2. discount.php - 添加数量判断函数 + count_limit 字段"
echo "   路径: $SHOP_PATH/inc/discount.php"
echo ""
echo "3. order.php - 添加数量限制判断调用"
echo "   路径: $SHOP_PATH/inc/order.php"
echo ""
echo "4. main.js + main.min.js - 前端数量限制判断"
echo "   路径: $SHOP_PATH/assets/js/"
echo ""
echo "5. dis.php - 添加\"满X件可用\"标签"
echo "   路径: $SHOP_PATH/page/dis.php"
echo ""
echo "=========================================="
echo "请参考 Git 历史或补丁文件进行恢复"
echo "=========================================="
