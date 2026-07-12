<?php

namespace Plugin\ExtraNodeAccess\Models;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 额外节点授权关系（用户 ↔ 节点 多对多）。
 *
 * 通过本表记录的授权，会在两个位置叠加生效：
 *  - 订阅侧（client.subscribe.servers）：把额外节点追加进用户的订阅节点列表；
 *  - 同步侧（server.users.get）：把额外用户追加进节点的用户列表。
 */
class ExtraNodeAccess extends Model
{
    protected $table = 'v2_extra_node_access';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id', 'id');
    }
}
