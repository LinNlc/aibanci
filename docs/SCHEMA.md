# 数据库结构说明

系统采用 SQLite，默认数据库路径为 `data/app.db`。可参考 `schema/init.sql` 创建表结构，核心实体如下：

## users
| 字段 | 类型 | 说明 |
| ---- | ---- | ---- |
| `id` | INTEGER | 主键 |
| `username` | TEXT | 登录用户名，唯一 |
| `display_name` | TEXT | 展示名称 |
| `password_hash` | TEXT | Bcrypt 哈希值 |
| `must_change_password` | INTEGER | 首次登录是否需改密（0/1） |
| `is_active` | INTEGER | 是否启用账号 |
| `token_version` | INTEGER | 会话版本号（改密后递增以失效旧 Cookie） |
| `created_at` / `updated_at` | TEXT | 时间戳（UTC） |

## teams
| 字段 | 类型 | 说明 |
| `id` | INTEGER | 主键 |
| `name` | TEXT | 团队名称，唯一 |
| `code` | TEXT | 团队代码，唯一 |
| `description` | TEXT | 描述 |

## user_page_permissions
| 字段 | 类型 | 说明 |
| `user_id` | INTEGER | 引用 `users.id` |
| `page` | TEXT | 页面标识（`schedule`/`settings`/`people`/`permissions`） |
| `can_view` | INTEGER | 是否可见 |
| `can_edit` | INTEGER | 是否可编辑（隐式要求 `can_view=1`） |

## user_team_permissions
| 字段 | 类型 | 说明 |
| `user_id` | INTEGER | 引用 `users.id` |
| `team_id` | INTEGER | 引用 `teams.id` |
| `access_level` | TEXT | 团队授权级别：`read` 或 `write` |

## shift_definitions
| 字段 | 类型 | 说明 |
| `team_id` | INTEGER | 所属团队 |
| `code` | TEXT | 班次代码，团队内唯一 |
| `display_name` | TEXT | 显示名 |
| `bg_color` / `text_color` | TEXT | 颜色配置（十六进制） |
| `sort_order` | INTEGER | 排序权重，越小越靠前 |
| `is_active` | INTEGER | 是否启用 |
| `created_at` / `updated_at` | TEXT | 创建/更新时间 |

## people
| 字段 | 类型 | 说明 |
| `team_id` | INTEGER | 所属团队 |
| `name` | TEXT | 姓名，团队内唯一 |
| `active` | INTEGER | 是否启用 |
| `show_in_schedule` | INTEGER | 排班表是否展示 |
| `sort_index` | INTEGER | 排班表列排序值 |
| `created_at` / `updated_at` | TEXT | 时间戳 |

## schedule_entries
| 字段 | 类型 | 说明 |
| `team_id` | INTEGER | 所属团队 |
| `person_id` | INTEGER | 人员 ID |
| `day` | TEXT | 日期（`YYYY-MM-DD`） |
| `shift_code` | TEXT | 班次代码，可为空表示清空 |
| `updated_at` | TEXT | 最近更新时间 |
| `updated_by` | INTEGER | 最后操作人 ID |

### 约束与索引
- `user_page_permissions`、`user_team_permissions` 分别对 `(user_id, page)`、`(user_id, team_id)` 建唯一约束。
- `shift_definitions` 在 `(team_id, code)` 上唯一；`schedule_entries` 在 `(team_id, person_id, day)` 上唯一。
- 辅助索引：`people(team_id, sort_index)`、`shift_definitions(team_id, sort_order)`、`schedule_entries(team_id, day)`。
- 所有外键均开启 `ON DELETE CASCADE`，删除团队/用户时相关记录会自动清理。
