#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DB_PATH="$ROOT_DIR/data/schedule.db"
SCHEMA="$ROOT_DIR/schema/init.sql"
CONFIG_TARGET="$ROOT_DIR/config/app.php"

mkdir -p "$ROOT_DIR/data" "$ROOT_DIR/log" "$ROOT_DIR/snapshots"

sqlite3 "$DB_PATH" < "$SCHEMA"

echo "数据库结构及默认数据已同步：$DB_PATH" >&2

if [ ! -f "$CONFIG_TARGET" ]; then
  cp "$ROOT_DIR/config/app.example.php" "$CONFIG_TARGET"
  echo "已生成默认配置：$CONFIG_TARGET" >&2
fi

chmod 664 "$DB_PATH"

printf '初始化完成。\n'
