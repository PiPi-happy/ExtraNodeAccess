<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>额外节点授权管理</title>
    <!--
      安全说明：本页为公开静态壳，HTML 中不含 secure_path（防止向未登录访客泄露后台 API 前缀）。
      管理员首次使用时在「凭证」弹窗手动填写 secure_path（存本机 Local Storage），token 支持自动扫描。
    -->
    <style>
        /* 设计语言：Acme 风格指南（UI标准/acme-style-guide.html）——主色 #2563eb，Inter，
           中性灰阶 slate/gray，浅色卡底 eff6ff/ecfdf5/fefce8，卡片 12px 圆角细边框，微妙对比 */
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --border-strong: #d1d5db;
            --text: #1f2937;
            --text-strong: #0f172a;
            --text-2: #6b7280;
            --text-3: #94a3b8;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-soft: #eff6ff;
            --primary-border: #dbeafe;
            --ok: #16a34a;
            --ok-soft: #ecfdf5;
            --danger: #dc2626;
            --danger-soft: #fef2f2;
            --warn: #b45309;
            --warn-soft: #fefce8;
            --warn-border: #fde68a;
            --dark: #1e293b;
            --radius: 12px;
            --shadow: 0 1px 3px rgba(0, 0, 0, .06);
            --shadow-lg: 0 20px 60px rgba(0, 0, 0, .15);
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            margin: 0; background: var(--bg); color: var(--text); font-size: 15px; line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        button { font-family: inherit; }
        input, select {
            font-family: inherit; font-size: 14px; color: var(--text);
            padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; outline: none;
            background: var(--card); transition: border-color .15s, box-shadow .15s;
        }
        input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, .14); }
        input::placeholder { color: var(--text-3); }
        .btn {
            display: inline-flex; align-items: center; gap: 6px; justify-content: center;
            padding: 8px 18px; border: 1px solid transparent; border-radius: 8px;
            font-size: 14px; font-weight: 500; cursor: pointer; background: var(--primary); color: #fff;
            transition: background .15s, border-color .15s, opacity .15s; white-space: nowrap;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .btn.ghost { background: var(--card); color: #374151; border-color: var(--border-strong); }
        .btn.ghost:hover { background: var(--bg); }
        .btn.danger { background: var(--card); color: var(--danger); border-color: var(--border-strong); }
        .btn.danger:hover { background: var(--danger-soft); border-color: #fecaca; }
        .btn.sm { padding: 5px 13px; font-size: 13px; border-radius: 7px; }
        .btn.link { background: none; border: none; color: var(--primary); padding: 4px 6px; cursor: pointer; font-size: 13px; }
        .btn.link:hover { text-decoration: underline; }
        code { background: #f1f5f9; padding: 1px 5px; border-radius: 5px; font-size: 12.5px; font-family: 'SF Mono', ui-monospace, Menlo, Consolas, monospace; color: #7c3aed; }

        /* ===== 顶栏 ===== */
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: rgba(255, 255, 255, .8); backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
        }
        .topbar-inner { max-width: 1080px; margin: 0 auto; padding: 12px 24px; display: flex; align-items: center; gap: 12px; }
        .topbar h1 { font-size: 17px; font-weight: 600; color: var(--text-strong); letter-spacing: -0.01em; margin: 0; }
        .topbar .sub { font-size: 12px; color: var(--text-2); margin-top: 1px; }
        .topbar .spacer { flex: 1; }
        .badge {
            display: inline-flex; align-items: center; gap: 5px; font-size: 12px;
            padding: 4px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--card); color: var(--text-2);
        }
        .badge .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--text-3); }
        .badge.ok .dot { background: var(--ok); }
        .badge.bad .dot { background: var(--danger); }
        .badge.clickable { cursor: pointer; }
        .badge.clickable:hover { border-color: var(--border-strong); }

        main { max-width: 1080px; margin: 0 auto; padding: 20px 24px 80px; }

        /* ===== 统计条 ===== */
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .stat {
            background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 14px 16px; box-shadow: var(--shadow);
        }
        .stat .num { font-size: 26px; font-weight: 700; color: var(--text-strong); letter-spacing: -0.02em; }
        .stat .lbl { font-size: 12px; color: var(--text-2); margin-top: 2px; }

        /* ===== Tab + 搜索 ===== */
        .list-head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
        .tabs { display: inline-flex; background: #eef1f6; border-radius: 9px; padding: 3px; }
        .tab {
            border: none; background: none; padding: 6px 16px; border-radius: 7px;
            font-size: 13.5px; color: var(--text-2); cursor: pointer; transition: all .15s;
        }
        .tab.active { background: var(--card); color: var(--text); font-weight: 600; box-shadow: var(--shadow); }
        .search { flex: 1; min-width: 200px; max-width: 360px; margin-left: auto; position: relative; }
        .search input { width: 100%; padding-left: 32px; }
        .search .icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-3); pointer-events: none; display: flex; }
        .icon-svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }
        .empty-svg { width: 34px; height: 34px; fill: none; stroke: var(--text-3); stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; margin-bottom: 8px; }
        .btn .icon-svg { width: 15px; height: 15px; }

        /* ===== 列表卡片 ===== */
        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
            box-shadow: var(--shadow); padding: 14px 16px; margin-bottom: 10px;
        }
        .card .row1 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .card .title { font-weight: 600; color: var(--text-strong); font-size: 14.5px; }
        .card .meta { font-size: 12px; color: var(--text-2); }
        .card .grow { flex: 1; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .chip {
            display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px;
            padding: 3px 10px; border-radius: 999px; background: var(--primary-soft); color: var(--primary);
            border: 1px solid var(--primary-border); max-width: 100%;
        }
        .chip.hidden-node { background: var(--warn-soft); color: var(--warn); border-color: var(--warn-border); }
        .chip.dead { background: var(--bg); color: var(--text-3); border-color: var(--border); text-decoration: line-through; }
        .chip.more { background: var(--bg); color: var(--text-2); border-color: var(--border); }
        .chip .x { cursor: pointer; font-weight: 700; opacity: .6; }
        .chip .x:hover { opacity: 1; color: var(--danger); }
        .remark-line { font-size: 12.5px; color: var(--text-2); margin-top: 8px; }
        .empty { text-align: center; color: var(--text-3); padding: 48px 0; }
        .empty .big { font-size: 30px; margin-bottom: 8px; }

        /* ===== 分页 ===== */
        .pager { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; }
        .pager .info { font-size: 12.5px; color: var(--text-2); }

        /* ===== 抽屉 ===== */
        .drawer-mask {
            position: fixed; inset: 0; background: rgba(15, 23, 42, .32); z-index: 100;
            opacity: 0; pointer-events: none; transition: opacity .2s;
        }
        .drawer-mask.open { opacity: 1; pointer-events: auto; }
        .drawer {
            position: fixed; top: 0; right: -560px; width: 540px; max-width: 96vw; height: 100vh; z-index: 101;
            background: var(--bg); box-shadow: var(--shadow-lg); transition: right .22s ease;
            display: flex; flex-direction: column;
        }
        .drawer.open { right: 0; }
        .drawer-head { padding: 16px 20px; background: var(--card); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .drawer-head .title { font-weight: 600; font-size: 15px; }
        .drawer-body { flex: 1; overflow-y: auto; padding: 16px 20px; }
        .drawer-sec { margin-bottom: 18px; }
        .drawer-sec h3 { font-size: 13px; color: var(--text-2); margin: 0 0 8px; font-weight: 600; }
        .grant-line {
            display: flex; align-items: center; gap: 8px; background: var(--card);
            border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin-bottom: 6px; font-size: 13px;
        }
        .grant-line .grow { flex: 1; min-width: 0; }
        .grant-line .sub { font-size: 11.5px; color: var(--text-3); }
        .pool-toolbar { display: flex; gap: 8px; margin-bottom: 10px; }
        .pool-toolbar input { flex: 1; }
        .pool-list { max-height: 340px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; background: var(--card); }
        .pool-item {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            border-bottom: 1px solid var(--border); font-size: 13.5px; cursor: pointer; user-select: none;
        }
        .pool-item:last-child { border-bottom: none; }
        .pool-item:hover { background: var(--bg); }
        .pool-item.disabled { opacity: .5; cursor: not-allowed; }
        .pool-item input[type=checkbox] { accent-color: var(--primary); width: 15px; height: 15px; cursor: pointer; }
        .pool-item .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
        .tag.hidden { background: var(--warn-soft); color: var(--warn); }
        .tag.shown { background: var(--ok-soft); color: var(--ok); }
        .pool-foot { display: flex; gap: 8px; margin-top: 10px; align-items: center; }
        .pool-foot input { flex: 1; }

        /* ===== 弹窗 ===== */
        .modal-mask {
            position: fixed; inset: 0; background: rgba(15, 23, 42, .4); z-index: 200;
            display: none; align-items: flex-start; justify-content: center; padding: 8vh 16px 16px;
        }
        .modal-mask.open { display: flex; }
        .modal {
            background: var(--card); border-radius: 16px; box-shadow: var(--shadow-lg);
            width: 520px; max-width: 100%; max-height: 82vh; display: flex; flex-direction: column;
            animation: pop .18s ease;
        }
        @keyframes pop { from { transform: scale(.97) translateY(-6px); opacity: 0; } }
        .modal-head { padding: 16px 20px 0; display: flex; align-items: center; }
        .modal-head h3 { margin: 0; font-size: 15.5px; }
        .modal-head .close { margin-left: auto; border: none; background: none; font-size: 18px; color: var(--text-3); cursor: pointer; padding: 4px 8px; }
        .modal-head .close:hover { color: var(--text); }
        .modal-body { padding: 14px 20px 20px; overflow-y: auto; }
        .field { margin-bottom: 12px; }
        .field label { display: block; font-size: 12.5px; color: var(--text-2); margin-bottom: 5px; }
        .field input, .field textarea { width: 100%; }
        .hint { font-size: 12px; color: var(--text-2); line-height: 1.7; }
        .modal pre {
            background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
            padding: 12px; font-size: 12px; line-height: 1.7; white-space: pre-wrap; word-break: break-all;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; max-height: 46vh; overflow-y: auto;
        }
        .errbox { background: var(--danger-soft); border: 1px solid #fecaca; color: #b91c1c;
                  border-radius: 8px; padding: 10px 12px; font-size: 12.5px; line-height: 1.7; display: none; word-break: break-all; }
        .okbox { color: var(--ok); font-size: 12.5px; display: none; }

        /* ===== toast ===== */
        #toast {
            position: fixed; top: 18px; left: 50%; transform: translateX(-50%);
            background: var(--dark); color: #fff; font-size: 13.5px;
            padding: 10px 18px; border-radius: 9px; box-shadow: var(--shadow-lg);
            opacity: 0; transition: opacity .25s; z-index: 999; max-width: 88vw;
        }
        #toast.show { opacity: 1; }
        #toast.err { background: var(--danger); }

        @media (max-width: 720px) {
            main { padding: 14px 12px 80px; }
            .topbar-inner { padding: 10px 12px; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .search { max-width: none; width: 100%; margin-left: 0; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <div>
            <h1>额外节点授权</h1>
            <div class="sub">给单个用户在套餐之外额外开放节点（含隐藏节点），无需新建套餐</div>
        </div>
        <div class="spacer"></div>
        <span class="badge clickable" id="credBadge" onclick="openCredModal()" title="点击管理凭证">
            <span class="dot"></span><span id="credText">未配置凭证</span>
        </span>
        <button class="btn ghost sm" onclick="openDiagModal()">
            <svg class="icon-svg" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            同步诊断
        </button>
    </div>
</header>

<main>
    <!-- 统计条 -->
    <section class="stats" id="stats">
        <div class="stat"><div class="num" id="stTotal">–</div><div class="lbl">总授权数</div></div>
        <div class="stat"><div class="num" id="stUsers">–</div><div class="lbl">涉及用户</div></div>
        <div class="stat"><div class="num" id="stServers">–</div><div class="lbl">涉及节点</div></div>
        <div class="stat"><div class="num" id="stHidden">–</div><div class="lbl">隐藏节点授权</div></div>
    </section>

    <!-- Tab + 搜索 -->
    <div class="list-head">
        <div class="tabs">
            <button class="tab active" id="tabUser" onclick="switchTab('user')">按用户</button>
            <button class="tab" id="tabServer" onclick="switchTab('server')">按节点</button>
        </div>
        <div class="search">
            <span class="icon"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
            <input id="searchInput" placeholder="搜索邮箱 / 节点名 / 备注…" oninput="onSearchInput()">
        </div>
    </div>

    <!-- 列表 -->
    <section id="listView"><div class="empty">加载中…</div></section>
    <div class="pager" id="pager" style="display:none;">
        <button class="btn ghost sm" id="pgPrev" onclick="goPage(-1)">‹ 上一页</button>
        <span class="info" id="pgInfo"></span>
        <button class="btn ghost sm" id="pgNext" onclick="goPage(1)">下一页 ›</button>
    </div>
</main>

<!-- 抽屉 -->
<div class="drawer-mask" id="drawerMask" onclick="closeDrawer()"></div>
<aside class="drawer" id="drawer">
    <div class="drawer-head">
        <div class="title" id="drawerTitle">管理</div>
        <div class="spacer" style="flex:1"></div>
        <button class="btn ghost sm" onclick="closeDrawer()">关闭</button>
    </div>
    <div class="drawer-body" id="drawerBody"></div>
</aside>

<!-- 凭证弹窗 -->
<div class="modal-mask" id="credModal">
    <div class="modal">
        <div class="modal-head"><h3>管理员凭证</h3><button class="close" onclick="closeModal('credModal')">✕</button></div>
        <div class="modal-body">
            <div class="field">
                <label>Secure Path（后台保密路径，仅存本机）</label>
                <input type="text" id="securePathInput" placeholder="即登录后台地址里域名后那一段，如 https://x.com/staff 的 staff">
                <div class="hint" style="margin-top:5px;">
                    本页为公开页面、<b>不会</b>自动携带后台路径（防止泄露）。此处只需填写一次，保存在浏览器 Local Storage。
                    与后台「管理路径 / secure_path」设置一致；自定义过后台地址就填自定义的那段。
                </div>
            </div>
            <div class="field">
                <label>Admin Token（Bearer）</label>
                <input type="text" id="tokenInput" placeholder="自动扫描后台登录凭证；为空时手动粘贴（不含 Bearer 前缀）">
                <div class="hint" style="margin-top:5px;">同域名下已登录过后台，打开本页会自动扫描 Local Storage 读取。</div>
            </div>
            <div class="errbox" id="credErr"></div>
            <div class="okbox" id="credOk"></div>
            <div style="display:flex; gap:8px; margin-top:12px;">
                <button class="btn" id="credSaveBtn" onclick="saveCred()">保存并加载</button>
                <button class="btn ghost" onclick="rescanToken()">重新扫描 token</button>
            </div>
        </div>
    </div>
</div>

<!-- 诊断弹窗 -->
<div class="modal-mask" id="diagModal">
    <div class="modal">
        <div class="modal-head"><h3>同步诊断（排查"授权后连不上"）</h3><button class="close" onclick="closeModal('diagModal')">✕</button></div>
        <div class="modal-body">
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <div class="field" style="flex:1; margin:0;">
                    <label>用户 ID</label>
                    <input type="number" id="diagUser" placeholder="如 88">
                </div>
                <div class="field" style="flex:1; margin:0;">
                    <label>节点 ID</label>
                    <input type="number" id="diagServer" placeholder="如 12">
                </div>
                <button class="btn" id="diagBtn" onclick="diagnose()" style="align-self:flex-end;">诊断</button>
            </div>
            <pre id="diagResult" style="display:none;"></pre>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
    // ================= 凭证 =================
    const LS_PATH = 'ena_secure_path', LS_TOKEN = 'ena_admin_token';
    const getPath = () => (localStorage.getItem(LS_PATH) || '').trim().replace(/^\/+|\/+$/g, '');
    const getToken = () => (localStorage.getItem(LS_TOKEN) || '').trim();
    const setPath = v => v ? localStorage.setItem(LS_PATH, v.trim()) : localStorage.removeItem(LS_PATH);
    const setToken = v => v ? localStorage.setItem(LS_TOKEN, v.trim()) : localStorage.removeItem(LS_TOKEN);
    const API_BASE = () => '/api/v2/' + encodeURIComponent(getPath()) + '/extra-node';

    function updateCredBadge(ok) {
        const b = document.getElementById('credBadge');
        document.getElementById('credText').textContent = ok ? '凭证已就绪' : '未配置凭证';
        b.classList.toggle('ok', !!ok);
        b.classList.toggle('bad', !ok);
    }

    // 自动扫描 Local Storage，找后台存的 "Bearer xxx" 凭证
    function rescanToken() {
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            const v = (localStorage.getItem(k) || '').trim();
            if (/^bearer\s+/i.test(v)) {
                const pure = v.replace(/^bearer\s+/i, '').trim();
                if (pure.length >= 20) {
                    setToken(pure);
                    document.getElementById('tokenInput').value = pure;
                    showCredOk('✅ 已自动读取后台凭证（Local Storage key: ' + k + '）');
                    return true;
                }
            }
        }
        showCredOk('未能自动扫描到 token，请手动粘贴。');
        return false;
    }

    function showCredOk(msg) { const el = document.getElementById('credOk'); el.textContent = msg; el.style.display = 'block'; document.getElementById('credErr').style.display = 'none'; }
    function showCredErr(html) { const el = document.getElementById('credErr'); el.innerHTML = html; el.style.display = 'block'; document.getElementById('credOk').style.display = 'none'; }

    function openCredModal() {
        document.getElementById('securePathInput').value = getPath();
        document.getElementById('tokenInput').value = getToken();
        document.getElementById('credErr').style.display = 'none';
        document.getElementById('credOk').style.display = 'none';
        openModal('credModal');
    }

    function saveCred() {
        const p = document.getElementById('securePathInput').value.trim().replace(/^\/+|\/+$/g, '');
        const t = document.getElementById('tokenInput').value.trim();
        if (!p) { showCredErr('请填写 Secure Path（后台保密路径）。'); return; }
        if (!t) { showCredErr('请填写 Admin Token（打开本页时会先自动扫描一次）。'); return; }
        setPath(p); setToken(t);
        closeModal('credModal');
        updateCredBadge(true);
        loadAll();
    }

    // ================= 通用 UI =================
    function toast(msg, type = 'ok') {
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.className = 'show' + (type === 'err' ? ' err' : '');
        clearTimeout(el._t);
        el._t = setTimeout(() => el.className = '', 2600);
    }
    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // 请求期间禁用按钮，防重复点击
    let busy = false;
    async function withBtn(btn, fn) {
        if (busy) return;
        busy = true;
        if (btn) { btn.disabled = true; btn._old = btn.textContent; btn.textContent = '处理中…'; }
        try { await fn(); } finally {
            busy = false;
            if (btn) { btn.disabled = false; btn.textContent = btn._old; }
        }
    }

    // ================= API =================
    async function api(path, method = 'GET', body = null) {
        const headers = {};
        const t = getToken();
        if (t) headers['Authorization'] = 'Bearer ' + t;
        const opts = { method, headers };
        if (body) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        let res;
        try {
            res = await fetch(API_BASE() + path, opts);
        } catch (e) {
            showCredErr('网络错误：' + esc(e.message) + '。请检查 secure_path 与域名是否正确。');
            throw new Error('network');
        }
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (e) {
            showCredErr('HTTP ' + res.status + '，响应非 JSON。多为 secure_path 错误（跳到了登录页/404）。<br>响应前 120 字：' + esc(text.slice(0, 120)));
            openCredModal();
            throw new Error('bad-json');
        }
        if (res.status === 401 || res.status === 403) {
            showCredErr('鉴权失败（' + res.status + '）。token 无效/未启用，或 secure_path 不一致。<br>后端返回：' + esc(json.message || '(空)'));
            openCredModal();
            throw new Error('unauthorized');
        }
        if (json.status !== 'success') {
            toast(json.message || '操作失败', 'err');
            throw new Error(json.message || 'fail');
        }
        return json.data;
    }

    // ================= 列表状态 =================
    const state = { tab: 'user', keyword: '', page: 1, pageSize: 20, total: 0 };
    let searchTimer = null;
    let drawerCtx = null;          // {type:'user',userId,email} | {type:'server',serverId,name}
    let drawerPool = [];           // 节点池 / 用户池
    let drawerSelected = new Set();
    let drawerPoolKeyword = '';

    function switchTab(tab) {
        state.tab = tab; state.page = 1;
        document.getElementById('tabUser').classList.toggle('active', tab === 'user');
        document.getElementById('tabServer').classList.toggle('active', tab === 'server');
        document.getElementById('searchInput').placeholder = tab === 'user' ? '搜索邮箱 / 备注…' : '搜索节点名 / host…';
        loadList();
    }

    function onSearchInput() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.keyword = document.getElementById('searchInput').value.trim();
            state.page = 1;
            loadList();
        }, 300);
    }

    function goPage(delta) {
        const maxPage = Math.max(1, Math.ceil(state.total / state.pageSize));
        const next = state.page + delta;
        if (next < 1 || next > maxPage) return;
        state.page = next;
        loadList();
    }

    // ================= 列表加载/渲染 =================
    async function loadStats() {
        try {
            const s = await api('/stats');
            document.getElementById('stTotal').textContent = s.total;
            document.getElementById('stUsers').textContent = s.user_count;
            document.getElementById('stServers').textContent = s.server_count;
            document.getElementById('stHidden').textContent = s.hidden_count;
        } catch (e) { /* 错误已由 api() 提示 */ }
    }

    async function loadList() {
        const view = document.getElementById('listView');
        const pager = document.getElementById('pager');
        view.innerHTML = '<div class="empty">加载中…</div>';
        try {
            if (state.tab === 'user') await loadByUser(view, pager);
            else await loadByServer(view, pager);
        } catch (e) {
            view.innerHTML = '<div class="empty"><svg class="empty-svg" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg><br>加载失败，请检查右上角凭证</div>';
        }
    }

    async function loadByUser(view, pager) {
        const kw = state.keyword ? '&keyword=' + encodeURIComponent(state.keyword) : '';
        const d = await api(`/by-user?page=${state.page}&page_size=${state.pageSize}${kw}`);
        state.total = d.total;
        if (!d.data.length) {
            view.innerHTML = '<div class="empty"><svg class="empty-svg" viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg><br>' +
                (state.keyword ? '没有匹配的用户' : '暂无授权记录<br><span style="font-size:12.5px">点任意用户行的「管理」开始授权</span>') + '</div>';
            renderPager(pager, d);
            return;
        }
        const MAX_CHIPS = 6;
        view.innerHTML = d.data.map(u => {
            const chips = u.grants.slice(0, MAX_CHIPS).map(g => {
                const name = g.server_name || '已删除#' + g.server_id;
                const cls = g.server_name ? (g.server_show ? '' : ' hidden-node') : ' dead';
                const title = g.server_name ? esc(name) + (g.server_show ? '' : '（隐藏节点）') : '节点已删除';
                return `<span class="chip${cls}" title="${title}">${esc(name)}</span>`;
            }).join('');
            const more = u.grants.length > MAX_CHIPS ? `<span class="chip more">+${u.grants.length - MAX_CHIPS} 更多</span>` : '';
            const remark = [...new Set(u.grants.map(g => g.remark).filter(Boolean))].slice(0, 2).join('；');
            const latest = u.grants.reduce((a, g) => (a && a > (g.created_at || '') ? a : g.created_at), '') || '';
            return `<div class="card">
                <div class="row1">
                    <span class="title">${esc(u.email) || '<span style="color:var(--text-3)">已删除用户</span>'}</span>
                    <span class="meta">#${u.user_id}</span>
                    <span class="badge">${u.grant_count} 个节点</span>
                    <span class="grow"></span>
                    <span class="meta">${esc(latest) ? '最近 ' + esc(latest.slice(0, 10)) : ''}</span>
                    <button class="btn ghost sm" onclick="openUserDrawerFromBtn(this, ${u.user_id})">管理</button>
                </div>
                <div class="chips">${chips}${more}</div>
                ${remark ? `<div class="remark-line">备注：${esc(remark)}</div>` : ''}
            </div>`;
        }).join('');
        renderPager(pager, d);
    }

    async function loadByServer(view, pager) {
        const d = await api('/by-server');
        state.total = d.length;
        pager.style.display = 'none';
        const list = d.filter(s => state.keyword
            ? (s.name || '').toLowerCase().includes(state.keyword.toLowerCase()) || (s.host || '').toLowerCase().includes(state.keyword.toLowerCase())
            : true);
        if (!list.length) {
            view.innerHTML = '<div class="empty"><svg class="empty-svg" viewBox="0 0 24 24"><rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg><br>' + (state.keyword ? '没有匹配的节点' : '暂无节点') + '</div>';
            return;
        }
        const MAX_CHIPS = 8;
        view.innerHTML = list.map(s => {
            const chips = s.users.slice(0, MAX_CHIPS).map(u =>
                `<span class="chip" title="${esc(u.user_email || ('#' + u.user_id))}">${esc(u.user_email) || '#' + u.user_id}</span>`).join('');
            const more = s.users.length > MAX_CHIPS ? `<span class="chip more">+${s.users.length - MAX_CHIPS}</span>` : '';
            return `<div class="card">
                <div class="row1">
                    <span class="title">${esc(s.name)}</span>
                    <span class="meta">#${s.id}</span>
                    <span class="tag ${s.show ? 'shown' : 'hidden'}">${s.show ? '显示' : '隐藏'}</span>
                    ${s.host ? `<span class="meta">${esc(s.host)}</span>` : ''}
                    <span class="grow"></span>
                    <span class="badge">${s.grant_count} 个用户</span>
                    <button class="btn ghost sm" onclick="openServerDrawerFromBtn(this, ${s.id})">管理</button>
                </div>
                ${s.users.length ? `<div class="chips">${chips}${more}</div>` : '<div class="remark-line">暂无额外授权用户</div>'}
            </div>`;
        }).join('');
    }

    function renderPager(pager, d) {
        const maxPage = Math.max(1, Math.ceil(d.total / d.page_size));
        if (d.total <= d.page_size) { pager.style.display = 'none'; return; }
        pager.style.display = 'flex';
        document.getElementById('pgInfo').textContent = `第 ${d.page} / ${maxPage} 页 · 共 ${d.total} 条`;
        document.getElementById('pgPrev').disabled = d.page <= 1;
        document.getElementById('pgNext').disabled = d.page >= maxPage;
    }

    // ================= 抽屉 =================
    function openUserDrawerFromBtn(btn, userId) {
        // 从同行卡片标题取邮箱（textContent，已解码的纯文本，无注入风险）
        const card = btn.closest('.card');
        const title = card ? (card.querySelector('.title') || {}).textContent || '' : '';
        openUserDrawer(userId, title.trim());
    }

    function openUserDrawer(userId, email) {
        drawerCtx = { type: 'user', userId, email };
        drawerSelected = new Set();
        drawerPoolKeyword = '';
        document.getElementById('drawerTitle').textContent = '用户：' + (email || '#' + userId);
        openDrawer();
        renderDrawer();
    }

    function openServerDrawerFromBtn(btn, serverId) {
        const card = btn.closest('.card');
        const title = card ? (card.querySelector('.title') || {}).textContent || '' : '';
        openServerDrawer(serverId, title.trim());
    }

    function openServerDrawer(serverId, name) {
        drawerCtx = { type: 'server', serverId, name };
        drawerSelected = new Set();
        drawerPoolKeyword = '';
        document.getElementById('drawerTitle').textContent = '节点：' + (name || '#' + serverId);
        openDrawer();
        renderDrawer();
    }

    function openDrawer() {
        document.getElementById('drawer').classList.add('open');
        document.getElementById('drawerMask').classList.add('open');
    }
    function closeDrawer() {
        document.getElementById('drawer').classList.remove('open');
        document.getElementById('drawerMask').classList.remove('open');
        drawerCtx = null;
    }

    function renderDrawer() {
        const body = document.getElementById('drawerBody');
        if (!drawerCtx) { body.innerHTML = ''; return; }
        const isUser = drawerCtx.type === 'user';
        body.innerHTML = `
            <div class="drawer-sec" id="secExisting"><h3>${isUser ? '已授权节点' : '已授权用户'}</h3><div class="empty" style="padding:20px 0">加载中…</div></div>
            <div class="drawer-sec">
                <h3>${isUser ? '节点池（勾选后批量授权）' : '用户池（勾选后批量授权，输入邮箱搜索）'}</h3>
                <div class="pool-toolbar">
                    <input id="poolSearch" placeholder="${isUser ? '搜索节点名…' : '输入邮箱关键词，回车搜索…'}" value="${esc(drawerPoolKeyword)}">
                    ${isUser ? '' : '<button class="btn ghost sm" onclick="searchPool()">搜索</button>'}
                </div>
                <div class="pool-list" id="poolList"><div class="empty" style="padding:20px 0">加载中…</div></div>
                <div class="pool-foot">
                    <input id="poolRemark" placeholder="备注（可选，应用到本次批量授权）">
                    <button class="btn sm" id="poolGrantBtn" onclick="batchGrant()">授权选中 <span id="selCount"></span></button>
                </div>
            </div>`;
        document.getElementById('poolSearch').addEventListener(isUser ? 'input' : 'keydown', e => {
            if (isUser) {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => { drawerPoolKeyword = e.target.value.trim(); loadPool(); }, 300);
            } else if (e.key === 'Enter') {
                drawerPoolKeyword = e.target.value.trim(); loadPool();
            }
        });
        loadExisting();
        loadPool();
    }

    async function loadExisting() {
        const sec = document.getElementById('secExisting');
        try {
            if (drawerCtx.type === 'user') {
                // 精确按 user_id 查该用户的全部授权（平铺接口）
                const list = await api('/grants?user_id=' + drawerCtx.userId);
                renderExisting(list.map(g => ({
                    key: g.server_id,
                    main: g.server_name || '已删除#' + g.server_id,
                    dead: !g.server_name,
                    hidden: g.server_name && !g.server_show,
                    sub: g.remark ? '备注：' + g.remark : '',
                    revoke: `revoke(${g.user_id}, ${g.server_id})`
                })), false);
            } else {
                const list = await api('/grants?server_id=' + drawerCtx.serverId);
                renderExisting(list.map(g => ({
                    key: g.user_id,
                    main: g.user_email || '#' + g.user_id + '（已删除）',
                    hidden: false,
                    sub: g.remark ? '备注：' + g.remark : '',
                    revoke: `revoke(${g.user_id}, ${g.server_id})`
                })), true);
            }
        } catch (e) {
            sec.innerHTML = '<h3>加载失败</h3>';
        }
    }

    function renderExisting(items, isUserSide) {
        const sec = document.getElementById('secExisting');
        if (!items.length) {
            sec.innerHTML = `<h3>${isUserSide ? '已授权用户' : '已授权节点'}</h3><div class="empty" style="padding:16px 0">暂无授权，从下方${isUserSide ? '用户池' : '节点池'}勾选添加</div>`;
            return;
        }
        sec.innerHTML = `<h3>${isUserSide ? '已授权用户（' + items.length + '）' : '已授权节点（' + items.length + '）'}</h3>` +
            items.map(it => `
                <div class="grant-line">
                    <div class="grow">
                        <div>${esc(it.main)} ${it.hidden ? '<span class="tag hidden">隐藏</span>' : ''}</div>
                        ${it.sub ? `<div class="sub">${esc(it.sub)}</div>` : ''}
                    </div>
                    <button class="btn link" style="color:var(--text-2)" onclick="openDiagWith(${drawerCtx.type === 'user' ? drawerCtx.userId : it.key}, ${drawerCtx.type === 'user' ? it.key : drawerCtx.serverId})">诊断</button>
                    <button class="btn danger sm" onclick="${it.revoke}">撤销</button>
                </div>`).join('');
    }

    async function loadPool() {
        const el = document.getElementById('poolList');
        if (!el) return;
        el.innerHTML = '<div class="empty" style="padding:20px 0">加载中…</div>';
        try {
            if (drawerCtx.type === 'user') {
                // 节点池：全量 + authorized 标记，前端按关键词过滤
                drawerPool = await api('/servers?user_id=' + drawerCtx.userId);
            } else {
                const kw = drawerPoolKeyword ? '&keyword=' + encodeURIComponent(drawerPoolKeyword) : '';
                drawerPool = await api('/users?server_id=' + drawerCtx.serverId + kw);
            }
            renderPool();
        } catch (e) {
            el.innerHTML = '<div class="empty" style="padding:20px 0">加载失败</div>';
        }
    }
    function searchPool() {
        drawerPoolKeyword = document.getElementById('poolSearch').value.trim();
        loadPool();
    }

    function renderPool() {
        const el = document.getElementById('poolList');
        if (!el) return;
        if (!drawerPool.length) {
            el.innerHTML = '<div class="empty" style="padding:20px 0">' + (drawerPoolKeyword ? '没有匹配结果' : '暂无可选项') + '</div>';
            return;
        }
        const isUser = drawerCtx.type === 'user';
        let list = drawerPool;
        if (isUser && drawerPoolKeyword) {
            const kw = drawerPoolKeyword.toLowerCase();
            list = drawerPool.filter(s => (s.name || '').toLowerCase().includes(kw) || (s.host || '').toLowerCase().includes(kw));
        }
        if (!list.length) { el.innerHTML = '<div class="empty" style="padding:20px 0">没有匹配结果</div>'; return; }
        el.innerHTML = list.map(it => {
            const authed = it.authorized;
            const label = isUser ? `${esc(it.name)} <span class="meta">#${it.id}</span> ${it.show ? '' : '<span class="tag hidden">隐藏</span>'}`
                                 : `${esc(it.email) || '#' + it.id}`;
            return `<label class="pool-item ${authed ? 'disabled' : ''}">
                <input type="checkbox" value="${it.id}" ${authed ? 'disabled checked' : ''} onchange="onPoolCheck(this)">
                <span class="grow">${label}</span>
                ${isUser && it.grant_count ? `<span class="meta">${it.grant_count} 人</span>` : ''}
                ${authed ? '<span class="meta">已授权</span>' : ''}
            </label>`;
        }).join('');
        updateSelCount();
    }

    function onPoolCheck(box) {
        if (box.checked) drawerSelected.add(+box.value);
        else drawerSelected.delete(+box.value);
        updateSelCount();
    }
    function updateSelCount() {
        const el = document.getElementById('selCount');
        if (el) el.textContent = drawerSelected.size ? `(${drawerSelected.size})` : '';
    }

    async function batchGrant(btn) {
        if (!drawerSelected.size) { toast('请先勾选要授权的对象', 'err'); return; }
        const remark = (document.getElementById('poolRemark').value || '').trim();
        const body = { remark };
        if (drawerCtx.type === 'user') { body.user_id = drawerCtx.userId; body.server_ids = [...drawerSelected]; }
        else { body.server_id = drawerCtx.serverId; body.user_ids = [...drawerSelected]; }
        await withBtn(document.getElementById('poolGrantBtn'), async () => {
            try {
                const d = await api('/grant', 'POST', body);
                toast(d.message || '授权成功');
                drawerSelected = new Set();
                await Promise.all([loadExisting(), loadPool()]);
                loadStats(); loadList();
            } catch (e) { /* api() 已提示 */ }
        });
    }

    async function revoke(userId, serverId) {
        if (!confirm(`确认取消 用户#${userId} ↔ 节点#${serverId} 的授权？`)) return;
        try {
            const d = await api('/revoke', 'POST', { user_id: userId, server_id: serverId });
            toast(d.message || '已取消');
            await Promise.all([loadExisting(), loadPool()]);
            loadStats(); loadList();
        } catch (e) { /* api() 已提示 */ }
    }

    // ================= 诊断 =================
    function openDiagModal() {
        document.getElementById('diagResult').style.display = 'none';
        openModal('diagModal');
    }
    function openDiagWith(userId, serverId) {
        document.getElementById('diagUser').value = userId;
        document.getElementById('diagServer').value = serverId;
        document.getElementById('diagResult').style.display = 'none';
        openModal('diagModal');
        diagnose();
    }

    async function diagnose() {
        const uid = document.getElementById('diagUser').value;
        const sid = document.getElementById('diagServer').value;
        if (!uid || !sid) { toast('请输入用户ID和节点ID', 'err'); return; }
        const el = document.getElementById('diagResult');
        el.style.display = 'block';
        el.textContent = '诊断中…';
        await withBtn(document.getElementById('diagBtn'), async () => {
            try {
                const d = await api('/diagnose?user_id=' + uid + '&server_id=' + sid, 'GET');
                const u = d.user || {};
                const nowSec = Math.floor(Date.now() / 1000);
                const expired = u.expired_at && u.expired_at < nowSec;
                const overTraffic = u.transfer_enable && ((u.u || 0) + (u.d || 0)) >= u.transfer_enable;
                let out = '';
                out += '══ 节点 ══\n';
                out += `名称：${d.server_name} (ID ${sid})\n`;
                out += `group_ids：${(d.group_ids && d.group_ids.length) ? d.group_ids.join(',') : '【空】'}${d.group_ids_empty ? '  ← ⚠️ 空！getAvailableUsers 不触发同步Hook，必须给节点设权限组（可空组）' : ' ✅'}\n\n`;
                out += '══ 授权 ══\n';
                out += `授权记录：${d.grant_exists ? '✅ 存在' : '❌ 不存在（请先授权）'}\n`;
                out += `该节点额外授权的用户ID：${d.extra_user_ids.length ? d.extra_user_ids.join(',') : '（无）'}\n\n`;
                out += '══ 同步 ══\n';
                out += `节点WS在线：${d.node_ws_online ? '✅ 是（grant后能即时推送）' : '❌ 否（WS未连，靠节点定时拉取等1-2分钟；或节点用HTTP/UniProxy模式）'}\n`;
                out += `getAvailableUsers 用户总数：${d.available_count}\n`;
                out += `目标用户 ${uid} 在该列表：${d.user_in_list ? '✅ 是（同步侧已注入）' : '❌ 否（同步侧未注入！）'}\n\n`;
                out += '══ 用户状态（影响 sync_validity_check）══\n';
                out += `邮箱：${u.email || '-'}\n`;
                out += `封禁：${u.banned ? '⚠️是' : '否'}　过期时间戳：${u.expired_at || '(永久)'}${expired ? ' ⚠️已过期' : ''}　流量(u+d/上限)：${(u.u || 0) + (u.d || 0)} / ${u.transfer_enable || 0}${overTraffic ? ' ⚠️超额' : ''}\n`;
                if (!d.user_in_list && d.grant_exists && !d.group_ids_empty) {
                    if (u.banned) out += '\n→ 用户被封禁，sync_validity_check 拦截。请解封或关闭该配置项。\n';
                    else if (expired) out += '\n→ 用户已过期，sync_validity_check 拦截。请延期。\n';
                    else if (overTraffic) out += '\n→ 用户流量超额，sync_validity_check 拦截。请重置流量。\n';
                    else out += '\n→ ⚠️ 授权存在、节点有组、用户有效，但仍未注入——查服务器 storage/logs/laravel.log 里 [ExtraNodeAccess] 开头的报错。\n';
                }
                el.textContent = out;
            } catch (e) {
                el.textContent = '诊断失败：' + e.message;
            }
        });
    }

    // ================= 初始化 =================
    function loadAll() { loadStats(); loadList(); }

    (function init() {
        const hasPath = !!getPath();
        updateCredBadge(hasPath && !!getToken());
        if (!getToken()) rescanToken();
        if (!hasPath) {
            openCredModal();
            showCredOk('首次使用：请填写后台 Secure Path（token 已自动扫描，若为空请手动粘贴）。');
        }
        loadAll();
    })();
</script>
</body>
</html>
