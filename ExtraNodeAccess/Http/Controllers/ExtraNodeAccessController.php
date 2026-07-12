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
 */
class ExtraNodeAccessController extends Controller
{
    private ExtraNodeAccessService $service;

    public function __construct()
    {
        $this->service = new ExtraNodeAccessService();
    }

    /** GET extra-node/grants?user_id=&server_id= — 授权列表 */
    public function grants(Request $request)
    {
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        return $this->success($this->service->getGrants($userId, $serverId));
    }

    /** POST extra-node/grant {user_id, server_id, remark} — 新增授权 */
    public function grant(Request $request)
    {
        $userId = (int) $request->input('user_id');
        $serverId = (int) $request->input('server_id');
        if (!$userId || !$serverId) {
            return $this->fail([400, 'user_id 与 server_id 必填']);
        }
        $result = $this->service->grant($userId, $serverId, $request->input('remark'));
        return $result['success']
            ? $this->success(['message' => $result['message']])
            : $this->fail([400, $result['message']]);
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

    /** GET extra-node/options — 供管理网页下拉选择 */
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
                'user_in_list' => in_array($userId, $availableIds),
                'user' => $user,
            ]);
        } catch (\Throwable $e) {
            // 暴露真实异常，便于定位（绕过 Xboard Handler 的通用 500 消息）
            return $this->fail([500, '诊断异常: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
        }
    }
}
