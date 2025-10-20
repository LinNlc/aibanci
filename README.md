# 排班助手代码概览

## 项目结构
- `index.html`：使用 React + TailwindCSS 构建的单页应用，内联脚本负责数据建模、界面交互、本地存储以及与后端 API 的通信。
- `auto-scheduler.js`：封装所有自动化排班算法与通用排班工具，并挂载在 `window.AutoScheduler` 上，供页面和外部脚本调用。
- `ai-propose.js`：为排班页面注入 AI 调度辅助按钮，可读取页面状态或解析排班表格并向 `/api/ai_propose.php` 请求建议方案。
- `ai_propose.php`：后端示例接口，转发 AI 调整排班的请求。

## 运行方式
1. 直接在浏览器中打开 `index.html` 即可体验前端功能。
2. 页面启动时会尝试访问 `/api` 下的接口；若本地无后端，可自行模拟或关闭相关入口。
3. 自动排班逻辑由 `auto-scheduler.js` 预先加载，并在主脚本运行前通过 `Babel` 转译。主脚本会在初始化时校验 `window.AutoScheduler` 是否存在。

## 自动排班算法
`auto-scheduler.js` 对外暴露的核心能力包括：
- 日期工具：`fmt`、`enumerateDates`、`dateAdd` 等。
- 基础数据操作：`getVal`、`setVal`、`countByEmpInRange` 等。
- 规则检查：连续上班限制、休息偏好解析、夜班/中班后的休息规则等。
- 排班生成与调整：
  - `buildWhiteFiveTwo`：根据休息偏好生成白班 5-2 基础排布。
  - `applyAlternateByCycle`、`clampDailyByRange`、`clampPersonByRange`：控制中班比例、个人/日均分布。
  - `adjustWithHistory`：结合历史工时和管理员配置对个人班次进行细调。
  - `autoAssignNightAndM2`：自动分配夜班与中2班并处理后续休息。

模块同时导出 `REST_PAIRS`、`normalizeRestPair` 等工具，主页面和统计视图直接复用，避免重复实现。

## 主要前端流程
1. `index.html` 首次加载时从 `localStorage` 读取组织配置与团队排班缓存；若不存在则创建默认结构。
2. 用户可在侧边栏切换视图（排班表、批量调度、员工管理、统计、历史、设置、角色）。
3. 自动排班入口会调用 `window.AutoScheduler` 提供的算法：生成基础排布、迭代调整、结合历史记录、自动排夜班等，并将结果写回页面状态。
4. 自检模块（`selfTests`）在控制台运行一组用例，确保常见规则仍被满足。

## 自定义/扩展建议
- 若需调整算法，可直接编辑 `auto-scheduler.js`，或在其他脚本中调用 `window.AutoScheduler` 暴露的函数进行组合。
- 新增前端功能时，优先通过 `AutoScheduler` 获取已有工具函数，避免在业务层重复实现日期/排班逻辑。
- 可根据需要扩展 `ai-propose.js` 与后端 API，实现 AI 回传的排班方案验证与回放。

