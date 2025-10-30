# API 说明

所有接口均通过 `https://{HOST}/api` 暴露，统一使用基于 Cookie 的会话。除登录与首次改密外，其余请求均需携带由后端设置的 `session_token` Cookie。返回格式默认为 JSON，若发生错误，`detail` 字段中会包含 `{"error": "xxx"}`。

## 通用错误码
| `error` | 说明 |
| ------- | ---- |
| `unauthenticated` | 未登录或会话失效 |
| `forbidden` | 权限不足（页面或团队权限） |
| `invalid_range` | 查询参数的日期范围非法 |
| `invalid_shift` | 指定的班次不存在或被禁用 |
| `duplicate_shift_code` | 班次代码重复 |
| `duplicate_person` | 人员名称重复 |
| `duplicate_username` | 账号用户名重复 |
| `invalid_page` | 设置了未知的页面权限标识 |
| `invalid_access_level` | 团队授权等级非法（非 `read`/`write`/`null`） |
| `team_not_found` | 指定团队不存在 |
| `not_found` | 资源不存在 |

## 认证与账号接口

### `POST /auth/login`
- 请求体：`{ "username": "admin", "password": "admin" }`
- 成功返回：
  ```json
  {
    "must_change_password": false,
    "user": {
      "id": 1,
      "username": "admin",
      "display_name": "超级管理员",
      "must_change_password": false,
      "pages": [{"page": "schedule", "can_view": true, "can_edit": true}, ...],
      "teams": [{"team_id": 1, "team_name": "运营一组", "access_level": "write"}, ...]
    }
  }
  ```
- 若返回 `{"must_change_password": true}`，前端需转入首次改密流程。

### `POST /auth/first-login`
- 请求体：`{ "username": "admin", "current_password": "admin", "new_password": "新密码" }`
- 功能：管理员首次登录必须调用本接口设置新密码。
- 返回体同 `login`，成功后会话自动建立。

### `POST /auth/logout`
- 清除 `session_token` Cookie，返回 `{ "success": true }`。

### `GET /auth/me`
- 返回当前用户信息，字段同 `login` 接口中的 `user`。

## 团队与排班接口

### `GET /teams`
- 返回当前账号有权限的团队列表，包含 `id`、`name`、`code`、`description` 与 `access_level`。

### `GET /schedule`
- 参数：`team_id`、`start`、`end`（均为 `YYYY-MM-DD`）。
- 权限：页面 `schedule` 可见 + 团队 `read`/`write`。
- 返回：
  ```json
  {
    "team": {"id": 1, "name": "运营一组", "code": "ops", "description": "...", "access_level": "write"},
    "start": "2024-06-01",
    "end": "2024-06-30",
    "read_only": false,
    "people": [{"id": 1, "name": "张三", "sort_index": 1, "active": true, "show_in_schedule": true}, ...],
    "shifts": [{"id": 1, "code": "DAY", "display_name": "白班", "bg_color": "#facc15", ...} ...],
    "days": [
      {
        "date": "2024-06-01",
        "weekday": "周六",
        "assignments": [
          {"person_id": 1, "shift_code": "DAY"},
          {"person_id": 2, "shift_code": "SWING"}
        ]
      }
    ]
  }
  ```

### `PUT /schedule/cell`
- 请求体：`{ "team_id": 1, "person_id": 1, "day": "2024-06-01", "shift_code": "DAY" }`
- 权限：页面 `schedule` 可编辑 + 团队 `write`。
- 返回：`{"person_id":1,"day":"2024-06-01","shift_code":"DAY","updated_at":"2024-06-01T12:00:00","updated_by":1}`。
- 若 `shift_code` 为空或 `null`，表示清空该单元格。

### `GET /schedule/export`
- 参数同 `GET /schedule`。
- 返回当前范围的 CSV 文件（`text/csv`）。

## 班次设置接口
所有接口均要求页面 `settings` 权限；写操作还需团队 `write`。

| 方法 | 路径 | 说明 |
| ---- | ---- | ---- |
| `GET` | `/teams/{team_id}/shifts` | 按排序返回团队班次列表（含启用状态） |
| `POST` | `/teams/{team_id}/shifts` | 新增班次，字段：`code`、`display_name`、`bg_color`、`text_color`、`sort_order`、`is_active` |
| `PUT` | `/teams/{team_id}/shifts/{shift_id}` | 更新班次单字段，允许部分字段提交 |
| `DELETE` | `/teams/{team_id}/shifts/{shift_id}` | 删除班次（若已在排班表中使用，需手动清理） |

## 人员管理接口
要求页面 `people` 权限；写操作需团队 `write`。

| 方法 | 路径 | 说明 |
| ---- | ---- | ---- |
| `GET` | `/teams/{team_id}/people` | 返回团队成员，按 `sort_index` 排序 |
| `POST` | `/teams/{team_id}/people` | 创建人员，字段：`name`、`active`、`show_in_schedule`、`sort_index` |
| `PUT` | `/teams/{team_id}/people/{person_id}` | 更新人员信息，可提交部分字段 |
| `DELETE` | `/teams/{team_id}/people/{person_id}` | 删除人员 |

## 权限矩阵接口
要求页面 `permissions` 权限，写操作需可编辑。

### `GET /permissions/overview`
返回权限矩阵：
```json
{
  "teams": [{"id":1,"name":"运营一组","code":"ops","description":"..."}],
  "users": [
    {
      "id":1,
      "username":"admin",
      "display_name":"超级管理员",
      "is_active":true,
      "pages":[{"page":"schedule","can_view":true,"can_edit":true},...],
      "teams":[{"team_id":1,"team_name":"运营一组","access_level":"write"}]
    }
  ]
}
```

### `POST /permissions/users`
- 创建新账号。
- 请求体：`{ "username": "new_user", "display_name": "新同事", "password": "Passw0rd", "must_change_password": true }`
- 返回：完整用户权限对象（默认无页面/团队授权）。

### `PUT /permissions/users/{user_id}`
- 用于修改账号显示名、重置密码与更新页面/团队权限。
- 请求体示例：
  ```json
  {
    "display_name": "排班观察员",
    "pages": [
      {"page": "schedule", "can_view": true, "can_edit": false},
      {"page": "people", "can_view": true, "can_edit": false}
    ],
    "teams": [
      {"team_id": 2, "access_level": "read"}
    ],
    "new_password": "viewer456"
  }
  ```
- 若 `access_level` 为 `null` 或不包含团队，将撤销对应团队授权；`can_edit=true` 时会强制 `can_view=true`。

## 其他
- `GET /api/health` 返回 `{ "status": "ok" }`，用于存活检测。
- 静态前端通过 `/public/*` 访问，根路径 `/` 会返回 `public/index.html`。
