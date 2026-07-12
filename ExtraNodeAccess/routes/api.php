<?php

/**
 * 额外节点授权管理 API 路由。
 *
 * PluginManager 以 api 中间件加载本文件（不带前缀），这里自行包一层
 * /api/v2/{secure_path}/extra-node 前缀 + admin/log 中间件，与核心 admin API 对齐，
 * 复用 sanctum 管理员鉴权。
 *
 * 控制器用全限定类名（use），因为 PluginManager 给本文件设的命名空间是
 * Plugin\ExtraNodeAccess\Controllers，而控制器实际在 Http\Controllers 下。
 */

use Illuminate\Support\Facades\Route;
use Plugin\ExtraNodeAccess\Http\Controllers\ExtraNodeAccessController;

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

Route::group([
    'prefix' => '/api/v2/' . $securePath . '/extra-node',
    'middleware' => ['admin', 'log'],
], function ($router) {
    $router->get('/grants', [ExtraNodeAccessController::class, 'grants']);
    $router->post('/grant', [ExtraNodeAccessController::class, 'grant']);
    $router->post('/revoke', [ExtraNodeAccessController::class, 'revoke']);
    $router->get('/options', [ExtraNodeAccessController::class, 'options']);
    $router->get('/diagnose', [ExtraNodeAccessController::class, 'diagnose']);
});
