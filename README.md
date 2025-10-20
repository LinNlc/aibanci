# 排班助手（测试版）

一个包含完整前端排班工作台、可插拔自动化排班算法以及简易 API 示例的演示项目。所有资源可直接静态托管，同时配合 PHP 内置服务即可体验完整的持久化交互链路。

## 目录结构
```
.
├── index.html              # React + TailwindCSS 单页入口
├── static/
│   ├── auto-scheduler.js   # 自动排班算法与工具集合（挂载到 window.AutoScheduler）
│   └── ai-propose.js       # DeepSeek AI 调整助手前端脚本
├── api/
│   ├── index.php           # 核心 RESTful API（登录、配置、历史版本、导出等）
│   └── ai_propose.php      # 调用 DeepSeek 接口生成排班建议
└── storage/
    └── .gitignore          # 持久化状态文件（state.json）忽略配置
```

## 快速启动
1. **准备后端（可选但推荐）**
   ```bash
   php -S 0.0.0.0:8000 -t .
   ```
   启动后，`http://localhost:8000/index.html` 即可访问。`api/index.php` 会自动初始化 `storage/state.json` 持久化文件并管理登录会话。

2. **仅前端预览**
   若暂时没有 PHP 环境，也可直接双击 `index.html` 体验本地功能。此时部分依赖后端的功能（登录、历史版本、导出等）会提示请求失败，但界面不会崩溃。

## API 摘要
`api/index.php` 内置了最常用的接口，全部返回 JSON：
- `POST /api/login` / `POST /api/logout`：基于 session 的登录会话管理，默认账户为 `admin`（空密码）。
- `GET /api/org-config` / `POST /api/org-config`：读取或保存组织结构、账号和团队信息。
- `GET /api/schedule`：按团队读取最近保存的排班数据；若无历史记录则返回默认空模板。
- `POST /api/schedule/save`：保存最新排班并生成带版本号的历史记录，包含冲突检测（返回 409）。
- `GET /api/schedule/versions`、`GET /api/version`、`POST /api/schedule/version/delete`：历史版本的读取与删除（删除仅限 `super` 角色）。
- `GET /api/export/xlsx`：导出最新排班为 CSV（Excel 可直接打开）。
- `POST /api/ai_propose.php`：示例调用 DeepSeek API 的 AI 调整接口（需在环境变量中配置 `DEEPSEEK_API_KEY`）。

所有异常情况（参数缺失、权限不足、网络错误等）都会返回带 `message` 字段的 JSON，前端收到后会通过顶部错误栏提示，避免应用崩溃。

## 自动排班算法
`static/auto-scheduler.js` 暴露了一个包含数十个实用函数的 `window.AutoScheduler` 对象，涵盖：
- **日期与数据工具**：`fmt`、`enumerateDates`、`dateAdd`、`getVal`、`setVal` 等。
- **规则校验**：连续上班上限、夜班休息、中班连班冲突、休息偏好合法化等。
- **生成与调优**：`buildWhiteFiveTwo`、`applyAlternateByCycle`、`clampDailyByRange`、`clampPersonByRange`、`adjustWithHistory`、`autoAssignNightAndM2` 等。
- **统计辅助**：`countByEmpInRange`、`dailyMidCount`、`mixedCyclesCount`、`statsForEmployee` 等。

页面主脚本会在启动时自动校验 `window.AutoScheduler` 是否可用，并在 `console` 中运行一套自检用例，帮助定位潜在的规则冲突。

## AI 调整助手
`static/ai-propose.js` 为任何已有排班表注入一键 AI 调整能力：
- 默认直接访问 `window.ScheduleState`，也可自动解析 DOM 表格数据；
- 调用 `/api/ai_propose.php` 后会先做本地强校验（如夜班休 2 天、中 1→白 禁止等），再应用到页面；
- UI 通过 Corner Button 悬浮按钮呈现，支持预览与确认。

## 前端体验亮点
- TailwindCSS + 自定义动画，保证低占用的平滑过渡效果；
- 响应式侧边栏与浮动进度条，支持移动端；
- 全局错误捕获（`window.onerror`、`unhandledrejection`），确保任何异常都以提示方式呈现。

## 自定义与扩展
- **算法优化**：直接在 `static/auto-scheduler.js` 内编写新函数，或组合现有 API，React 组件无需改动。
- **后端扩展**：`api/index.php` 结构清晰，可继续添加导出、统计、审计等接口。写入操作统一通过 `saveState()` 带文件锁实现，避免并发覆盖。
- **部署**：静态资源可托管在任意 CDN/对象存储，API 可部署到任何支持 PHP 8+ 的环境（含 serverless 平台）。

欢迎在此基础上继续迭代排班算法或接入真实业务数据。
