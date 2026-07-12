# ExtraNodeAccess 额外节点授权 — 产品需求文档（PRD）

| 项 | 值 |
|---|---|
| 项目名 | ExtraNodeAccess（额外节点授权） |
| 插件 code | `extra_node_access` |
| 当前版本 | 1.0.6 |
| 作者 | Peter |
| 目标平台 | Xboard（Laravel 12 + Octane） |
| 一句话 | 给单个用户在套餐权限之外，额外授权节点访问（含隐藏节点），无需为每个定制客户新建专属套餐 |

---

## 1. 背景与动机

### 1.1 来源
GitHub Issue #989（PiPi-happy）：《强烈希望增加"给单个用户增加套餐之外的节点使用权"》。

### 1.2 痛点
个性化定制单人客户增多，运营者需要为单个客户"在原有套餐基础上，单独开放某个/某些节点（尤其是不对其他组显示的隐藏节点）"。

**现状的代价**：只能为每个客户新建一个专属套餐 → 把所有节点挂到该套餐的权限组 → 随定制客户增长，套餐数与维护成本线性膨胀。

### 1.3 上游建议
rebecca554owen（Xboard Contributor）：该功能偏个性化，进核心会增加复杂度；更适合用**插件 + 现成 Hook** 实现，在订阅拉取/节点同步时做一层额外权限过滤，不改核心。

---

## 2. 目标与非目标

### 2.1 目标
- ✅ 零核心代码改动，纯插件实现
- ✅ 用户↔节点多对多额外授权
- ✅ 支持授权 `show=false` 的隐藏节点（VIP 独享场景）
- ✅ 提供三种管理入口：可视化网页 / REST API / artisan 命令
- ✅ 授权即时生效，无需重启
- ✅ Octane 长驻进程兼容

### 2.2 非目标
- ❌ 不改变套餐语义（额外授权是"叠加"，原 group/套餐权限不变）
- ❌ 不注入 Xboard 后台 SPA 菜单（技术限制：admin 是独立 Vite SPA）
- ❌ 不改动核心流量计费（额外节点流量正常计入用户账户）

---

## 3. 功能需求

### 3.1 核心功能
| 功能 | 说明 |
|---|---|
| 授权 | 给指定用户额外开放指定节点 |
| 取消授权 | 撤销某条授权 |
| 查询 | 列出所有授权关系，支持按用户/节点筛选 |
| 即时生效 | 授权变更后，节点用户表立即更新 |

### 3.2 配置项（后台插件配置页可改）
| 配置 | 默认 | 说明 |
|---|---|---|
| `allow_hidden` | 开启 | 是否允许授权 `show=false` 的隐藏节点 |
| `sync_validity_check` | 开启 | 同步到节点时是否校验用户未封禁/未过期/流量未超额 |

### 3.3 三种管理入口
1. **可视化网页**（推荐）：`GET /extra-node-access`，自动扫描浏览器 Local Storage 读取后台 Bearer token，免手动抓
2. **REST API**：`/api/v2/{secure_path}/extra-node/*`，admin 中间件鉴权
3. **artisan 命令**：`extra-node:grant|revoke|list`

---

## 4. 技术方案设计

### 4.1 整体架构：双 Hook 叠加过滤

```
v2_extra_node_access 表（user_id ↔ server_id 多对多）
        │
   ┌────┴──────────────────┐
   ▼                       ▼
订阅侧 filter            同步侧 filter
client.subscribe.servers  server.users.get
追加额外节点到订阅        追加额外用户到节点用户表
   │                       │
   ▼                       ▼
用户订阅看到额外节点  →  节点接受用户连接  →  流量正常扣减
```

**两个 Hook 缺一不可**，否则出现"订阅看得到但连不上"或反之。

### 4.2 数据模型

`v2_extra_node_access`：
| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| user_id | bigint | 被授权用户 |
| server_id | bigint | 额外授权节点 |
| remark | varchar(255) | 备注 |
| created_at/updated_at | timestamp | |

- `UNIQUE(user_id, server_id)` 防重复
- `INDEX(server_id)` 同步侧反查
- **不使用外键约束**（不同 Xboard 版本 `v2_user.id`/`v2_server.id` 列类型可能不一致，外键触发 MySQL 1215）

### 4.3 订阅侧（简单，无坑）
- Hook：`client.subscribe.servers`（`ClientController.php:58`），签名 `($servers, $user, $request)`
- 逻辑：查用户额外授权的 server_id → `Server::whereIn` → 字段加工 → append 到 `$servers`
- **字段加工**（照搬 `getAvailableServers`，否则连不上）：动态端口 `Helper::randomPort`、`password=generateServerPassword($user)`、`rate=getCurrentRate()`，再 `->toArray()`
- 关键：Eloquent `__set` 走 `setAttribute`，动态设的 password/ports 进 attributes、会出现在 toArray（协议类读 `$item['password']`）

### 4.4 同步侧（两个陷阱，订阅侧没有）

**陷阱 1：`group_ids` 为空的节点不触发 Hook**
- `getAvailableUsers`（`ServerService.php:92`）开头 `if (empty($groupIds)) return collect();` —— 直接返回，不调 filter
- 影响：未设权限组的节点（隐藏独享节点常见），同步 Hook 收不到调用，额外用户永远进不去
- 解法：让节点至少属于一个权限组——可新建一个**不含任何用户的空组**赋给它（该组正常用户=0，插件再注入目标用户，实现独享）

**陷阱 2：授权变更后节点不自动同步**
- `NodeSyncService::notifyUserChanged(U)` 只推 U 到 `whereJsonContains('group_ids', U->group_id)` 的节点，**不含**目标独享节点（否则 U 本来就能访问）
- 影响：grant 后目标节点收不到该用户（WS 模式尤其；HTTP/UniProxy 节点靠定时拉取 ~60s 补上）
- 解法：**grant/revoke 后主动调 `NodeSyncService::notifyFullSync($serverId)`** 触发该节点全量同步（会重跑 getAvailableUsers→触发 Hook）

### 4.5 鉴权机制（admin token）
- Xboard 登录返回 `auth_data = "Bearer {random40}"`（`AuthService.php:28` 故意去掉 sanctum 的 `id|` 前缀）
- sanctum 的 `findToken` 对无 `|` 的 token 按 `sha256(token)` 查 `personal_access_tokens` 表
- 网页端：自动遍历 Local Storage 找 `Bearer xxx` 自动填入（避免手动复制漏字符）
- 注意区分两套 token：admin/用户端用 sanctum PAT（`Bearer {random40}`）；订阅端 `/s/{token}` 用 `v2_user.token`（`User::where('token')`）

### 4.6 Octane 兼容（关键）
- `PluginManager` 是 scoped 服务，Octane 下每请求重新 boot 插件
- 若用闭包注册 Hook，`getCallableId` 用 `spl_object_hash`（每请求新闭包→新 hash），回调会**累积**
- 解法：用**静态方法 + `[__CLASS__, 'method']`** 注册，callable id 固定为 `Plugin\ExtraNodeAccess\Plugin::method`，每请求 boot 覆盖同一 key，不累积

### 4.7 流量上报
- `processTraffic`→`trafficFetch`→`TrafficFetchJob` 按 `User::where('id',$uid)` 主键扣减，**不校验** user↔server 权限
- 所以只要同步侧把用户推进节点用户表，额外节点的流量能正常扣

---

## 5. 文件结构

```
ExtraNodeAccess/
├── config.json                              # 清单 + 配置项定义
├── Plugin.php                               # 入口：注册双 Hook（静态方法）
├── database/migrations/
│   └── 2026_07_11_000001_create_extra_node_access_table.php
├── Models/ExtraNodeAccess.php
├── Services/ExtraNodeAccessService.php      # 核心：查询(带缓存)/字段加工/grant/revoke/触发同步
├── Http/Controllers/ExtraNodeAccessController.php  # API（grants/grant/revoke/options/diagnose）
├── routes/api.php                           # admin API 路由
├── routes/web.php                           # 管理网页路由
├── Commands/{Grant,Revoke,List}Command.php
└── resources/views/manage.blade.php         # 可视化网页（自动读 token + 诊断）
```

---

## 6. 安装与使用

### 6.1 安装（纯网页 3 步，无需 SSH/composer）
1. 后台 → 插件管理 → 上传 `extra-node-access.zip` → 确定
2. 列表点「安装」（建表）
3. 列表点「启用」（注册 Hook）

> 不需要 `composer dump-autoload`：PSR-4 `Plugin\=>plugins/` 始终生效。升级时上传更高版本 zip 覆盖即可。

### 6.2 使用
- **网页**：浏览器开 `/extra-node-access`，自动读 token，选用户+节点+备注→授权
- **API**：`POST /api/v2/{secure_path}/extra-node/grant` body `{"user_id":88,"server_id":12,"remark":"VIP独享"}`
- **命令**：`php artisan extra-node:grant 88 12 --remark="VIP独享"`

### 6.3 卸载
后台 → 禁用 → 卸载（自动删表 + 清缓存）

---

## 7. 诊断功能（1.0.5+）

网页「同步诊断」卡片：输入用户ID + 节点ID，一键诊断节点为何收不到用户，输出：
- 节点 group_ids 是否空（空则同步 Hook 不触发）
- 节点 WS 是否在线（影响 notifyFullSync 即时推送）
- 目标用户是否在 getAvailableUsers 列表（同步侧是否注入）
- 用户封禁/过期/流量超额状态（sync_validity_check 是否拦截）
- 诊断 API 自带 try-catch，异常时返回真实错误信息（文件+行号）

---

## 8. 已知限制

1. **节点必须至少属于一个权限组**：`group_ids` 空的节点，同步侧 Hook 不触发，授权的用户进不去。建议给独享节点设一个空组。
2. **HTTP（UniProxy）节点同步有延迟**：grant 后靠节点定时拉取（~60s）；WS 节点由 `notifyFullSync` 即时推送。
3. **后台前端无法注入菜单**：admin 是独立 SPA，管理走独立网页 + API。

---

## 9. 版本历史

| 版本 | 主要变更 |
|---|---|
| 1.0.0 | 初版：双 Hook + 关联表 + 三入口 |
| 1.0.1 | 修复 MySQL 1215 外键错误（去外键约束 + dropIfExists） |
| 1.0.2 | 网页自动扫描 Local Storage 读 token |
| 1.0.3 | 修复 Blade `{{json_encode}}` 转义导致 JS 失效（改 `@json`） |
| 1.0.4 | grant/revoke 后主动 `notifyFullSync` 触发节点同步；group_ids 空警告 |
| 1.0.5 | 新增同步诊断功能（定位"授权后连不上"） |
| 1.0.6 | 诊断 API 加 try-catch 暴露真实异常；作者改 Peter |

详细变更见 [CHANGELOG.md](./CHANGELOG.md)。

---

## 10. 后续计划

- [ ] 确认并修复 1.0.6 诊断 API 的 500 根因（待用户反馈异常详情）
- [ ] 彻底解决"授权后连不上"（疑似 group_ids 空 或 节点 WS 离线）
- [ ] 可选：批量授权（一次给多用户/多节点）
- [ ] 可选：授权有效期（到期自动取消）
- [ ] 可选：授权关系导入导出

---

## 11. 关键参考

- Issue #989：给单个用户增加套餐之外的节点使用权
- Xboard 插件开发指南：`docs/en/development/plugin-development-guide.md`
- 核心 Hook 点：`ClientController.php:58`、`ServerService.php:111`
- 同步服务：`app/Services/NodeSyncService.php`
- 鉴权：`app/Services/AuthService.php`
