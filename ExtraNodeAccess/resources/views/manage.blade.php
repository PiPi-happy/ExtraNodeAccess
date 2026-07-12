<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>额外节点授权管理</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            margin: 0; padding: 24px; background: #f5f7fa; color: #2c3e50;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .subtitle { color: #8492a6; font-size: 13px; margin-bottom: 20px; }
        .card {
            background: #fff; border-radius: 8px; padding: 18px 20px; margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .card h2 { font-size: 15px; margin: 0 0 14px; border-left: 3px solid #409eff; padding-left: 8px; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 180px; }
        label { font-size: 12px; color: #8492a6; }
        input, select {
            padding: 7px 10px; border: 1px solid #dcdfe6; border-radius: 4px; font-size: 14px; outline: none;
        }
        input:focus, select:focus { border-color: #409eff; }
        button {
            padding: 7px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;
            background: #409eff; color: #fff;
        }
        button:hover { background: #66b1ff; }
        button.danger { background: #f56c6c; }
        button.danger:hover { background: #f78989; }
        button.ghost { background: #fff; color: #606266; border: 1px solid #dcdfe6; }
        button.ghost:hover { background: #f5f7fa; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #ebeef5; }
        th { color: #909399; font-weight: 600; background: #fafafa; }
        td { color: #606266; }
        tr:hover td { background: #f5f7fa; }
        .tag { display: inline-block; padding: 1px 7px; border-radius: 3px; font-size: 11px; }
        .tag.hidden { background: #fef0f0; color: #f56c6c; }
        .tag.shown { background: #f0f9eb; color: #67c23a; }
        .empty { text-align: center; color: #c0c4cc; padding: 30px; }
        #toast {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            padding: 10px 20px; border-radius: 4px; color: #fff; font-size: 14px;
            opacity: 0; transition: opacity .3s; z-index: 9999; max-width: 90vw;
        }
        #toast.show { opacity: 1; }
        #toast.ok { background: #67c23a; }
        #toast.err { background: #f56c6c; }
        .hint { font-size: 12px; color: #909399; line-height: 1.6; margin-top: 8px; }
        code { background: #f5f7fa; padding: 1px 5px; border-radius: 3px; color: #e63946; }
        .config-badges { font-size: 12px; color: #606266; margin-top: 6px; }
        .errbox { margin-top: 10px; padding: 10px 12px; background: #fef0f0; border: 1px solid #fbc4c4;
                  border-radius: 4px; color: #c45656; font-size: 12px; line-height: 1.6; display: none;
                  word-break: break-all; }
        .okbox { color: #67c23a; font-size: 12px; margin-top: 6px; display: none; }
    </style>
</head>
<body>
<div class="container">
    <h1>额外节点授权管理</h1>
    <div class="subtitle">在不新建套餐的前提下，给指定用户额外开放节点访问权限（支持隐藏节点）。插件：ExtraNodeAccess</div>

    <div id="toast"></div>

    <!-- 凭证区 -->
    <div class="card">
        <h2>管理员凭证</h2>
        <div class="row">
            <div class="field" style="flex: 3;">
                <label>Admin Token（Bearer，已自动从后台 Local Storage 读取）</label>
                <input type="text" id="token" placeholder="自动填充；如为空请手动粘贴后台 token（不含 Bearer 前缀）">
            </div>
            <button onclick="saveToken()">保存凭证</button>
            <button class="ghost" onclick="rescanToken()">重新扫描</button>
        </div>
        <div class="okbox" id="autoOk"></div>
        <div class="hint">
            本页打开时会<strong>自动扫描</strong>浏览器的 Local Storage，找到后台存的 <code>Bearer xxx</code> 凭证并填入（同域名下后台已登录即可）。<br>
            扫描依据：Xboard 登录返回 <code>auth_data = "Bearer {token}"</code>，sanctum 按此鉴权。
        </div>
        <div class="errbox" id="authErr"></div>
        <div class="config-badges" id="configBadges"></div>
    </div>

    <!-- 新增授权 -->
    <div class="card">
        <h2>新增授权</h2>
        <div class="row">
            <div class="field">
                <label>用户</label>
                <select id="userSelect"><option value="">加载中...</option></select>
            </div>
            <div class="field">
                <label>节点（含隐藏节点）</label>
                <select id="serverSelect"><option value="">加载中...</option></select>
            </div>
            <div class="field" style="flex: 1.5;">
                <label>备注（可选）</label>
                <input type="text" id="remark" placeholder="如：VIP客户A独享">
            </div>
            <button onclick="addGrant()">授权</button>
            <button class="ghost" onclick="loadAll()">刷新</button>
        </div>
    </div>

    <!-- 同步诊断 -->
    <div class="card">
        <h2>同步诊断（排查"授权后连不上"）</h2>
        <div class="hint">输入授权关系里的用户ID和节点ID（可从下方列表复制），一键诊断节点为何收不到该用户。</div>
        <div class="row">
            <div class="field" style="max-width:160px;">
                <label>用户 ID</label>
                <input type="number" id="diagUser" placeholder="如 88">
            </div>
            <div class="field" style="max-width:160px;">
                <label>节点 ID</label>
                <input type="number" id="diagServer" placeholder="如 12">
            </div>
            <button onclick="diagnose()">诊断同步</button>
        </div>
        <div class="errbox" id="diagResult" style="background:#f4f4f5;border-color:#dcdfe6;color:#606266;white-space:pre-wrap;font-family:monospace;"></div>
    </div>

    <!-- 授权列表 -->
    <div class="card">
        <h2>授权列表</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>用户</th><th>节点</th><th>显示</th>
                    <th>备注</th><th>授权时间</th><th>操作</th>
                </tr>
            </thead>
            <tbody id="grantBody">
                <tr><td colspan="7" class="empty">加载中...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    const SECURE_PATH = @json($securePath);
    const API_BASE = '/api/v2/' + SECURE_PATH + '/extra-node';

    // ---------- token 读写 ----------
    function getToken() { return localStorage.getItem('ena_admin_token') || ''; }
    function setToken(v) {
        v = (v || '').trim();
        if (v) localStorage.setItem('ena_admin_token', v);
        else localStorage.removeItem('ena_admin_token');
    }

    // 自动扫描 Local Storage，找后台存的 "Bearer xxx" 凭证
    function rescanToken() {
        document.getElementById('autoOk').style.display = 'none';
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            const v = (localStorage.getItem(k) || '').trim();
            if (/^bearer\s+/i.test(v)) {
                const pure = v.replace(/^bearer\s+/i, '').trim();
                if (pure.length >= 20) {
                    setToken(pure);
                    document.getElementById('token').value = pure;
                    showAutoOk('已自动读取后台凭证（来源 Local Storage key: ' + k + '）');
                    return true;
                }
            }
        }
        return false;
    }

    function showAutoOk(msg) {
        const el = document.getElementById('autoOk');
        el.textContent = '✅ ' + msg;
        el.style.display = 'block';
    }

    function toast(msg, type = 'ok') {
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.className = 'show ' + type;
        setTimeout(() => el.className = '', 2400);
    }

    function showAuthErr(msg) {
        const el = document.getElementById('authErr');
        el.innerHTML = '❌ ' + msg;
        el.style.display = 'block';
    }
    function clearAuthErr() { document.getElementById('authErr').style.display = 'none'; }

    // ---------- API ----------
    function authHeaders(extra = {}) {
        const h = { ...extra };
        const t = getToken();
        if (t) h['Authorization'] = 'Bearer ' + t;
        return h;
    }

    async function api(path, method = 'GET', body = null) {
        clearAuthErr();
        const opts = { method, headers: authHeaders(), credentials: 'same-origin' };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        let res;
        try {
            res = await fetch(API_BASE + path, opts);
        } catch (e) {
            const msg = '网络错误：' + e.message + '（API: ' + API_BASE + path + '）';
            showAuthErr(msg);
            throw new Error(msg);
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            const msg = `HTTP ${res.status}，响应非 JSON。API: ${API_BASE + path}<br>响应前 200 字: ${text.slice(0, 200)}`;
            showAuthErr(msg);
            throw new Error(msg);
        }
        if (res.status === 403) {
            const msg = '鉴权失败（403）。token 无效/未启用/secure_path 不一致。<br>' +
                '当前 API: ' + API_BASE + path + '<br>后端返回: ' + (json.message || '(空)');
            showAuthErr(msg);
            toast('鉴权失败，详见凭证区', 'err');
            throw new Error('Unauthorized');
        }
        if (json.status !== 'success') {
            const msg = `HTTP ${res.status}：${json.message || '操作失败'}`;
            showAuthErr(msg);
            throw new Error(msg);
        }
        return json.data;
    }

    // ---------- 业务 ----------
    function saveToken() {
        const t = document.getElementById('token').value;
        setToken(t);
        toast(t.trim() ? '凭证已保存' : '已清空凭证', 'ok');
        loadAll();
    }

    async function loadOptions() {
        const userSel = document.getElementById('userSelect');
        const srvSel = document.getElementById('serverSelect');
        try {
            const data = await api('/options');
            userSel.innerHTML = '<option value="">— 选择用户 —</option>' +
                data.users.map(u => `<option value="${u.id}">${u.email || ('用户#' + u.id)} (${u.id})</option>`).join('');
            srvSel.innerHTML = '<option value="">— 选择节点 —</option>' +
                data.servers.map(s => `<option value="${s.id}">${s.name} (${s.id})${s.show ? '' : ' [隐藏]'}</option>`).join('');
            const cfg = data.config || {};
            document.getElementById('configBadges').innerHTML =
                `✅ 鉴权通过。配置：allow_hidden=<code>${cfg.allow_hidden ? '开启' : '关闭'}</code>　` +
                `sync_validity_check=<code>${cfg.sync_validity_check ? '开启' : '关闭'}</code>`;
        } catch (e) { console.error(e); }
    }

    async function loadGrants() {
        const body = document.getElementById('grantBody');
        try {
            const list = await api('/grants');
            if (!list || list.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="empty">暂无授权记录</td></tr>';
                return;
            }
            body.innerHTML = list.map(g => `
                <tr>
                    <td>${g.id}</td>
                    <td>${g.user_email ? g.user_email + '<br><small>#' + g.user_id + '</small>' : '#' + g.user_id}</td>
                    <td>${g.server_name || '<i>已删除</i>'}<br><small>#${g.server_id}</small></td>
                    <td><span class="tag ${g.server_show ? 'shown' : 'hidden'}">${g.server_show ? '显示' : '隐藏'}</span></td>
                    <td>${g.remark || '-'}</td>
                    <td>${g.created_at || '-'}</td>
                    <td><button class="danger" onclick="removeGrant(${g.user_id}, ${g.server_id})">取消</button></td>
                </tr>`).join('');
        } catch (e) { body.innerHTML = '<tr><td colspan="7" class="empty">加载失败，请检查凭证（见上方凭证区错误）</td></tr>'; }
    }

    async function addGrant() {
        const userId = document.getElementById('userSelect').value;
        const serverId = document.getElementById('serverSelect').value;
        const remark = document.getElementById('remark').value.trim();
        if (!userId || !serverId) { toast('请选择用户和节点', 'err'); return; }
        try {
            const data = await api('/grant', 'POST', { user_id: +userId, server_id: +serverId, remark });
            toast(data.message || '授权成功', 'ok');
            document.getElementById('remark').value = '';
            loadGrants();
        } catch (e) { toast(e.message, 'err'); }
    }

    async function removeGrant(userId, serverId) {
        if (!confirm(`确认取消用户 #${userId} 对节点 #${serverId} 的授权？`)) return;
        try {
            const data = await api('/revoke', 'POST', { user_id: userId, server_id: serverId });
            toast(data.message || '已取消', 'ok');
            loadGrants();
        } catch (e) { toast(e.message, 'err'); }
    }

    async function diagnose() {
        const uid = document.getElementById('diagUser').value;
        const sid = document.getElementById('diagServer').value;
        if (!uid || !sid) { toast('请输入用户ID和节点ID', 'err'); return; }
        const el = document.getElementById('diagResult');
        el.textContent = '诊断中...';
        el.style.display = 'block';
        try {
            const d = await api('/diagnose?user_id=' + uid + '&server_id=' + sid, 'GET');
            const u = d.user || {};
            const nowSec = Math.floor(Date.now() / 1000);
            const expired = u.expired_at && u.expired_at < nowSec;
            const overTraffic = u.transfer_enable && ((u.u||0)+(u.d||0)) >= u.transfer_enable;
            let out = '';
            out += '══ 节点 ══\n';
            out += `名称：${d.server_name} (ID ${sid})\n`;
            out += `group_ids：${(d.group_ids && d.group_ids.length) ? d.group_ids.join(',') : '【空】'}${d.group_ids_empty ? '  ← ⚠️ 空！getAvailableUsers 不触发同步Hook，必须给节点设权限组（可空组）' : ' ✅'}\n\n`;
            out += '══ 授权 ══\n';
            out += `授权记录：${d.grant_exists ? '✅ 存在' : '❌ 不存在（请先点上方授权）'}\n`;
            out += `该节点额外授权的用户ID：${d.extra_user_ids.length ? d.extra_user_ids.join(',') : '（无）'}\n\n`;
            out += '══ 同步 ══\n';
            out += `节点WS在线：${d.node_ws_online ? '✅ 是（grant后能即时推送）' : '❌ 否（WS未连，靠节点定时拉取等1-2分钟；或节点用HTTP/UniProxy模式）'}\n`;
            out += `getAvailableUsers 用户总数：${d.available_count}\n`;
            out += `目标用户 ${uid} 在该列表：${d.user_in_list ? '✅ 是（同步侧已注入，节点端应有此用户）' : '❌ 否（同步侧未注入！）'}\n\n`;
            out += '══ 用户状态（影响 sync_validity_check）══\n';
            out += `邮箱：${u.email || '-'}\n`;
            out += `封禁：${u.banned ? '⚠️是' : '否'}　过期时间戳：${u.expired_at || '(永久)'}${expired ? ' ⚠️已过期' : ''}　流量(u+d/上限)：${(u.u||0)+(u.d||0)} / ${u.transfer_enable||0}${overTraffic ? ' ⚠️超额' : ''}\n`;
            if (!d.user_in_list && d.grant_exists && !d.group_ids_empty) {
                if (u.banned) out += '\n→ 用户被封禁，sync_validity_check 拦截。请解封或关闭该配置项。\n';
                else if (expired) out += '\n→ 用户已过期，sync_validity_check 拦截。请延期。\n';
                else if (overTraffic) out += '\n→ 用户流量超额，sync_validity_check 拦截。请重置流量。\n';
                else out += '\n→ ⚠️ 授权存在、节点有组、用户有效，但仍未注入——插件Hook可能未注册或异常，查服务器 storage/logs/laravel.log 里 [ExtraNodeAccess] 开头的报错发我。\n';
            }
            el.textContent = out;
        } catch (e) {
            el.textContent = '诊断失败：' + e.message;
        }
    }

    function loadAll() { loadOptions(); loadGrants(); }

    // ---------- 初始化 ----------
    if (!getToken()) {
        rescanToken();   // 首次进入，自动扫描后台凭证
    }
    document.getElementById('token').value = getToken();
    loadAll();
</script>
</body>
</html>
