<?php

/**
 * 额外节点授权管理网页（独立页面，不在后台 SPA 菜单内）。
 *
 * 访问：https://你的域名/extra-node-access
 * 页面仅渲染静态 HTML 壳，**不含 secure_path**（安全：本页无需登录即可打开，
 * 若把 /api/v2/{secure_path}/ 前缀渲染进 HTML，等于向任意访客泄露后台保密路径）。
 * secure_path 由管理员首次使用时在页面「凭证」弹窗手动填一次，存浏览器 Local Storage。
 * 所有数据操作走 /api/v2/{secure_path}/extra-node/*，由 admin 中间件鉴权。
 */

use Illuminate\Support\Facades\Route;

Route::get('/extra-node-access', function () {
    return view('ExtraNodeAccess::manage');
});
