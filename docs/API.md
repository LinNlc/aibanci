# API 说明

所有接口默认响应 `application/json`，除 `sse.php`（SSE 流）。未特别说明时均需在 Header 中携带安全 Cookie/Token（此处演示使用 `X-USER-ID` 头）。

## 通用错误码
| 字段 | 说明 |
| ---- | ---- |
| `error` | 错误类型，例如 `invalid_params` / `invalid_date` / `rate_limited` / `server_error` |
| `message` | 友好提示 |

`update_cell` 冲突时返回 `{"applied":false,"reason":"conflict"}`，HTTP 状态 200。

---

## `POST /api/update_cell.php`
- **功能**：写入单元格班次（CAS + 幂等）
- **参数**：`team` `day` `emp` `new_value` `base_version` `op_id`
- **示例**：
```bash
curl -X POST https://${DOMAIN}/api/update_cell.php \
  -H 'X-USER-ID: user_123' \
  -F team='版权组' \
  -F day='2025-05-01' \
  -F emp='张三' \
  -F new_value='白' \
  -F base_version=1 \
  -F op_id='op-20250501-0001'
```
- **成功响应**：
```json
{
  "applied": true,
  "value": "白",
  "version": 2,
  "updated_at": 1730457600,
  "updated_by": "user_123",
  "op_id": "op-20250501-0001",
  "ts": 1730457600
}
```
- **冲突示例**：`{"applied":false,"reason":"conflict","current_version":3}`

## `GET /api/get_cell.php`
- **功能**：查询单元格当前值与版本
- **参数**：`team` `day` `emp`
- **示例**：
```bash
curl 'https://${DOMAIN}/api/get_cell.php?team=版权组&day=2025-05-01&emp=张三'
```
- **响应**：`{"value":"白","version":2,"updated_at":1730457600,"updated_by":"user_123"}`；若尚未写入则版本为 0。

## `GET /api/sync_ops.php`
- **功能**：按时间戳增量同步操作日志，用于 SSE 断线补差
- **参数**：`team` `day` `since_ts`（默认 0）`limit`（默认 200）
- **示例**：
```bash
curl 'https://${DOMAIN}/api/sync_ops.php?team=版权组&day=2025-05-01&since_ts=1730457600'
```
- **响应**：
```json
{
  "ops": [
    {
      "op_id": "op-20250501-0001",
      "team": "版权组",
      "day": "2025-05-01",
      "emp": "张三",
      "base_version": 1,
      "new_value": "白",
      "user_id": "user_123",
      "ts": 1730457600
    }
  ],
  "next_since_ts": 1730457600
}
```

## `POST /api/lock.php`
- **功能**：软锁单元格，提示并发编辑
- **参数**：`team` `day` `emp` `action`（`acquire` / `renew` / `release`）
- **示例**：
```bash
curl -X POST https://${DOMAIN}/api/lock.php \
  -H 'X-USER-ID: user_123' \
  -F team='版权组' \
  -F day='2025-05-01' \
  -F emp='张三' \
  -F action='acquire'
```
- **响应**：`{"locked":true,"lock_until":1730457630,"risk":false}`；若他人持锁则 `risk=true`。

## `GET /api/sse.php`
- **功能**：建立 SSE 通道，接收实时增量
- **参数**：`team` `day` `since_ts`（可选，断线重连使用）
- **示例**：
```bash
curl -N 'https://${DOMAIN}/api/sse.php?team=版权组&day=2025-05-01&since_ts=1730457600'
```
- **推送样例**：
```
event: message
data: {"type":"cell.update","team":"版权组","day":"2025-05-01","emp":"张三","value":"白","by":"user_123","ts":1730457600,"op_id":"op-20250501-0001","base_version":1}
:ping
```

## `POST /api/snapshot.php?action=create`
- **功能**：生成快照
- **参数**：`team` `day` `note`
- **示例**：
```bash
curl -X POST 'https://${DOMAIN}/api/snapshot.php?action=create&team=版权组&day=2025-05-01&note=手动快照' \
  -H 'X-USER-ID: user_123'
```
- **响应**：`{"snap_id":"d41d8cd98f00b204e9800998ecf8427e_2025-05-01_1730457600","created_at":1730457600}`

## `POST /api/snapshot.php?action=restore`
- **功能**：按快照回滚
- **参数**：`snap_id`
- **响应**：`{"restored":true,"team":"版权组","day":"2025-05-01"}`

## `GET /api/snapshot.php?action=list`
- **功能**：列出某日快照
- **参数**：`team` `day`
- **响应**：`{"snapshots":[{"snap_id":"...","created_at":1730457600,"note":"自动快照"}]}`
