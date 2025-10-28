#!/usr/bin/env bash
set -euo pipefail

# 初始化数据库与默认配置（1Panel 环境可直接执行）

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DB_PATH="$ROOT_DIR/data/schedule.db"
SCHEMA="$ROOT_DIR/schema/init.sql"
CONFIG_SAMPLE="$ROOT_DIR/config/app.example.php"
CONFIG_FILE="$ROOT_DIR/config/app.php"
SNAPSHOT_DIR="$ROOT_DIR/snapshots"

mkdir -p "${ROOT_DIR}/data" "${ROOT_DIR}/log" "${ROOT_DIR}/runtime" "${SNAPSHOT_DIR}"

if [ ! -f "$CONFIG_FILE" ]; then
    cp "$CONFIG_SAMPLE" "$CONFIG_FILE"
    echo "已创建默认配置：$CONFIG_FILE"
fi

if [ ! -f "$DB_PATH" ]; then
    sqlite3 "$DB_PATH" < "$SCHEMA"
    echo "已初始化数据库：$DB_PATH"
else
    echo "数据库已存在，跳过初始化"
fi

chmod 664 "$DB_PATH"
chmod -R 775 "${ROOT_DIR}/runtime" "${ROOT_DIR}/log" "${SNAPSHOT_DIR}"

echo "安装完成"
