# 数据库结构说明

所有 PRAGMA 均在 `api/common.php` 初始化时自动设置，亦可通过 `schema/init.sql` 一次性导入。

```sql
PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA foreign_keys=ON;
PRAGMA busy_timeout=5000;
```

## schedule_cells
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `team` | TEXT | 团队名称，主键一部分 |
| `day` | TEXT | 日期（YYYY-MM-DD），主键一部分 |
| `emp` | TEXT | 员工姓名/编号，主键一部分 |
| `value` | TEXT | 班次值，受 CHECK 约束（默认：白/中1/中2/夜/休） |
| `version` | INTEGER | CAS 版本号，写入时必须匹配 `base_version` |
| `updated_at` | INTEGER | 最近更新时间（Unix 秒） |
| `updated_by` | TEXT | 最后修改人 ID |

- 主键：`(team, day, emp)`
- 约束：`CHECK (value IN ('白','中1','中2','夜','休'))`
- 初次写入时若记录不存在，会以版本 0 插入后再执行 CAS 更新。

## schedule_ops
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `op_id` | TEXT | 操作唯一 ID，主键；幂等控制凭证 |
| `team` / `day` / `emp` | TEXT | 操作作用域 |
| `base_version` | INTEGER | 操作提交前客户端持有的版本 |
| `new_value` | TEXT | 更新后的班次值（同样受 CHECK 限制） |
| `user_id` | TEXT | 发起者 ID |
| `ts` | INTEGER | 操作完成时间戳，用于 SSE & 补差排序 |

- 索引：`CREATE INDEX idx_schedule_ops_team_day_ts ON schedule_ops(team, day, ts);`
- 逻辑：成功写入后保留记录，CAS 失败会回滚并删除插入的日志。

## schedule_softlocks
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `team` / `day` / `emp` | TEXT | 被占用的单元格主键 |
| `locked_by` | TEXT | 当前持锁人 ID |
| `lock_until` | INTEGER | 锁到期时间（Unix 秒） |

- 主键：`(team, day, emp)`
- 行为：重复申请时若锁未过期且持有人不同，会更新为新持有人并返回 `risk=true` 警告。

## schedule_snapshots
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `snap_id` | TEXT | 快照 ID（随机生成），主键 |
| `team` | TEXT | 快照所属团队 |
| `day` | TEXT | 对应日期 |
| `created_at` | INTEGER | 快照生成时间 |
| `note` | TEXT | 备注（可为空） |
| `payload` | TEXT | JSON 字符串，包含该团队当日所有单元格的值/版本/更新人 |

- 索引：`CREATE INDEX idx_snapshots_team_day ON schedule_snapshots(team, day, created_at);`
- 恢复：读取 `payload` 后在事务内清空目标日期记录，并按照快照数据重建 `schedule_cells`，同时写入 `schedule_ops` 触发 SSE 通知。

## 事务与一致性
- 所有写接口均在显式事务中执行，失败将回滚。
- SQLite `busy_timeout=5000`，避免短暂锁冲突导致的异常返回。
- `schedule_ops` 的 `op_id` 结合 CAS 版本字段提供幂等保障，可配合客户端重试。
