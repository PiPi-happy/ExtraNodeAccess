# ExtraNodeAccess · Xboard 额外节点授权插件

> 给单个用户在套餐权限之外，**额外授权节点访问**——无需为每个定制客户新建专属套餐。

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)
[![Platform](https://img.shields.io/badge/Platform-Xboard-blueviolet)](https://github.com/cedar2025/Xboard)

## 这是什么

定制客户一多，每人建一个专属套餐会让套餐数和维护成本线性膨胀。本插件直接给指定用户**额外开某几个节点**（含对其他组隐藏的节点），零核心改动，靠 Xboard 两个现成 Hook 叠加一层权限过滤。起源于 [Issue #989](https://github.com/cedar2025/Xboard/issues/989)。

## 特性

- **零核心改动** — 纯插件实现，升级不冲突
- **支持隐藏节点** — 可授权 `show=false` 的节点，VIP 独享
- **即时生效** — 授权后自动触发节点同步，另有定时兜底
- **三种管理入口** — 可视化网页 / REST API / artisan 命令
- **同步诊断** — 一键排查「授权后连不上」

## 🚀 快速开始

1. 从 [Releases](https://github.com/PiPi-happy/ExtraNodeAccess/releases/latest) 下载 `extra-node-access.zip`
2. Xboard 后台 → **插件管理** → 上传 → **安装** → **启用**
3. 打开 `https://你的域名/extra-node-access`，首次按提示填一次 **Secure Path**（后台保密路径，仅存本机浏览器）即可使用

> 无需 SSH、无需 `composer dump-autoload`。
>
> ⚠️ **升级后必须重载 Octane**（常驻进程不热更新已加载的类，否则新增端点会 500）：
>
> ```bash
> docker exec -w /www xboard-xboard-1 php artisan octane:reload
> docker exec xboard-xboard-1 supervisorctl restart all
> # 或直接: docker restart xboard-xboard-1
> ```

## 使用方式

**网页（推荐）** — 顶部统计总览 → 按用户 / 按节点双视角 → 「+ 新增授权」或行内「管理」进入抽屉：搜索勾选、批量授权/撤销，支持分页与关键字搜索，上百定制客户也能轻松管理。页面为公开静态壳、不含 secure_path；admin token 自动扫描后台登录凭证。

**API** — `user_id` / `server_id` 均支持单个值或数组批量：

```bash
curl -X POST https://你的域名/api/v2/{secure_path}/extra-node/grant \
  -H "Authorization: Bearer {admin_token}" -H "Content-Type: application/json" \
  -d '{"user_id":88,"server_id":12,"remark":"VIP独享"}'
```

**命令行** —

```bash
php artisan extra-node:grant 88 12 --remark="VIP独享"
php artisan extra-node:list                # --user= / --server= 筛选
php artisan extra-node:revoke 88 12
```

**同步诊断** — 网页右上角「同步诊断」，输入用户ID + 节点ID，一键输出节点 `group_ids`、WS 在线状态、用户是否已注入节点用户表、封禁/过期/流量状态，排查「订阅看得到但连不上」。

## 配置项

后台 → 插件管理 → 额外节点授权 → 配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `allow_hidden` | 开启 | 是否允许授权隐藏节点（`show=false`） |
| `sync_validity_check` | 开启 | 同步时校验用户未封禁 / 未过期 / 流量未超额 |
| `auto_resync` | 开启 | 定时兜底同步，避免节点端用户表不同步导致「看得到连不上」 |
| `resync_interval_minutes` | 1 | 兜底同步间隔（分钟），节点多时建议 5 |

## 已知限制

- **节点必须至少属于一个权限组**：`group_ids` 为空的节点不触发同步 Hook，独享节点建议挂一个**不含任何用户的空组**
- **中转节点**（`parent_id`≠0）不连面板 WS：需「入口 + 落地」都授权，且授权靠节点定时拉取生效
- admin 后台是独立 SPA，无法注入菜单，管理走独立网页 + API

## 面向开发者

- 双 Hook：`client.subscribe.servers`（订阅侧）+ `server.users.get`（同步侧），缺一不可
- Hook 回调用静态方法注册，规避 Octane 闭包累积；额外节点的字段加工照搬 `getAvailableServers`，保证能连上

```bash
git clone https://github.com/PiPi-happy/ExtraNodeAccess.git
cd ExtraNodeAccess && ./build.sh   # 生成 extra-node-access.zip
```

## License

[MIT](./LICENSE) © PiPi-happy

如果帮到了你，欢迎 ⭐ Star。
