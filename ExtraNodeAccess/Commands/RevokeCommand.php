<?php

namespace Plugin\ExtraNodeAccess\Commands;

use Illuminate\Console\Command;
use Plugin\ExtraNodeAccess\Services\ExtraNodeAccessService;

class RevokeCommand extends Command
{
    protected $signature = 'extra-node:revoke
        {user_id : 用户 ID}
        {server_id : 节点 ID}';

    protected $description = '取消指定用户对某个节点的额外授权';

    public function handle(): int
    {
        $service = new ExtraNodeAccessService();
        $result = $service->revoke(
            (int) $this->argument('user_id'),
            (int) $this->argument('server_id')
        );

        if ($result['success']) {
            $this->info('✅ ' . $result['message']);
            return self::SUCCESS;
        }
        $this->error('❌ ' . $result['message']);
        return self::FAILURE;
    }
}
