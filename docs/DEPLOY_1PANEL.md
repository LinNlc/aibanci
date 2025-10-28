# 1Panel 部署手册（HTTPS + OpenResty + PHP-FPM）

> 部署前请准备好 `${DOMAIN}` 的 DNS 管理权，以及目标服务器已安装 1Panel、OpenResty、PHP 8.x。

## 1. DNS 与证书
1. 登录域名服务商，新增两条 **A 记录**：
   - 主机记录 `@` → 服务器公网 IP
   - 主机记录 `www` → 服务器公网 IP
2. 进入 1Panel 后台 → **网站** → **证书** → 点击右上角 **申请证书**
3. 在弹窗中勾选 `单域名`，输入 `${DOMAIN}` 与 `www.${DOMAIN}`，保持自动续签
4. 点击 **确定**，等待证书状态变为“已签发”

## 2. 新建站点（HTTPS）
1. 1Panel → **网站** → **网站** → 点击右上角 **创建站点**
2. 在弹窗中填写：
   - **站点域名**：`${DOMAIN} www.${DOMAIN}`（空格分隔）
   - **站点目录**：`${SITE_ROOT}`（如 `/opt/1panel/apps/openresty/openresty/www/sites/${SITE_ROOT}`）
   - **运行环境**：选择 `PHP 8.x`
   - **启用 HTTPS**：勾选并在证书下拉中选择刚才签发的证书
3. 点击 **确定** 完成站点创建

## 3. 上传代码与权限
1. 1Panel → **文件** → 进入 `${SITE_ROOT}` 目录（可通过上传压缩包并解压，或使用 SFTP）
2. 保持仓库目录结构：`api/`、`config/`、`public/`、`bin/`、`schema/`、`docs/`
3. 1Panel → **终端** → 进入容器或主机 Shell，执行：
   ```bash
   chown -R www:www ${SITE_ROOT}
   chmod -R 755 ${SITE_ROOT}
   ```

## 4. 初始化数据库
1. 1Panel → **终端** → 进入 OpenResty/PHP 容器（或主机）
2. 执行：
   ```bash
   cd ${SITE_ROOT}
   bash bin/install.sh
   ```
   - 若命令不存在，可手动执行：`sqlite3 ${SITE_ROOT}/data/schedule.db < ${SITE_ROOT}/schema/init.sql`

## 5. 配置应用
1. `bin/install.sh` 会自动生成 `/config/app.php`；如需自定义请编辑：
   - `db_path`：SQLite 路径
  - `allowed_values`：班次集合
  - `sse_ping_interval`：SSE 心跳秒数
  - `lock_duration`：软锁时长
  - `snapshot_dir`：快照目录
2. 配置完成后保存，确保文件权限为 `664`

## 6. 配置 Nginx（SSE 反代）
1. 1Panel → **网站** → **网站** → 选择 `${DOMAIN}` 对应站点 → 点击 **配置**
2. 在“配置文件”页签中找到 `server { ... }`
3. 将 `${SITE_ROOT}/config/nginx.sse.conf` 内容复制到 `server {}` 内部，例如：
   ```nginx
   # SSE 反代（HTTPS）
   location /api/sse {
       proxy_pass http://${PHP_UPSTREAM};
       proxy_http_version 1.1;
       proxy_set_header Connection "";
       proxy_buffering off;
       proxy_cache off;
       gzip off;
       add_header X-Accel-Buffering no;
       proxy_read_timeout 3600s;
   }
   ```
4. 点击右上角 **保存** → 再点击 **重载 Nginx**

## 7. 定时任务（每日快照）
1. 1Panel → **计划任务** → 点击 **新增任务**
2. 设置：
   - **任务名称**：`自动快照`
   - **任务类型**：`Shell 脚本`
   - **执行周期**：`每天` → 时间 `00:05`
   - **脚本内容**：`${SITE_ROOT}/bin/daily_snapshot.sh 2>> ${SITE_ROOT}/log/snapshot.err`
3. 点击 **保存**

## 8. 开放防火墙
- 在云主机安全组与本地防火墙放行 80/443 端口

## 9. 自检（curl）
在 1Panel 终端或本地运行：
```bash
curl -I https://${DOMAIN}/api/get_cell.php?team=版权组&day=$(date +%F)&emp=张三
```
若返回 `200 OK` 且头部包含 `content-type: application/json`，说明接口可达。

> 至此部署完成，可在浏览器访问 `https://${DOMAIN}` 验证实时协作、快照、SSE 功能。
