# 变更记录

## 1.1.0 (2026-09-18)
- **管理页面全面重构**：按用户 / 按节点双视角 + 顶部统计条 + 右侧抽屉批量授权/撤销 + 分页与关键字搜索，支持上百定制客户的规模化管理
- **安全修复**：管理页面不再把 `secure_path` 渲染进公开 HTML（此前任何未登录访客查看页面源码即可获得后台 API 保密前缀）；改为管理员首次使用时手动填写一次（存浏览器 Local Storage）
- 批量授权 API：`POST /grant` 支持 `user_ids[]` / `server_ids[]` 数组，一次授权多用户/多节点
- 新增管理端点：`/by-user`（按用户聚合分页）、`/by-server`（按节点聚合）、`/users`（用户远程搜索，替代旧版一次只取最新 50 人的下拉）、`/servers`（节点池含授权人数）、`/stats`（统计）
- 修复并发重复授权触发唯一键冲突导致 500 的问题（现返回友好提示）
- 修复定时兜底同步频率不可调的问题：新增配置项 `resync_interval_minutes`（1/2/5/10 分钟，默认 1 分钟保持与 1.0.7 一致）
- 新 UI：遵循 `UI标准/` 设计规范（Acme 风格指南：#2563eb 主色、Inter 字体、12px 卡片圆角、毛玻璃顶栏、stroke 1.5 线性图标），全量输出转义防 XSS，按钮防重复点击
- 旧 API（`/grants` 全量数组、`/options`）保持兼容；artisan 命令不变

## 1.0.7 (2026-08-07)
- **新增定时兜底同步**：每分钟自动对所有授权节点强制触发 `NodeSyncService::notifyFullSync`，修复节点端用户表偶尔和面板不同步导致的“订阅看得到节点但连不上”（Xboard/插件重装、节点 WS 抖动后尤其明显），免去手动“删除授权再重新添加”
- 新增 artisan 命令 `extra-node:resync`（可手动触发一次全量重同步，离线节点自动跳过）
- 新增配置项 `auto_resync`（默认开启，可在后台插件配置关闭）

## 1.0.6 (2026-07-11)
- 诊断 API (`diagnose`) 包 try-catch，异常时返回真实错误信息（文件+行号），不再被 Xboard Handler 吞成通用 500
- 作者改为 PiPi-happy（统一使用 GitHub 账号署名）

## 1.0.5 (2026-07-11)
- 新增「同步诊断」功能：网页输入用户ID+节点ID，一键诊断节点为何收不到用户
  - 检查节点 group_ids 是否空、节点 WS 是否在线、用户是否在 getAvailableUsers 列表、用户封禁/过期/流量状态
- 新增 `GET /extra-node/diagnose` API

## 1.0.4 (2026-07-11)
- **修复"授权后连不上"**：grant/revoke 后主动调 `NodeSyncService::notifyFullSync($serverId)` 触发节点全量用户同步
- grant 时检测节点 `group_ids` 为空，返回明确警告（提示给节点设权限组）

## 1.0.3 (2026-07-11)
- **修复网页 `saveToken is not defined`**：Blade `{{ json_encode($securePath) }}` 的 `{{ }}` 把 JSON 引号转义成 `&quot;`，`<script>` 内不解码导致整个 JS 块解析失败。改用 `@json($securePath)`（不转义）

## 1.0.2 (2026-07-11)
- 网页自动扫描浏览器 Local Storage，找 `Bearer xxx` 凭证自动填入，免去手动抓 token（手动复制易漏字符导致 403）
- 凭证区增加详细错误框（HTTP 状态 + 后端响应）

## 1.0.1 (2026-07-11)
- **修复 MySQL 1215 外键约束错误**：不同 Xboard 版本 `v2_user.id`/`v2_server.id` 列类型与本表 `unsignedBigInteger` 不完全一致，外键要求逐字节匹配。去掉外键约束（孤儿记录无害，应用层自动忽略），migration 开头加 `Schema::dropIfExists` 清理半成品表

## 1.0.0 (2026-07-11)
- 初版发布
- 双 Hook 实现（`client.subscribe.servers` + `server.users.get`）
- `v2_extra_node_access` 多对多授权表
- 三种管理入口：可视化网页 / REST API / artisan 命令
- 两个配置项：`allow_hidden`、`sync_validity_check`
- 零核心改动，Octane 兼容（静态方法注册 Hook）
