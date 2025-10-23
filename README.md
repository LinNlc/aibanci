# 排班助手实时协作改造说明

本次提交将排班助手升级为「单一数据源 + 多人实时协作」模式。后端以 `schedule_cells` 维护当前排班的唯一真相，所有前端编辑动作都会转换为增量操作（op）写回服务端，并通过 SSE 广播给其他在线用户。

## 新增数据结构

SQLite 启动时会自动创建以下三张表：

- `schedule_cells(team, day, emp, value, version, updated_at, updated_by)`：记录每个班次单元格的最新值及版本号。
- `schedule_ops(team, day, emp, ...)`：按时间顺序保存所有操作日志（包含客户端去重信息、冲突标记、操作者等）。
- `live_versions(team, version)`：每个团队的全局自增版本号，SSE 客户端据此进行补帧。

旧有的 `schedule_versions`、`team_states`、历史导入导出逻辑保持不变。

## 新接口

- `POST /api/ops`
  - 入参示例：
    ```json
    {
      "team": "版权组",
      "ops": [
        {"day":"2025-10-01","emp":"张三","to":"中1","baseCellVersion":12,"clientId":"web#u1","clientSeq":101}
      ]
    }
    ```
  - 返回每条 op 的服务端版本、最终单元格版本以及是否产生冲突。

- `GET /api/events?team=版权组&since=123`
  - `Content-Type: text/event-stream`，推送 `op`/`presence`/`snapshot` 事件。
  - 连接空闲超过 15 秒会发送 `:keepalive` 注释维持链路，服务器约 5 分钟自动断开，前端需自动重连并携带最新 `since`。

- `GET /api/schedule`
  - 当 `REALTIME_ENABLED=true` 时，响应体新增：
    - `realtime: true`
    - `liveVersion` 当前全局版本号
    - `cellVersions` / `cellMeta`（单元格版本与更新时间信息）
    - `serverTime` 服务端 UTC 时间戳
  - `data` 字段由 `schedule_cells` 聚合生成，确保刷新后与实时视图一致。

- `POST /api/schedule/save`
  - 在实时模式下不再依赖前端传入的整表 JSON，而是按请求的 `viewStart~viewEnd` 聚合 `schedule_cells` 生成快照存档，保持与历史版本、导出接口的兼容性。

## 前端行为

- 组件维护一个 `LiveChannel`（SSE）与服务器保持长链路，收到 `op` 事件后 1~2 秒内增量更新界面，同时对本地待确认的操作做对账。
- 所有对 `data` 的改动都会与上一份快照比对，转换为批量 `ops` 写入后端；冲突时自动回退并弹出提示。
- 依旧保留手动保存、历史版本、导出导入等入口，只是保存来源改为服务端聚合的数据。

## 部署与 Nginx 配置

SSE 要求关闭缓冲并保持长链接，可参考以下配置片段：

```nginx
location /api/events {
    proxy_pass http://php-backend/api/events;
    proxy_http_version 1.1;
    proxy_set_header Connection '';
    proxy_set_header Cache-Control 'no-cache';
    proxy_read_timeout 3600s;
    proxy_buffering off;
}
```

若前端部署在同域，确保反向代理不会合并多条响应（关闭 gzip/压缩缓存等）。

## 灰度与回滚

- 通过环境变量 `REALTIME_ENABLED=false` 可立即切回旧的“整页快照”模式；实时相关接口仍然保留但返回 `realtime:false`，前端自动降级。
- 回滚后原有的 `schedule_cells`、`schedule_ops` 数据会保留，方便后续再开启实时模式。

## 数据迁移建议

上线前可将最近一次排班快照回填到 `schedule_cells`：

```sql
INSERT INTO schedule_cells(team, day, emp, value, version, updated_at, updated_by)
SELECT
  v.team,
  json_extract(kv.key, '$') AS day,
  emp.name AS emp,
  kv.value AS value,
  1 AS version,
  datetime('now') AS updated_at,
  'bootstrap' AS updated_by
FROM schedule_versions v,
     json_each(v.data) AS kv,
     json_each(kv.value) AS emp;
```

请按需限制团队与日期范围，并在执行前做好数据库备份。

## 其他说明

- 新增的 `schedule_ops` 日志可用于审计或追溯问题，建议定期归档。
- SSE 长链路依赖 PHP-FPM `output_buffering=0` 与 `fastcgi_buffering off` 等设置，确保输出即时刷新。
- 前端通过 `clientId + clientSeq` 保证操作幂等，服务端也对相同标识做了去重。

如需进一步扩展（在线用户浮标、只读锁等），可在现有 SSE 渠道追加自定义事件。
