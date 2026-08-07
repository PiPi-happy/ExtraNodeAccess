<?php

namespace Plugin\ExtraNodeAccess\Commands;

use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Plugin\ExtraNodeAccess\Models\ExtraNodeAccess;

/**
 * 兜底同步：对所有存在额外授权的节点强制触发一次全量用户同步。
 *
 * 背景：节点端用户表偶尔会和面板不同步（WS 抖动、重装后尤其明显），
 * 表现为"订阅看得到节点但连不上"。grant 时已有即时 notifyFullSync，
 * 但那是一次性的；本命令由定时任务每分钟调用，持续补偿，让节点端用户表保持新鲜。
 *
 * 注意：notifyFullSync 内部对未连 WS 的节点（isNodeOnline=false）会直接 return，
 * 所以不连面板的节点（如纯中转/落地）会被自动跳过，不会报错。
 */
class ResyncCommand extends Command
{
    protected $signature = 'extra-node:resync';

    protected $description = '对所有额外授权节点强制全量用户同步（定时兜底，亦可手动调用）';

    public function handle(): int
    {
        $serverIds = ExtraNodeAccess::pluck('server_id')->unique()->sort()->values()->all();

        if (empty($serverIds)) {
            $this->info('暂无授权记录，跳过。');
            return self::SUCCESS;
        }

        $pushed = 0;
        $skipped = 0;
        foreach ($serverIds as $sid) {
            $sid = (int) $sid;
            // 离线节点（未连 WS）notifyFullSync 会直接 return，这里先判一次以便统计
            if (!NodeSyncService::isNodeOnline($sid)) {
                $skipped++;
                continue;
            }
            NodeSyncService::notifyFullSync($sid);
            $pushed++;
        }

        $this->info(sprintf(
            '已处理 %d 个授权节点：已推送 %d，离线跳过 %d。',
            count($serverIds),
            $pushed,
            $skipped
        ));

        return self::SUCCESS;
    }
}
