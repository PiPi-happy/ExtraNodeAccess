# 额外节点授权插件（ExtraNodeAccess）

给单个用户在套餐权限之外**额外授权节点**（含隐藏节点），无需为每个定制客户新建专属套餐。

**适用场景**：VIP 客户独享节点、临时开放特定节点给某用户、给员工单独开测试节点。

---

## 一、安装（纯网页操作，无需 SSH / 命令行）

> 准备：`extra-node-access.zip`（约 18KB）

| 步骤 | 操作 | 作用 |
|---|---|---|
| ① | 登录后台 → 左侧菜单 **「插件管理」** | 进入插件页 |
| ② | 点 **「上传插件」** → 选 `extra-node-access.zip` → 确定 | 把文件放到 `plugins/ExtraNodeAccess/` |
| ③ | 列表出现「额外节点授权」→ 点 **「安装」** | 创建数据表 `v2_extra_node_access` |
| ④ | 点 **「启用」**（状态变绿 ✅） | 注册订阅/同步两个 Hook，插件开始生效 |
| ⑤ | （可选）点 **「配置」** | 调整 `allow_hidden`、`sync_validity_check` |

完成后无需重启，立即生效。

**验证安装成功**：
- 插件列表里「额外节点授权」显示「已启用」
- 浏览器访问 `https://你的域名/extra-node-access` 能打开管理页

---

## 二、使用方法（三选一，推荐方式一）

### 🌟 方式一：可视化网页（最简单，推荐）

1. 浏览器打开 `https://你的域名/extra-node-access`
2. 首次使用，在「Admin Token」框粘贴后台管理员 token → 点「保存凭证」
   - **怎么拿 token**：后台页面按 `F12` → `Application` → `Local Storage` → 复制 `token` 字段的值
   - 若后台和本页同域且已登录后台，token 可留空（自动用 cookie）
3. 「新增授权」区：选择用户 + 选择节点 + 填备注 → 点 **「授权」**
4. 下方列表实时刷新，点「取消」可撤销

### 方式二：API（对接脚本/外部系统用）

```bash
# 授权
curl -X POST https://你的域名/api/v2/{secure_path}/extra-node/grant \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{"user_id":88,"server_id":12,"remark":"VIP独享"}'

# 取消
curl -X POST https://你的域名/api/v2/{secure_path}/extra-node/revoke \
  -H "Authorization: Bearer {admin_token}" \
  -d '{"user_id":88,"server_id":12}'

# 列表
curl https://你的域名/api/v2/{secure_path}/extra-node/grants \
  -H "Authorization: Bearer {admin_token}"
```

> `{secure_path}` 是后台管理路径前缀（后台请求 `/api/v2/XXXXX/...` 里的 XXXXX），在 **后台 → 系统设置 → 安全路径** 可查。

### 方式三：命令行（SSH，调试 / 批量用）

```bash
php artisan extra-node:grant 88 12 --remark="VIP独享"   # 授权
php artisan extra-node:revoke 88 12                      # 取消
php artisan extra-node:list --user=88                    # 查询
```

---

## 三、怎么找到 user_id 和 server_id？

| 要找 | 位置 |
|---|---|
| **user_id** | 后台 → 用户管理 → 点开某用户 → 详情里的「ID」 |
| **server_id** | 后台 → 节点管理 → 节点列表的「ID」列 |

授权后，该用户下次拉订阅就会看到这个节点，立即可用。

---

## 四、配置项说明（后台 → 插件管理 → 额外节点授权 → 配置）

| 配置 | 默认 | 说明 |
|---|---|---|
| `allow_hidden` | 开启 | 是否允许授权 `show=false`（对其他用户隐藏）的节点。独享节点场景建议开启 |
| `sync_validity_check` | 开启 | 同步到节点时是否校验用户未封禁 / 未过期 / 流量未超额。建议开启 |

---

## 五、卸载

插件列表 → 「额外节点授权」→ **禁用** → **卸载**
（自动回滚迁移、删除 `v2_extra_node_access` 表、清理缓存，无残留）

---

## 六、常见问题

**Q: 上传后插件列表没出现？**
A: 刷新页面；确认 zip 内是 `ExtraNodeAccess/` 目录结构（本包已是）。

**Q: 点「启用」后用户仍看不到额外节点？**
A: 检查：① 用户未过期、未封禁；② 节点本身在线；③ 授权记录确实存在（网页列表或 `extra-node:list` 查）。

**Q: 网页提示「鉴权失败」？**
A: admin token 不对或已过期，重新从后台复制粘贴。

**Q: 额外节点的流量怎么算？**
A: 计入用户本人账户流量（按节点倍率），和套餐内节点完全一致。

**Q: 用户过期/封禁后，额外节点还能连吗？**
A: 不能。同步侧会自动校验（`sync_validity_check` 开启时），过期/封禁用户不会被推送到节点。

---

## 七、工作原理（给技术人员）

通过 Xboard 两个现成 Hook 叠加一层授权过滤，零核心改动：

- **订阅侧** `client.subscribe.servers`（`ClientController.php:58`）：把用户额外授权的节点追加进订阅列表
- **同步侧** `server.users.get`（`ServerService.php:111`）：把额外授权的用户追加进节点用户列表

两个 Hook 缺一不可，否则会出现"订阅看得到但连不上"或反之。流量上报链路无 group 校验，授权后流量能正常扣减。

插件数据表 `v2_extra_node_access` 记录 `user_id ↔ server_id` 多对多关系，带缓存（订阅/同步时只查一次缓存）。
