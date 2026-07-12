<?php

namespace Plugin\ExtraNodeAccess\Commands;

use Illuminate\Console\Command;
use Plugin\ExtraNodeAccess\Services\ExtraNodeAccessService;

class GrantCommand extends Command
{
    protected $signature = 'extra-node:grant
        {user_id : 被授权的用户 ID}
        {server_id : 额外授权的节点 ID}
        {--remark= : 备注信息（可选，如「VIP客户A独享」）}';

    protected $description = '给指定用户额外授权某个节点的访问权限（无需新建套餐）';

    public function handle(): int
    {
        $service = new ExtraNodeAccessService();
        $result = $service->grant(
            (int) $this->argument('user_id'),
            (int) $this->argument('server_id'),
            $this->option('remark') ?: null
        );

        if ($result['success']) {
            $this->info('✅ ' . $result['message']);
            return self::SUCCESS;
        }
        $this->error('❌ ' . $result['message']);
        return self::FAILURE;
    }
}
