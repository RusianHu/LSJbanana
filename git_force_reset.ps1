# Git 强制重置脚本 (PowerShell)
# 用于将本地仓库强制同步到远程 origin/main 分支的最新提交

$ErrorActionPreference = "Stop"

Write-Host "========================================"
Write-Host "  Git 强制重置脚本"
Write-Host "========================================"
Write-Host ""

# 检查是否在 Git 仓库中
try {
    $null = git rev-parse --git-dir 2>$null
} catch {
    Write-Host "[错误] 当前目录不是 Git 仓库！" -ForegroundColor Red
    exit 1
}

if ($LASTEXITCODE -ne 0) {
    Write-Host "[错误] 当前目录不是 Git 仓库！" -ForegroundColor Red
    exit 1
}

# 显示当前状态
$currentBranch = git branch --show-current 2>$null
if (-not $currentBranch) { $currentBranch = "HEAD detached" }
$currentCommit = git log --oneline -1

Write-Host "[信息] 当前分支: $currentBranch"
Write-Host "[信息] 当前提交: $currentCommit"
Write-Host ""

# 检查是否有未提交的更改
$diffOutput = git diff --quiet HEAD 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "[警告] 检测到未提交的本地更改，将被丢弃！" -ForegroundColor Yellow
    git status --short
    Write-Host ""
}

# 确认操作
Write-Host "此操作将："
Write-Host "  1. 从远程仓库拉取所有最新代码"
Write-Host "  2. 强制将本地代码重置到 origin/main"
Write-Host "  3. 丢弃所有本地未提交的修改"
Write-Host ""
$confirm = Read-Host "确认执行？[y/N]"

if ($confirm -notmatch "^[Yy]$") {
    Write-Host "[取消] 操作已取消" -ForegroundColor Cyan
    exit 0
}

Write-Host ""
Write-Host "[执行] git fetch --all" -ForegroundColor Green
git fetch --all

if ($LASTEXITCODE -ne 0) {
    Write-Host "[错误] git fetch 失败！" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "[执行] git reset --hard origin/main" -ForegroundColor Green
git reset --hard origin/main

if ($LASTEXITCODE -ne 0) {
    Write-Host "[错误] git reset 失败！" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "[完成] 重置成功！" -ForegroundColor Green
$newCommit = git log --oneline -1
Write-Host "[信息] 当前提交: $newCommit"