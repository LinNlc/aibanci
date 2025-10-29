# 更新日志

## v0.1.0（Asia/Singapore）
- 首次落地方案 A：完成 `schedule_cells` / `schedule_ops` / `schedule_softlocks` / `schedule_snapshots` 数据结构与事务写入
- 新增 SSE 推送与断线补差，实现排班单元格的强实时协作
- 前端 React-free 版本：实现软锁、冲突提示、乐观更新与操作频率控制
- BUGFIX：修复班次下拉在滚动中会导致页面回到顶部的问题，采用固定定位面板 + `button` 触发并完善键盘可达性
- 提供 1Panel 详细部署指引、API 文档与每日快照脚本
