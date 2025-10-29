# 1Panel 部署指南（基于 OpenResty + PHP-FPM）

以下步骤假设您已在服务器上安装并登录 1Panel。文中所有 `${...}` 占位符需根据实际环境替换，例如：`${DOMAIN}`→`schedule.example.com`，`${SITE_ROOT}`→`/opt/1panel/apps/openresty/openresty/www/sites/schedule`，`${PHP_UPSTREAM}`→`php-fpm:9000`。

## 1. DNS 与证书
1. 打开域名服务商管理平台，为 `${DOMAIN}` 与 `www.${DOMAIN}` 添加指向服务器公网 IP 的 A 记录。
2. 登录 1Panel → 左侧导航选择 **网站** → 点击顶部 **证书** → 右上角 **申请证书**。
3. 在弹窗中选择 **自动申请证书**，填写域名：`${DOMAIN}` 与 `www.${DOMAIN}`，确认勾选自动续签，点击 **确定**，等待申请完成。

## 2. 新建 HTTPS 站点
1. 1Panel 左侧导航选择 **网站** → 子菜单 **网站** → 右上角 **创建站点**。
2. 在“创建站点”弹窗中：
   - **站点域名**：填写 `${DOMAIN} www.${DOMAIN}`。
   - **站点目录**：填写 `${SITE_ROOT}`（例如 `/opt/1panel/apps/openresty/openresty/www/sites/${SITE_ROOT}`）。
   - **运行环境**：选择 `PHP 8.x`。
   - **HTTPS**：勾选“启用 HTTPS”，在证书下拉框选择刚申请的 `${DOMAIN}` 证书。
3. 点击 **确定**，等待站点创建完成。

## 3. 上传代码并设置权限
1. 1Panel → **文件** → 在左侧目录树找到 `${SITE_ROOT}`，点击工具栏 **上传**，选择本项目完整压缩包或逐文件上传。
2. 上传完成后，选中 `${SITE_ROOT}`，点击工具栏 **解压**（若上传为压缩包）。
3. 打开 1Panel → **终端**，进入站点运行主机或容器，执行：
   ```bash
   chown -R www:www ${SITE_ROOT}
   chmod -R 755 ${SITE_ROOT}
   ```
   确保 PHP-FPM 账户（默认为 `www`）拥有读写权限。

## 4. 初始化数据库
1. 1Panel → **终端** → 在弹出的 Shell 中执行：
   ```bash
   cd ${SITE_ROOT}
   ./bin/install.sh
   ```
   若提示 `sqlite3` 不存在，可安装 `sqlite3` 或手动执行：
   ```bash
   sqlite3 ${SITE_ROOT}/data/schedule.db < ${SITE_ROOT}/schema/init.sql
   cp ${SITE_ROOT}/config/app.example.php ${SITE_ROOT}/config/app.php
   ```
2. 确认生成的 `data/schedule.db`、`config/app.php` 权限为 `www:www`。

## 5. 配置应用
1. 1Panel → **文件** → 浏览至 `${SITE_ROOT}/config/app.php`，点击 **编辑**。
2. 根据实际环境调整：
   - `db_path`（建议保持默认 `${SITE_ROOT}/data/schedule.db`）。
   - `allowed_values`（如需新增班次，在此处追加）。
   - `snapshot_dir`、`log_dir`（若调整目录需同步创建并授权）。
   - `sse_heartbeat`、`lock_ttl`、`rate_limit`（可根据团队规模调优）。
3. 保存文件。

## 6. 配置 Nginx（SSE 反向代理）
1. 1Panel → **网站** → 选择刚创建的站点 → 右侧点击 **配置**。
2. 在“配置文件”页签中，找到 `server {}` 区块，将 `${SITE_ROOT}/config/nginx.sse.conf` 内容复制并粘贴到 `server {}` 内合适位置，例如其他 `location` 配置之后：
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
3. 点击右上角 **保存** → 出现弹窗后点击 **立即重载**，确认 Nginx 配置生效。

## 7. 配置计划任务（每日快照）
1. 1Panel 左侧导航 → **计划任务** → 右上角 **新增任务**。
2. 在弹窗中：
   - **任务类型**：选择 `Shell 脚本`。
   - **任务名称**：如 “每日排班快照”。
   - **执行周期**：选择 `每天`，时间设置为 `00:05`。
   - **脚本内容**：
     ```bash
     ${SITE_ROOT}/bin/daily_snapshot.sh 2>> ${SITE_ROOT}/log/snapshot.err
     ```
3. 点击 **确定** 保存任务。

## 8. 防火墙与安全组
1. 登录云服务器控制台，放行 80/443 端口到互联网。
2. 若服务器本地启用了防火墙（如 firewalld），执行：
   ```bash
   firewall-cmd --add-service=http --permanent
   firewall-cmd --add-service=https --permanent
   firewall-cmd --reload
   ```

## 9. 部署自检
1. 打开终端执行：
   ```bash
   curl -I https://${DOMAIN}/public/index.html
   curl -s https://${DOMAIN}/api/get_schedule.php?team=默认团队\&day=$(date +%F)
   ```
   返回 `200 OK` 且 JSON 合法表示部署成功。
2. 使用浏览器访问 `https://${DOMAIN}`，滚动至表格中部后点击任意班次右侧按钮，确认页面不会跳回顶部，并能实时接收其他浏览器窗口的更新。
