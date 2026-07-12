<?php

namespace Plugin\ExtraNodeAccess;

use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Plugin\ExtraNodeAccess\Services\ExtraNodeAccessService;

/**
 * 额外节点授权插件入口。
 *
 * 通过两个现成 Hook 叠加"用户 ↔ 节点"的额外授权，零核心改动：
 *  - 订阅侧 client.subscribe.servers（ClientController.php:58）：把额外节点追加进用户订阅；
 *  - 同步侧 server.users.get（ServerService.php:111）：把额外用户追加进节点用户列表。
 *
 * 两个 filter 必须同时挂，否则会出现"订阅看得到但连不上"或反之。
 *
 * 实现细节：filter 回调用「静态方法 + [__CLASS__, 'method']」注册，而非闭包。
 * 原因：HookManager::registerFilter 用 getCallableId 作 key 去重，闭包的 id 是
 * spl_object_hash（每请求 boot 新建闭包 → 新 id → 累积回调）。改用静态方法后，
 * callable id 固定为 'Plugin\ExtraNodeAccess\Plugin::method'，每请求 boot 覆盖同一
 * key，Octane 长驻 worker 下不累积。
 */
class Plugin extends AbstractPlugin
{
    private static ?ExtraNodeAccessService $service = null;

    protected static function service(): ExtraNodeAccessService
    {
        return self::$service ??= new ExtraNodeAccessService();
    }

    public function boot(): void
    {
        // =================== 订阅侧：追加额外节点到用户订阅 ===================
        $this->filter('client.subscribe.servers', [__CLASS__, 'onSubscribeServers']);

        // =================== 同步侧：追加额外用户到节点列表 ===================
        $this->filter('server.users.get', [__CLASS__, 'onServerUsersGet']);
    }

    /**
     * 订阅侧 filter：client.subscribe.servers
     * 签名 ($servers, $user, $request)，需返回处理后的 $servers。
     */
    public static function onSubscribeServers($servers, $user, $request)
    {
        try {
            if (!$user) {
                return $servers;
            }
            $service = self::service();
            $extraIds = $service->getExtraServerIds((int) $user->id);
            if (empty($extraIds)) {
                return $servers;
            }

            $existingIds = collect($servers)->pluck('id')->filter()->all();
            $allowHidden = $service->getConfig('allow_hidden', true);

            // 取出额外节点，排除已在订阅列表里的（避免重复）
            $toAdd = Server::whereIn('id', $extraIds)
                ->whereNotIn('id', $existingIds)
                ->get();

            foreach ($toAdd as $server) {
                // 配置不允许下发隐藏节点时跳过 show=false 的节点
                if (!$allowHidden && !$server->show) {
                    continue;
                }
                // 字段加工（port/password/rate）后转数组，与原列表元素同构
                $servers[] = $service->processServer($server, $user)->toArray();
            }
        } catch (\Throwable $e) {
            // 任何异常都降级为原样返回，绝不影响订阅主流程
            Log::error('[ExtraNodeAccess] subscribe filter error: ' . $e->getMessage());
        }
        return $servers;
    }

    /**
     * 同步侧 filter：server.users.get
     * 签名 ($users, $node)，需返回处理后的 $users（Collection）。
     */
    public static function onServerUsersGet($users, $node)
    {
        try {
            if (!$node) {
                return $users;
            }
            $service = self::service();
            $extraUserIds = $service->getExtraUserIds((int) $node->id);
            if (empty($extraUserIds)) {
                return $users;
            }

            $existingIds = $users->pluck('id')->all();
            $query = User::toBase()
                ->whereIn('id', $extraUserIds)
                ->whereNotIn('id', $existingIds);

            // 与 getAvailableUsers 保持一致的可用性校验
            if ($service->getConfig('sync_validity_check', true)) {
                $query->where('banned', 0)
                    ->whereRaw('u + d < transfer_enable')
                    ->where(function ($q) {
                        $q->where('expired_at', '>=', time())->orWhereNull('expired_at');
                    });
            }
            $extra = $query->select(['id', 'uuid', 'speed_limit', 'device_limit'])->get();
            return $users->merge($extra);
        } catch (\Throwable $e) {
            Log::error('[ExtraNodeAccess] sync filter error: ' . $e->getMessage());
        }
        return $users;
    }

    /**
     * 卸载时清理缓存。建表由 PluginManager 自动跑 migration，删表由其自动 rollback。
     */
    public function cleanup(): void
    {
        self::service()->clearAllCache();
    }
}
