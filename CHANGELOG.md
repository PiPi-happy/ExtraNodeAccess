# 变更记录

## 1.4.0 (2026-10-25) · 移除中转管理，聚焦节点授权 + 加速订阅
- **移除 Mieru 中转管理全部功能**（v2.0/v2.5 遗留需求，迭代确认方案无法达成目标，正式废弃）：
  - 删除「中转节点 / 开通记录」管理页视图、`/mieru/*` 管理端点、`/api/v2/ena-agent/v1/*` Agent 端点
  - 删除 `ena-mieru:reconcile` 命令与定时调度、ena-agent 交付物、3 张 mieru 相关 migration
  - 删除配置项：`mieru_sync_interval_minutes`、`mieru_default_speed_mbps`、`mieru_portal_slug`
  - 数据库已建的 `v2_ena_relay_nodes` / `v2_ena_mieru_provisions` 表不影响运行，如需清理可手动 DROP
- **保留加速订阅端点**（`GET /api/v2/ena-sub/v1/clash?token=`）：OpenClash 等客户端订阅该端点即自动融合 mieru 链式加速，参数 `clash_sub_*` 后台可调——该能力不依赖被移除的中转管理体系
- **节点授权（v1）功能不变**：双 Hook 追加、管理页、定时兜底同步原样保留

## 1.3.0 (2026-10-21) · v2.0 迭代：中转管理全面升级
- **管理页左侧导航栏**：功能拆分为「节点授权 / 中转管理」两版块（中转管理含「中转节点」「开通记录」两个子视图）
- **Mieru 开通配置迁移到中转节点维度**：每个中转节点独立配置「Mieru 自动开通开关 + 绑定套餐 ID（CSV）+ 端口池 + 保留端口」，管理页节点卡片直接编辑保存（不再藏在插件全局配置）；支持添加任意多个中转节点
- **开通记录支持「移除」**：吊销后 Agent 自动在中转 VPS 执行 `nobrand --user-del` 删除用户并释放端口（此前仅停用）
- **新增 `/nodes/update` 端点**：节点卡片保存（名称/播报地址/端口/传输/端口池/保留端口/自动开通开关/套餐绑定/启停）
- **开通记录列升级**：显示中转节点名、用户专属端口；手动开通支持选择目标节点与「越过资格」
- **ena-agent 状态持久化**：每用户变更指纹缓存（/var/lib/ena-agent/state.tsv），无变化跳过 nobrand 调用；被中断可断点续传
- **修复**：Agent systemd `TimeoutStartSec=300` 小于首轮全量收敛耗时，导致被反复杀死、永远无法回报（改 `infinity`）
- **收编 NoBrand-OneClick**：fork 至 [PiPi-happy/NoBrand-OneClick](https://github.com/PiPi-happy/NoBrand-OneClick)（GPL-3.0），本插件 Agent 依赖该仓库 ≥3.2.6，不再跟随原作者更新
- 实测：沪港 IPLC 22 用户全量自动开户收敛成功（端口/到期/限速精确映射）

## 1.2.0 (2026-10-21) · v2.0 规划 M1「Mieru 中转自动开通」面板骨架
- **新增 Mieru 中转自动开通模块（PRD §13 / M1）**：用户当前有效套餐命中「指定套餐」（`mieru_enabled_plan_ids`，多选任一）即自动生成 Mieru 开通记录（期望状态），配额=套餐月流量、限速=套餐带宽（Mbps）、到期=套餐 expired_at
- **新增 Agent API**（`/api/v2/ena-agent/v1/desired|report`，独立前缀 + 节点级 Bearer token 鉴权，不走 admin secure_path）：中转 VPS 上的 `ena-agent`（M2 交付）按节奏拉取期望状态并调用 `nobrand mieru` 幂等收敛本机用户
- **新增两张表**：`v2_ena_relay_nodes`（中转节点注册，token 仅存 sha256）、`v2_ena_mieru_provisions`（开通记录与状态机：pending/active/suspended/expired/revoked/error）
- **管理页新增「中转开通」页签**：总览（开关/套餐映射/节点在线/统计）、中转节点管理（新建一次性展示 Token、轮换、启停）、开通记录（筛选/手动开通/启停/重置密码/查看凭据）、立即对账
- **新增配置项**：`mieru_enabled`、`mieru_enabled_plan_ids`、`mieru_sync_interval_minutes`（默认 5）、`mieru_default_speed_mbps`、`mieru_portal_slug`（M3 门户使用）
- **新增 artisan 命令** `ena-mieru:reconcile` + 定时调度（Octane 兼容，与 v1 调度并存）
- Mieru 密码 `Crypt` 加密存库；对账对 revoked 终态不自动拉起
- ⚠️ **升级注意**：上传新版 zip 后必须 `php artisan octane:reload` + `supervisorctl restart all`（或重启容器），否则新增端点会 500（同 1.1.0 根因）

## 1.1.1 (2026-09-18)
- **新增「+ 新增授权」按钮**：修复新客户（尚无任何授权记录、不出现在列表中）无法进入首次授权流程的入口缺失——从邮箱搜索选择用户后直接进入其授权面板
- **修复「定时同步间隔」配置项在 Xboard 后台渲染为空白**：Xboard 插件配置渲染仅支持 string / number / boolean，不支持 select，改为 number 类型（填正整数，常用 1/2/5/10）

## 1.1.0 (2026-09-18)
- **管理页面全面重构**：按用户 / 按节点双视角 + 顶部统计条 + 右侧抽屉批量授权/撤销 + 分页与关键字搜索，支持上百定制客户的规模化管理
- **安全修复**：管理页面不再把 `secure_path` 渲染进公开 HTML（此前任何未登录访客查看页面源码即可获得后台 API 保密前缀）；改为管理员首次使用时手动填写一次（存浏览器 Local Storage）
- 批量授权 API：`POST /grant` 支持 `user_ids[]` / `server_ids[]` 数组，一次授权多用户/多节点
- 新增管理端点：`/by-user`（按用户聚合分页）、`/by-server`（按节点聚合）、`/users`（用户远程搜索，替代旧版一次只取最新 50 人的下拉）、`/servers`（节点池含授权人数）、`/stats`（统计）
- 修复并发重复授权触发唯一键冲突导致 500 的问题（现返回友好提示）
- 修复定时兜底同步频率不可调的问题：新增配置项 `resync_interval_minutes`（1/2/5/10 分钟，默认 1 分钟保持与 1.0.7 一致）
- 新 UI：Acme 风格设计语言（#2563eb 主色、Inter 字体、12px 卡片圆角、毛玻璃顶栏、stroke 1.5 线性图标），全量输出转义防 XSS，按钮防重复点击
- 旧 API（`/grants` 全量数组、`/options`）保持兼容；artisan 命令不变
- ⚠️ **升级注意**：Xboard Octane 常驻进程不会热更新已加载的类——上传新版 zip 后必须执行 `php artisan octane:reload`（并重载 WS worker），否则新增端点会 500。这同时也是 1.0.6「诊断 API 500 未复现」遗留问题的根因

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
