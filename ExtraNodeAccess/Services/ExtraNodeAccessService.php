<?php

namespace Plugin\ExtraNodeAccess\Services;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\ExtraNodeAccess\Models\ExtraNodeAccess;

/**
 * 额外节点授权核心服务。
 *
 * 职责：
 *  - 查询授权关系（带缓存，避免每次订阅/同步都查 DB）；
 *  - 订阅侧节点字段加工（完全照搬 ServerService::getAvailableServers 的逻辑）；
 *  - grant / revoke 授权管理（含校验与缓存清理）。
 *
 * 两个方向的缓存：
 *  - extra_node:user:{userId}   → 该用户额外授权的 server_id 列表（订阅侧用）
 *  - extra_node:server:{serverId} → 该节点额外授权的 user_id 列表（同步侧用）
 */
class ExtraNodeAccessService
{
    /**
     * 订阅侧：取出该用户被额外授权的所有节点 ID。
     */
    public function getExtraServerIds(int $userId): array
    {
        return Cache::remember($this->userCacheKey($userId), 3600, function () use ($userId) {
            return ExtraNodeAccess::where('user_id', $userId)->pluck('server_id')->toArray();
        });
    }

    /**
     * 同步侧：取出该节点被额外授权的所有用户 ID。
     */
    public function getExtraUserIds(int $serverId): array
    {
        return Cache::remember($this->serverCacheKey($serverId), 3600, function () use ($serverId) {
            return ExtraNodeAccess::where('server_id', $serverId)->pluck('user_id')->toArray();
        });
    }

    /**
     * 订阅侧节点字段加工。
     * 完全照搬 ServerService::getAvailableServers 的 map 闭包
     * （app/Services/ServerService.php:68-80），保证额外节点的
     * port / password / rate 与正常节点处理一致，用户才能真正连上。
     */
    public function processServer(Server $server, User $user): Server
    {
        if (str_contains((string) $server->port, '-')) {
            // 动态端口范围（如 "20000-30000"）：随机取一个具体端口
            $port = $server->port;
            $server->port = (int) Helper::randomPort($port);
            $server->ports = $port;
        } else {
            $server->port = (int) $server->port;
        }
        $server->password = $server->generateServerPassword($user);
        $server->rate = $server->getCurrentRate();
        return $server;
    }

    /**
     * 授权：给用户额外开放节点。
     *
     * @return array{success: bool, message: string}
     */
    public function grant(int $userId, int $serverId, ?string $remark = null): array
    {
        $user = User::find($userId);
        if (!$user) {
            return ['success' => false, 'message' => "用户 [{$userId}] 不存在"];
        }
        $server = Server::find($serverId);
        if (!$server) {
            return ['success' => false, 'message' => "节点 [{$serverId}] 不存在"];
        }
        // 隐藏节点授权校验
        if (!$this->getConfig('allow_hidden', true) && !$server->show) {
            return ['success' => false, 'message' => "节点 [{$server->name}] 为隐藏节点（show=false），当前配置不允许授权隐藏节点"];
        }
        // 防重复授权
        if (ExtraNodeAccess::where('user_id', $userId)->where('server_id', $serverId)->exists()) {
            return ['success' => false, 'message' => "用户 [{$user->email}] 已被授权过节点 [{$server->name}]"];
        }
        ExtraNodeAccess::create([
            'user_id' => $userId,
            'server_id' => $serverId,
            'remark' => $remark,
        ]);
        $this->clearCache($userId, $serverId);
        $this->triggerNodeSync($serverId);
        // group_ids 为空时 getAvailableUsers 直接返回空、不触发同步 Hook，节点端永远收不到该用户
        $warning = empty($server->group_ids)
            ? "（⚠️警告：该节点未设置权限组，节点端不会同步此用户、无法上网。请到「节点管理」给该节点设置一个权限组——可以是新建的、不含任何用户的空组——设置后重新授权即可）"
            : "";
        return ['success' => true, 'message' => "已授权：用户 [{$user->email}] → 节点 [{$server->name}]{$warning}"];
    }

    /**
     * 取消授权。
     *
     * @return array{success: bool, message: string}
     */
    public function revoke(int $userId, int $serverId): array
    {
        $deleted = ExtraNodeAccess::where('user_id', $userId)
            ->where('server_id', $serverId)
            ->delete();
        if ($deleted) {
            $this->clearCache($userId, $serverId);
            $this->triggerNodeSync($serverId);
            return ['success' => true, 'message' => "已取消授权：用户 [{$userId}] ↔ 节点 [{$serverId}]"];
        }
        return ['success' => false, 'message' => "未找到授权记录：用户 [{$userId}] ↔ 节点 [{$serverId}]"];
    }

    /**
     * 列表查询（联表 user/server，给命令/API/网页展示用）。
     */
    public function getGrants(?int $userId = null, ?int $serverId = null)
    {
        $query = ExtraNodeAccess::query()
            ->leftJoin('v2_user', 'v2_extra_node_access.user_id', '=', 'v2_user.id')
            ->leftJoin('v2_server', 'v2_extra_node_access.server_id', '=', 'v2_server.id')
            ->select(
                'v2_extra_node_access.id',
                'v2_extra_node_access.user_id',
                'v2_extra_node_access.server_id',
                'v2_extra_node_access.remark',
                'v2_extra_node_access.created_at',
                'v2_user.email as user_email',
                'v2_server.name as server_name',
                'v2_server.show as server_show'
            );
        if ($userId) {
            $query->where('v2_extra_node_access.user_id', $userId);
        }
        if ($serverId) {
            $query->where('v2_extra_node_access.server_id', $serverId);
        }
        return $query->orderBy('v2_extra_node_access.id', 'desc')->get();
    }

    /**
     * 清除指定用户 + 节点的授权缓存。
     */
    public function clearCache(?int $userId = null, ?int $serverId = null): void
    {
        if ($userId !== null) {
            Cache::forget($this->userCacheKey($userId));
        }
        if ($serverId !== null) {
            Cache::forget($this->serverCacheKey($serverId));
        }
    }

    /**
     * 清除所有授权缓存（插件卸载时调用）。
     */
    public function clearAllCache(): void
    {
        foreach (ExtraNodeAccess::all(['user_id', 'server_id']) as $grant) {
            Cache::forget($this->userCacheKey($grant->user_id));
            Cache::forget($this->serverCacheKey($grant->server_id));
        }
        Cache::forget('extra_node:config');
    }

    /**
     * 授权变更后主动触发节点的全量用户同步，让变更立即生效。
     * 走 NodeSyncService::notifyFullSync：WS 节点即时推送含变更的用户表；
     * HTTP（UniProxy）节点不响应 WS 推送，靠自身定时拉取（通常 60s 内）补上。
     * 注意：若节点 group_ids 为空，getAvailableUsers 直接返回空，此推送无效——
     * 这种情况由 grant() 的警告提示用户给节点设置权限组。
     */
    protected function triggerNodeSync(int $serverId): void
    {
        try {
            NodeSyncService::notifyFullSync($serverId);
        } catch (\Throwable $e) {
            Log::warning('[ExtraNodeAccess] triggerNodeSync failed: ' . $e->getMessage());
        }
    }

    /**
     * 读取插件配置项（后台配置页修改，60s 缓存）。
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        $config = Cache::remember('extra_node:config', 60, function () {
            $plugin = \App\Models\Plugin::where('code', 'extra_node_access')->first();
            if (!$plugin) {
                return [];
            }
            $config = is_string($plugin->config) ? json_decode($plugin->config, true) : ($plugin->config ?? []);
            return is_array($config) ? $config : [];
        });
        return $config[$key] ?? $default;
    }

    protected function userCacheKey(int $userId): string
    {
        return "extra_node:user:{$userId}";
    }

    protected function serverCacheKey(int $serverId): string
    {
        return "extra_node:server:{$serverId}";
    }
}
