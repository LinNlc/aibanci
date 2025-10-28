# 数据库结构（SQLite）

初始化脚本位于 `schema/init.sql`，默认启用 WAL。以下为关键表结构：

## PRAGMA 设置
- `journal_mode=WAL`：支持高并发读写
- `synchronous=NORMAL`：权衡性能与安全
- `foreign_keys=ON`：启用外键约束（当前表无外键，但保持一致性）
- `busy_timeout=5000`：SQLite 忙等待 5 秒，避免频繁 `database is locked`

## `schedule_cells`
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `team` | TEXT | 团队名称（主键组成部分） |
| `day` | TEXT | 日期 `YYYY-MM-DD`（主键组成部分） |
| `emp` | TEXT | 员工姓名（主键组成部分） |
| `value` | TEXT | 班次值，受 CHECK 约束 `{'白','中1','中2','夜','休'}` |
| `version` | INTEGER | 乐观锁版本号，自增（首次写入为 1） |
| `updated_at` | INTEGER | Unix 时间戳（秒） |
| `updated_by` | TEXT | 最近写入者 ID |

> 主键：(`team`,`day`,`emp`)；用于 `update_cell` 的 CAS 校验。

## `schedule_ops`
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `op_id` | TEXT | 操作唯一 ID，主键，提供幂等保障 |
| `team` | TEXT | 团队 |
| `day` | TEXT | 日期 |
| `emp` | TEXT | 员工 |
| `base_version` | INTEGER | 写入前客户端感知的版本号 |
| `new_value` | TEXT | 新班次值 |
| `user_id` | TEXT | 操作者 |
| `ts` | INTEGER | 操作产生时间戳（秒） |

索引：`idx_schedule_ops_team_day_ts(team, day, ts)`，供 SSE 轮询与 `sync_ops` 使用。

## `schedule_softlocks`
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `team` | TEXT | 团队（主键） |
| `day` | TEXT | 日期（主键） |
| `emp` | TEXT | 员工（主键） |
| `locked_by` | TEXT | 当前持有锁的用户 |
| `lock_until` | INTEGER | 锁到期时间戳 |

> 软锁不强制回滚，仅用于前端提示并发编辑风险。

## `schedule_snapshots`
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `snap_id` | TEXT | 快照 ID，主键（`md5(team)_day_timestamp`） |
| `team` | TEXT | 团队 |
| `day` | TEXT | 日期 |
| `created_at` | INTEGER | 快照生成时间 |
| `note` | TEXT | 备注（手动或自动） |
| `payload` | TEXT | JSON 字符串，包含该日所有 `emp/value/version/updated_*` |

> 回滚时会删除原表中同日数据，再批量写入快照内容，确保事务原子性。

## 扩展建议
- 若需扩展班次集合，可在 `config/app.php` 的 `allowed_values` 中增加选项，并同步更新前端 `shifts`
- 可新增 `teams` / `employees` 配置表供前端动态加载
