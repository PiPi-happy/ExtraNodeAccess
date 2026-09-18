<?php

namespace Plugin\ExtraNodeAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Plugin\ExtraNodeAccess\Models\ExtraNodeAccess;
use Plugin\ExtraNodeAccess\Services\ExtraNodeAccessService;

/**
 * 额外节点授权管理 API（管理员鉴权）。
 * 路由挂在 /api/v2/{secure_path}/extra-node/* 下，套 admin 中间件。
 *
 * 端点一览：
 *  GET  /grants          授权列表（兼容旧版全量数组；带 page 参数时返回分页对象）
 *  GET  /by-user         按用户聚合（分页 + keyword 搜邮箱/备注）
 *  GET  /by-server       按节点聚合（全量，节点数级小）
 *  GET  /users           用户远程搜索（管理抽屉用，附带对指定节点的授权标记）
 *  GET  /servers         节点池（附带授权人数 + 对指定用户的授权标记）
 *  GET  /stats           顶部统计条
 *  POST /grant           授权（支持 user_id(s)/server_id(s) 单个或批量）
 *  POST /revoke          取消授权
 *  GET  /options         旧版下拉数据（保留兼容，新前端不依赖）
 *  GET  /diagnose        同步诊断
 */
class ExtraNodeAccessController extends Controller
{
    private ExtraNodeAccessService $service;

    public function __construct()
    {
        $this->service = new ExtraNodeAccessService();
    }

    /** GET extra-node/grants?user_id=&server_id=&keyword=&page=&page_size= — 授权列表 */
    public function grants(Request $request)
    {
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        // 旧版行为（无 page 参数）：全量数组，保持 API 兼容
        if ($request->input('page') === null) {
            return $this->success($this->service->getGrants($userId, $serverId));
        }
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(5, (int) $request->input('page_size', 20)));
        return $this->success($this->service->paginateGrants(
            $request->input('keyword'),
            $page,
            $pageSize
        ));
    }

    /** GET extra-node/by-user?keyword=&page=&page_size= — 按用户聚合 */
    public function byUser(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(5, (int) $request->input('page_size', 20)));
        return $this->success($this->service->getGrantsByUser(
            $request->input('keyword'),
            $page,
            $pageSize
        ));
    }

    /** GET extra-node/by-server?user_id= — 按节点聚合 */
    public function byServer(Request $request)
    {
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        return $this->success($this->service->getServersWithGrants($userId));
    }

    /** GET extra-node/users?keyword=&server_id= — 用户远程搜索 */
    public function users(Request $request)
    {
        return $this->success($this->service->searchUsers(
            $request->input('keyword'),
            $request->input('server_id') ? (int) $request->input('server_id') : null
        ));
    }

    /** GET extra-node/servers?user_id= — 节点池 */
    public function servers(Request $request)
    {
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        return $this->success($this->service->getServersWithGrants($userId));
    }

    /** GET extra-node/stats — 顶部统计 */
    public function stats()
    {
        return $this->success($this->service->getStats());
    }

    /**
     * POST extra-node/grant {user_id | user_ids[], server_id | server_ids[], remark} — 授权
     * 支持单个或批量（数组），批量时逐条执行并汇总结果。
     */
    public function grant(Request $request)
    {
        $userIds = $this->normalizeIds($request->input('user_ids', $request->input('user_id')));
        $serverIds = $this->normalizeIds($request->input('server_ids', $request->input('server_id')));
        if (empty($userIds) || empty($serverIds)) {
            return $this->fail([400, 'user_id 与 server_id 必填（支持数组批量）']);
        }
        $remark = $request->input('remark');

        $results = [];
        $okCount = 0;
        $failMessages = [];
        foreach ($userIds as $uid) {
            foreach ($serverIds as $sid) {
                $result = $this->service->grant($uid, $sid, $remark);
                $results[] = ['user_id' => $uid, 'server_id' => $sid] + $result;
                if ($result['success']) {
                    $okCount++;
                } else {
                    $failMessages[] = $result['message'];
                }
            }
        }

        $total = count($results);
        if ($okCount === 0) {
            return $this->fail([400, '全部失败：' . implode('；', array_slice($failMessages, 0, 5))]);
        }
        $message = $total === 1
            ? $results[0]['message']
            : "批量授权完成：成功 {$okCount}/{$total} 条"
                . ($failMessages ? '，失败：' . implode('；', array_slice($failMessages, 0, 5)) : '');
        return $this->success(['message' => $message, 'results' => $results]);
    }

    /** POST extra-node/revoke {user_id, server_id} — 取消授权 */
    public function revoke(Request $request)
    {
        $userId = (int) $request->input('user_id');
        $serverId = (int) $request->input('server_id');
        if (!$userId || !$serverId) {
            return $this->fail([400, 'user_id 与 server_id 必填']);
        }
        $result = $this->service->revoke($userId, $serverId);
        return $result['success']
            ? $this->success(['message' => $result['message']])
            : $this->fail([400, $result['message']]);
    }

    /** GET extra-node/options — 旧版下拉数据（保留兼容，新前端不依赖） */
    public function options(Request $request)
    {
        $keyword = $request->input('keyword');
        $users = User::query()
            ->select('id', 'email')
            ->when($keyword, fn($q) => $q->where('email', 'like', "%{$keyword}%"))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $servers = Server::query()
            ->select('id', 'name', 'show', 'host')
            ->orderByDesc('id')
            ->get();

        return $this->success([
            'users' => $users,
            'servers' => $servers,
            'config' => [
                'allow_hidden' => $this->service->getConfig('allow_hidden', true),
                'sync_validity_check' => $this->service->getConfig('sync_validity_check', true),
            ],
        ]);
    }

    /**
     * GET extra-node/diagnose?user_id=&server_id= — 同步诊断
     * 排查"授权后客户端连不上"：确认节点是否真的收到了该用户。
     */
    public function diagnose(Request $request)
    {
        try {
            $serverId = (int) $request->input('server_id');
            $userId = (int) $request->input('user_id');
            if (!$serverId || !$userId) {
                return $this->fail([400, 'server_id 与 user_id 必填']);
            }
            $server = Server::find($serverId);
            if (!$server) {
                return $this->fail([404, '节点不存在']);
            }

            // 调 getAvailableUsers——这会触发同步 Hook，反映"节点下次同步会拿到的用户列表"
            $available = ServerService::getAvailableUsers($server);
            $availableIds = $available->pluck('id')->all();

            $user = User::where('id', $userId)
                ->select(['id', 'email', 'uuid', 'expired_at', 'banned', 'transfer_enable', 'u', 'd'])
                ->first();

            return $this->success([
                'server_name' => $server->name,
                'group_ids' => $server->group_ids,
                'group_ids_empty' => empty($server->group_ids),
                'grant_exists' => ExtraNodeAccess::where('user_id', $userId)
                    ->where('server_id', $serverId)->exists(),
                'extra_user_ids' => $this->service->getExtraUserIds($serverId),
                'node_ws_online' => NodeSyncService::isNodeOnline($serverId),
                'available_count' => $available->count(),
                'user_in_list' => in_array($userId, $availableIds, true) || in_array((string) $userId, $availableIds, true),
                'user' => $user,
            ]);
        } catch (\Throwable $e) {
            // 暴露真实异常，便于定位（绕过 Xboard Handler 的通用 500 消息）
            return $this->fail([500, '诊断异常: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
        }
    }

    /**
     * 把 user_id / user_ids 输入归一化为非负 int 数组。
     */
    private function normalizeIds(mixed $input): array
    {
        $ids = is_array($input) ? $input : [$input];
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
