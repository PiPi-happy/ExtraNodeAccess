<?php

/**
 * 额外节点授权管理网页（独立页面，不在后台 SPA 菜单内）。
 *
 * 访问：https://你的域名/extra-node-access
 * 页面本身仅渲染 HTML 壳，所有数据操作走 /api/v2/{secure_path}/extra-node/*
 * 由 admin 中间件鉴权；未登录调接口会 403，页面提示需要凭证。
 */

use Illuminate\Support\Facades\Route;

Route::get('/extra-node-access', function () {
    $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
    return view('ExtraNodeAccess::manage', ['securePath' => $securePath]);
});
