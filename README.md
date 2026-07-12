# ExtraNodeAccess · Xboard 额外节点授权插件

> 给单个用户在套餐权限之外，**额外授权节点访问**——无需为每个定制客户新建专属套餐。
>
> Grant any user access to specific nodes beyond their subscription plan — no more creating a dedicated plan for every custom customer.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](./LICENSE)
[![Platform](https://img.shields.io/badge/Platform-Xboard-blueviolet)](https://github.com/cedar2025/Xboard)
[![Version](https://img.shields.io/badge/version-1.0.6-green)](./CHANGELOG.md)

---

## 🎯 这是什么

一个 **Xboard**（V2Board 系）插件，解决「个性化定制单人客户越来越多，需要为单个客户单独开放某些节点（尤其是不对其他组显示的隐藏节点）」的需求。

**旧做法的痛**：为每个定制客户新建一个专属套餐 → 把所有节点挂到该套餐 → 套餐数与维护成本随客户数线性膨胀。

**本插件**：直接给指定用户额外开某几个节点，**零核心改动**，靠 Xboard 两个现成 Hook 叠加一层权限过滤。

> 💡 起源于 Xboard Issue #989（Feature Request：给单个用户增加套餐之外的节点使用权）。

## ✨ 特性

- **零核心改动** — 纯插件实现，不碰 Xboard 源码，升级不冲突
- **支持隐藏节点** — 可授权 `show=false` 的节点，实现 VIP 独享
- **即时生效** — 授权后自动触发节点用户表同步，无需重启
- **三种管理入口** — 可视化网页 / REST API / artisan 命令
- **同步诊断** — 一键排查「授权后连不上」
- **Octane 兼容** — 长驻进程下 Hook 回调不累积
- **自动鉴权** — 管理网页自动读取后台凭证，免手动抓 token

## 📸 截图

<!-- 上传后在此放一张管理网页的截图，最直观 -->
<!-- ![管理界面](docs/screenshot.png) -->

## 🚀 快速开始

1. 下载 [最新版 extra-node-access.zip](./extra-node-access.zip)
2. Xboard 后台 → **插件管理** → **上传插件** → 选 zip
3. 列表点 **安装** → 点 **启用**
4. 浏览器打开 `https://你的域名/extra-node-access` 开始授权

> 不需要 SSH、不需要 `composer dump-autoload`。

详见 [PRD.md §6 安装与使用](./PRD.md)。

## 📖 使用方式（三选一）

### 🌟 方式一：可视化网页（推荐）

打开 `/extra-node-access`，自动读取后台凭证 → 选用户 + 选节点 + 填备注 → 授权。

### 方式二：REST API

```bash
curl -X POST https://你的域名/api/v2/{secure_path}/extra-node/grant \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{"user_id":88,"server_id":12,"remark":"VIP独享"}'
```

### 方式三：命令行

```bash
php artisan extra-node:grant 88 12 --remark="VIP独享"   # 授权
php artisan extra-node:list                              # 查询
php artisan extra-node:revoke 88 12                      # 取消
```

## 🩺 同步诊断

网页「同步诊断」卡片，输入用户ID + 节点ID，一键输出：
- 节点 `group_ids` 是否为空（空则同步 Hook 不触发）
- 节点 WS 是否在线
- 目标用户是否在节点用户表
- 用户封禁 / 过期 / 流量超额状态

排查「订阅看得到但连不上」的神器。

## 🔧 配置项

后台 → 插件管理 → 额外节点授权 → 配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `allow_hidden` | 开启 | 是否允许授权隐藏节点（`show=false`） |
| `sync_validity_check` | 开启 | 同步时校验用户未封禁 / 未过期 / 流量未超额 |

## ⚠️ 已知限制

- **节点必须至少属于一个权限组**：`group_ids` 为空的节点，Xboard 的 `getAvailableUsers` 不触发同步 Hook。建议给独享节点设一个**不含任何用户的空组**。
- **中转节点**（`parent_id`）：走中转需「入口节点 + 落地节点」都授权。
- **后台前端无法注入菜单**：admin 是独立 SPA，管理走独立网页 + API。

## 🛠️ 技术要点

- 双 Hook：`client.subscribe.servers`（订阅侧）+ `server.users.get`（同步侧），缺一不可
- Hook 回调用**静态方法**注册，规避 Octane 闭包累积
- 字段加工照搬 `getAvailableServers`，保证额外节点配置正确
- 鉴权：网页自动扫描 Local Storage 读 Bearer token

详见 [PRD.md](./PRD.md)。

## 📁 项目结构

```
ExtraNodeAccess/
├── PRD.md                 # 完整产品需求文档（必读）
├── CHANGELOG.md           # 版本变更记录
├── build.sh               # 一键打包脚本
├── extra-node-access.zip  # 当前发布产物
└── ExtraNodeAccess/       # 插件源码（部署到 Xboard 的 plugins/ 下）
```

## 🔨 自行构建

```bash
git clone https://github.com/PiPi-happy/ExtraNodeAccess.git
cd ExtraNodeAccess
./build.sh    # 生成 extra-node-access.zip
```

## 📝 License

[MIT](./LICENSE) © Peter

---

如果这个插件帮到了你，欢迎 ⭐ Star 让更多人看到。
