#!/bin/bash
# 跨平台 Git 强制重置脚本
# 用于将本地仓库强制同步到远程 origin/main 分支的最新提交

set -e

echo "========================================"
echo "  Git 强制重置脚本"
echo "========================================"
echo ""

# 检查是否在 Git 仓库中
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "[错误] 当前目录不是 Git 仓库！"
    exit 1
fi

# 显示当前状态
echo "[信息] 当前分支: $(git branch --show-current 2>/dev/null || echo 'HEAD detached')"
echo "[信息] 当前提交: $(git log --oneline -1)"
echo ""

# 检查是否有未提交的更改
if ! git diff --quiet HEAD 2>/dev/null; then
    echo "[警告] 检测到未提交的本地更改，将被丢弃！"
    git status --short
    echo ""
fi

# 确认操作
echo "此操作将："
echo "  1. 从远程仓库拉取所有最新代码"
echo "  2. 强制将本地代码重置到 origin/main"
echo "  3. 丢弃所有本地未提交的修改"
echo ""
read -p "确认执行？[y/N]: " confirm

if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "[取消] 操作已取消"
    exit 0
fi

echo ""
echo "[执行] git fetch --all"
git fetch --all

echo ""
echo "[执行] git reset --hard origin/main"
git reset --hard origin/main

echo ""
echo "[完成] 重置成功！"
echo "[信息] 当前提交: $(git log --oneline -1)"