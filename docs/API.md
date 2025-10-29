# API 说明

所有接口默认前缀为 `https://${DOMAIN}/api/`，除 GET 请求外均要求通过 Cookie / Header 完成鉴权（本仓库提供 `is_authenticated()` 桩函数，可按需接入 SSO）。返回值统一为 JSON，时间戳为秒级 Unix 时间。

## 公共错误码
| 字段 | 说明 |
| ---- | ---- |
| `missing_parameter` | 缺少必填字段 |
| `invalid_parameter` | 参数校验失败（非法日期、空值等） |
| `invalid_value` | 班次不在白名单内 |
| `unauthenticated` | 未登录或权限不足 |
| `rate_limited` | 写入频率超过配置上限 |
| `conflict` | CAS 校验失败，需刷新后重试 |
| `not_found` | 指定资源不存在 |
| `internal_error` | 服务内部异常 |

## 1. `update_cell.php`
- **方法**：POST
- **入参（JSON）**：
  - `team` (string)
  - `day` (YYYY-MM-DD)
  - `emp` (string)
  - `new_value` (string，需在白名单内)
  - `base_version` (int，当前单元格版本)
  - `op_id` (string，客户端生成的全局唯一 ID)
- **成功返回**：
  ```json
  {
    "applied": true,
    "value": "白",
    "version": 8,
    "updated_at": 1730000000,
    "updated_by": "user_123"
  }
  ```
- **冲突示例**：
  ```json
  {
    "applied": false,
    "reason": "conflict"
  }
  ```
  收到 conflict 时应立即调用 `get_cell.php` 获取最新版本。
- **示例命令**：
  ```bash
  curl -X POST "https://${DOMAIN}/api/update_cell.php" \
       -H 'Content-Type: application/json' \
       -d '{"team":"版权组","day":"2025-10-28","emp":"张三","new_value":"中1","base_version":7,"op_id":"client-uuid"}'
  ```

## 2. `get_cell.php`
- **方法**：GET
- **参数**：`team`、`day`、`emp`
- **返回**：单元格当前值、版本与最新更新时间

## 3. `get_schedule.php`
- **方法**：GET
- **参数**：`team`、`day`
- **返回**：
  ```json
  {
    "team": "版权组",
    "day": "2025-10-28",
    "cells": [
      {"emp":"张三","value":"白","version":3,"updated_at":1730000000,"updated_by":"user_123"},
      {"emp":"李四","value":"夜","version":6,"updated_at":1730000100,"updated_by":"user_789"}
    ]
  }
  ```
  若某成员尚未排班，可在前端补全默认行。

## 4. `sync_ops.php`
- **方法**：GET
- **参数**：`team`、`day`、`since_ts`（int，默认为 0）
- **返回**：`ts > since_ts` 的增量操作按时间升序排列
  ```json
  {
    "ops": [
      {"op_id":"uuid","team":"版权组","day":"2025-10-28","emp":"张三","base_version":7,"new_value":"中1","user_id":"user_123","ts":1730000000}
    ],
    "since": 0
  }
  ```
  前端应在 SSE 断线后调用一次以补齐遗漏事件。

## 5. `lock.php`
- **方法**：POST
- **入参（JSON）**：`action`（`acquire` / `renew` / `release`）、`team`、`day`、`emp`
- **返回示例**：
  ```json
  {
    "locked": true,
    "lock_until": 1730000030,
    "locked_by": "user_123",
    "risk": false
  }
  ```
  当 `risk=true` 时表示可能存在他人占用，前端需提示用户谨慎操作。

## 6. `sse.php`
- **方法**：GET（长连接）
- **参数**：`team`、`day`、`since_ts`
- **返回**：SSE 流，`event: message` 携带 `cell.update` 数据
  ```text
  event: message
  data: {"type":"cell.update","team":"版权组","day":"2025-10-28","emp":"张三","value":"中1","base_version":7,"by":"user_123","ts":1730000000}
  ```
  每隔 15 秒发送 `:ping` 保活。客户端需监听 `error` 事件并在重连前调用 `sync_ops.php` 补差。

## 7. `snapshot.php`
- **模式 A**：创建快照
  - **请求**：`POST /snapshot.php?team=...&day=...&note=...`
  - **返回**：`{ "snap_id": "snap_xxx", "created_at": 1730000000 }`
- **模式 B**：恢复快照
  - **请求**：`POST /snapshot.php?mode=restore&snap_id=...`
  - **返回**：`{ "restored": true, "team": "版权组", "day": "2025-10-28" }`
- **模式 C**：查询快照
  - **请求**：`GET /snapshot.php?team=...&day=...`
  - **返回**：快照列表（按创建时间倒序）

## 8. 其他脚本
- `bin/install.sh`：初始化数据库与配置文件
- `bin/daily_snapshot.sh`：按日期批量生成快照，可在 1Panel 计划任务中调用
