#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_DIR="${LSJBANANA_PROJECT_DIR:-/www/wwwroot/yanshanlaosiji.top/LSJbanana}"
REMOTE_NAME="${LSJBANANA_GIT_REMOTE:-origin}"
BRANCH_NAME="${LSJBANANA_GIT_BRANCH:-main}"
SITE_URL="${LSJBANANA_SITE_URL:-https://yanshanlaosiji.top/LSJbanana/}"
BACKUP_ROOT="${LSJBANANA_BACKUP_ROOT:-/www/backup/LSJbanana}"
STASH_CHANGES=1
RUN_CHECKS=1

usage() {
    cat <<'EOF'
用法：./git-sync.sh [--no-stash] [--skip-checks]

环境变量：
  LSJBANANA_PROJECT_DIR  项目目录
  LSJBANANA_GIT_REMOTE   Git 远程名，默认 origin
  LSJBANANA_GIT_BRANCH   部署分支，默认 main
  LSJBANANA_SITE_URL     部署后检查地址
  LSJBANANA_BACKUP_ROOT  配置备份根目录

脚本会备份 config.php，把未提交改动保存到 Git stash，并仅进行快进更新。
不会使用 reset --hard，也不会自动删除 stash。
EOF
}

while (($# > 0)); do
    case "$1" in
        --no-stash)
            STASH_CHANGES=0
            ;;
        --skip-checks)
            RUN_CHECKS=0
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "[错误] 未知参数：$1" >&2
            usage >&2
            exit 2
            ;;
    esac
    shift
done

fail() {
    echo "[错误] $*" >&2
    exit 1
}

command -v git >/dev/null 2>&1 || fail "未找到 git"
[ -d "$PROJECT_DIR" ] || fail "项目目录不存在：$PROJECT_DIR"

cd "$PROJECT_DIR"

if ! git config --global --get-all safe.directory 2>/dev/null | grep -Fqx "$PROJECT_DIR"; then
    git config --global --add safe.directory "$PROJECT_DIR"
fi

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "目标目录不是 Git 仓库"
git remote get-url "$REMOTE_NAME" >/dev/null 2>&1 || fail "Git 远程不存在：$REMOTE_NAME"

current_branch="$(git branch --show-current)"
[ "$current_branch" = "$BRANCH_NAME" ] || fail "当前分支为 $current_branch，预期为 $BRANCH_NAME"

timestamp="$(date '+%Y%m%d-%H%M%S')"
backup_dir="$BACKUP_ROOT/git-sync-$timestamp"
config_file="$PROJECT_DIR/config.php"
config_backup=""
config_hash_before=""

hash_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

if [ -f "$config_file" ]; then
    mkdir -p "$backup_dir"
    config_backup="$backup_dir/config.php"
    cp -p "$config_file" "$config_backup"
    chmod 600 "$config_backup"
    config_hash_before="$(hash_file "$config_file")"
    echo "[备份] 生产配置已保存到：$config_backup"
else
    echo "[警告] 未找到 config.php，部署后站点可能无法启动。" >&2
fi

echo "[1/4] 获取 $REMOTE_NAME 最新元数据..."
git fetch "$REMOTE_NAME" --prune
target_ref="$REMOTE_NAME/$BRANCH_NAME"
git show-ref --verify --quiet "refs/remotes/$target_ref" || fail "远程分支不存在：$target_ref"

stash_ref=""
if [ -n "$(git status --porcelain --untracked-files=all)" ]; then
    if [ "$STASH_CHANGES" -ne 1 ]; then
        fail "工作区存在未提交改动；移除 --no-stash 或先人工处理"
    fi
    stash_message="lsjbanana git-sync $timestamp before $target_ref"
    git stash push --include-untracked -m "$stash_message" >/dev/null
    stash_ref="$(git stash list --format='%gd %s' | awk -v message="$stash_message" 'index($0, message) {print $1; exit}')"
    echo "[保护] 未提交改动已保存到：${stash_ref:-Git stash 顶部}"
fi

if ! git merge-base --is-ancestor HEAD "$target_ref"; then
    fail "本地提交与 $target_ref 不满足快进关系；已保留配置备份和 stash，请人工检查"
fi

echo "[2/4] 快进更新到 $target_ref..."
git merge --ff-only "$target_ref"

if [ -n "$config_hash_before" ]; then
    if [ ! -f "$config_file" ] || [ "$(hash_file "$config_file")" != "$config_hash_before" ]; then
        cp -p "$config_backup" "$config_file"
        echo "[保护] 检测到 config.php 变化，已从备份恢复。"
    fi
fi

echo "[3/4] 运行部署检查..."
if [ "$RUN_CHECKS" -eq 1 ]; then
    if command -v php >/dev/null 2>&1; then
        for php_file in index.php api.php db.php openai_images_adapter.php debug_diagnostic.php; do
            [ ! -f "$php_file" ] || php -l "$php_file" >/dev/null
        done
    else
        echo "[警告] 未找到 php，跳过 PHP 语法检查。" >&2
    fi

    if command -v curl >/dev/null 2>&1; then
        curl --fail --silent --show-error --location --max-time 30 --output /dev/null "$SITE_URL"
    else
        echo "[警告] 未找到 curl，跳过站点 HTTP 检查。" >&2
    fi
else
    echo "[提示] 已按参数跳过部署检查。"
fi

if id www >/dev/null 2>&1; then
    if [ -f "$config_file" ]; then
        chown root:www "$config_file"
        chmod 640 "$config_file"
    fi
fi

echo "[4/4] 同步完成：$(git log -1 --format='%h %s')"
if [ -n "$stash_ref" ]; then
    echo "[提示] 同步前改动仍保留在 $stash_ref；确认无需恢复后可人工删除。"
fi
