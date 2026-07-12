# 变更记录

## 1.0.6 (2026-07-11)
- 诊断 API (`diagnose`) 包 try-catch，异常时返回真实错误信息（文件+行号），不再被 Xboard Handler 吞成通用 500
- 作者改为 Peter

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
