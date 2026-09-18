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
 *  - grant / revoke 授权管理（含校验与缓存清理）；
 *  - 管理端查询（按用户/按节点聚合、分页搜索、统计）。
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
        // 防重复授权（先查一遍给友好提示；并发下.unique 约束兜底见下方 catch）
        if (ExtraNodeAccess::where('user_id', $userId)->where('server_id', $serverId)->exists()) {
            return ['success' => false, 'message' => "用户 [{$user->email}] 已被授权过节点 [{$server->name}]"];
        }
        try {
            ExtraNodeAccess::create([
                'user_id' => $userId,
                'server_id' => $serverId,
                'remark' => $remark,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // 并发双击/重复提交会撞 UNIQUE(user_id, server_id)，转成友好提示而不是 500
            if ((string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return ['success' => false, 'message' => "用户 [{$user->email}] 已被授权过节点 [{$server->name}]"];
            }
            throw $e;
        }
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
     * 列表查询（联表 user/server，给命令/API 展示用，全量不分页）。
     */
    public function getGrants(?int $userId = null, ?int $serverId = null)
    {
        $query = ExtraNodeAccess::query()
            ->leftJoin($this->userTable() . ' as u', 'v2_extra_node_access.user_id', '=', 'u.id')
            ->leftJoin($this->serverTable() . ' as s', 'v2_extra_node_access.server_id', '=', 's.id')
            ->select(
                'v2_extra_node_access.id',
                'v2_extra_node_access.user_id',
                'v2_extra_node_access.server_id',
                'v2_extra_node_access.remark',
                'v2_extra_node_access.created_at',
                'u.email as user_email',
                's.name as server_name',
                's.show as server_show'
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
     * 平铺分页列表（keyword 匹配用户邮箱/节点名/备注）。
     * 返回 ['data' => ..., 'total' => int, 'page' => int, 'page_size' => int]。
     */
    public function paginateGrants(?string $keyword, int $page = 1, int $pageSize = 20): array
    {
        $query = $this->grantsQuery();
        if ($keyword !== null && $keyword !== '') {
            $kw = "%{$keyword}%";
            $query->where(function ($q) use ($kw) {
                $q->where('u.email', 'like', $kw)
                    ->orWhere('s.name', 'like', $kw)
                    ->orWhere('v2_extra_node_access.remark', 'like', $kw);
            });
        }
        $total = (clone $query)->count();
        $list = $query
            ->orderBy('v2_extra_node_access.id', 'desc')
            ->forPage($page, $pageSize)
            ->get();
        return ['data' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * 按用户聚合视角：每个被授权用户一行，带其全部授权节点。
     * keyword 匹配用户邮箱/备注；返回 ['data' => ..., 'total' => int, 'page' => ..., 'page_size' => ...]。
     */
    public function getGrantsByUser(?string $keyword, int $page = 1, int $pageSize = 20): array
    {
        $idQuery = ExtraNodeAccess::query()
            // leftJoin：用户被删除的残留授权也展示（email 为 null 显示"已删除"），便于运营清理
            ->leftJoin($this->userTable() . ' as u', 'v2_extra_node_access.user_id', '=', 'u.id')
            ->when($keyword !== null && $keyword !== '', function ($q) use ($keyword) {
                $kw = "%{$keyword}%";
                $q->where(function ($q2) use ($kw) {
                    $q2->where('u.email', 'like', $kw)
                        ->orWhere('v2_extra_node_access.remark', 'like', $kw);
                });
            })
            ->select('v2_extra_node_access.user_id')
            ->distinct();
        $total = (clone $idQuery)->distinct()->count('v2_extra_node_access.user_id');
        $userIds = (clone $idQuery)
            ->orderBy('v2_extra_node_access.user_id')
            ->forPage($page, $pageSize)
            ->pluck('v2_extra_node_access.user_id');
        if ($userIds->isEmpty()) {
            return ['data' => [], 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
        }

        $emails = User::whereIn('id', $userIds)->pluck('email', 'id');
        $grants = $this->grantsQuery()
            ->whereIn('v2_extra_node_access.user_id', $userIds)
            ->orderBy('v2_extra_node_access.user_id')
            ->orderBy('v2_extra_node_access.id', 'desc')
            ->get()
            ->groupBy('user_id');

        $data = $userIds->map(fn ($uid) => [
            'user_id' => (int) $uid,
            'email' => $emails->get($uid),
            'grant_count' => $grants->has($uid) ? $grants[$uid]->count() : 0,
            'grants' => $grants->get($uid, collect())->values(),
        ])->values();
        return ['data' => $data, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * 按节点聚合视角：全部节点 + 各自的授权用户列表（节点数量级小，不分页）。
     * $userId 传入时，每个节点附带 authorized 标记（该用户是否已被授权，供节点池勾选置灰）。
     */
    public function getServersWithGrants(?int $userId = null): array
    {
        $servers = Server::query()
            ->select('id', 'name', 'show', 'host')
            ->orderByDesc('id')
            ->get();
        $rows = $this->grantsQuery()
            ->orderBy('v2_extra_node_access.server_id')
            ->get()
            ->groupBy('server_id');
        $mine = $userId
            ? ExtraNodeAccess::where('user_id', $userId)->pluck('server_id')->flip()
            : collect();
        return $servers->map(fn ($s) => [
            'id' => (int) $s->id,
            'name' => $s->name,
            'show' => (bool) $s->show,
            'host' => $s->host,
            'grant_count' => $rows->has($s->id) ? $rows[$s->id]->count() : 0,
            'users' => $rows->get($s->id, collect())->values(),
            'authorized' => $mine->has($s->id),
        ])->values()->all();
    }

    /**
     * 用户搜索（管理抽屉的远程搜索框用，替代旧版一次只取最新 50 人的下拉）。
     * $serverId 传入时附带 authorized 标记（该用户对指定节点是否已授权）。
     */
    public function searchUsers(?string $keyword, ?int $serverId = null, int $limit = 20): array
    {
        $query = User::query()
            ->select('id', 'email')
            ->when($keyword !== null && $keyword !== '', fn ($q) => $q->where('email', 'like', "%{$keyword}%"))
            ->orderByDesc('id')
            ->limit($limit);
        $users = $query->get();
        $authorizedIds = $serverId
            ? ExtraNodeAccess::where('server_id', $serverId)->pluck('user_id')->flip()
            : collect();
        return $users->map(fn ($u) => [
            'id' => (int) $u->id,
            'email' => $u->email,
            'authorized' => $authorizedIds->has($u->id),
        ])->values()->all();
    }

    /**
     * 顶部统计条：总授权数 / 涉及用户数 / 涉及节点数 / 隐藏节点授权数。
     */
    public function getStats(): array
    {
        return [
            'total' => ExtraNodeAccess::count(),
            'user_count' => ExtraNodeAccess::distinct('user_id')->count('user_id'),
            'server_count' => ExtraNodeAccess::distinct('server_id')->count('server_id'),
            'hidden_count' => ExtraNodeAccess::query()
                ->join($this->serverTable() . ' as s', 'v2_extra_node_access.server_id', '=', 's.id')
                ->where('s.show', 0)
                ->count(),
        ];
    }

    /**
     * 授权列表基础查询（联表别名 u/s，供 paginateGrants / getGrantsByUser / getServersWithGrants 复用）。
     */
    protected function grantsQuery()
    {
        return ExtraNodeAccess::query()
            ->leftJoin($this->userTable() . ' as u', 'v2_extra_node_access.user_id', '=', 'u.id')
            ->leftJoin($this->serverTable() . ' as s', 'v2_extra_node_access.server_id', '=', 's.id')
            ->select(
                'v2_extra_node_access.id',
                'v2_extra_node_access.user_id',
                'v2_extra_node_access.server_id',
                'v2_extra_node_access.remark',
                'v2_extra_node_access.created_at',
                'u.email as user_email',
                's.name as server_name',
                's.show as server_show'
            );
    }

    /**
     * 表名从核心模型派生，不硬编码 v2_ 前缀（防 Xboard 未来改前缀）。
     */
    protected function userTable(): string
    {
        return (new User())->getTable();
    }

    protected function serverTable(): string
    {
        return (new Server())->getTable();
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
