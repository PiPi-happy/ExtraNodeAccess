<?php

namespace Plugin\ExtraNodeAccess\Commands;

use Illuminate\Console\Command;
use Plugin\ExtraNodeAccess\Services\ExtraNodeAccessService;

class ListCommand extends Command
{
    protected $signature = 'extra-node:list
        {--user= : 按用户 ID 筛选}
        {--server= : 按节点 ID 筛选}';

    protected $description = '列出所有额外节点授权关系';

    public function handle(): int
    {
        $service = new ExtraNodeAccessService();
        $grants = $service->getGrants(
            $this->option('user') ? (int) $this->option('user') : null,
            $this->option('server') ? (int) $this->option('server') : null
        );

        if ($grants->isEmpty()) {
            $this->info('暂无授权记录');
            return self::SUCCESS;
        }

        $rows = $grants->map(function ($g) {
            return [
                $g->id,
                $g->user_id,
                $g->user_email ?: '-',
                $g->server_id,
                $g->server_name ?: '(节点已删除)',
                $g->server_show ? '显示' : '隐藏',
                $g->remark ?: '-',
                $g->created_at,
            ];
        })->all();

        $this->table(
            ['ID', '用户ID', '用户邮箱', '节点ID', '节点名', '节点显示', '备注', '授权时间'],
            $rows
        );
        $this->info("共 {$grants->count()} 条授权记录");
        return self::SUCCESS;
    }
}
