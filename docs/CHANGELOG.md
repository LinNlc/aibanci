# 更新日志

> 时区：Asia/Singapore (UTC+08:00)

## v0.1.0 - 2025-05-01
- 建立方案A：HTTP 写入 + SSE 推送的多人协作排班系统
  - `schedule_cells` / `schedule_ops` / `schedule_softlocks` / `schedule_snapshots` 表结构与 PRAGMA 初始化
  - `update_cell` 实现 CAS + 幂等写入，并记录审计日志与频率限制
  - `sync_ops` / `sse` 提供增量实时同步，`lock` 管理软锁，`snapshot` 支持快照与回滚
  - 提供 `bin/install.sh`、`bin/daily_snapshot.sh` 与 1Panel 部署指引
- 前端排班页改版，新增 SSE 订阅、软锁续约、冲突回滚与快照按钮
- 🛠️ 修复“单元格右侧下拉显示所有班次时页面会弹回顶部”问题：
  - 改用 `<button type="button">` 作为触发器，禁止默认锚点
  - 下拉面板使用 `position: fixed` + `getBoundingClientRect()` 计算位置，展开/关闭不影响 `scrollTop`
  - 支持键盘 Esc 关闭、焦点管理与 aria 属性，移动端/桌面均平滑
