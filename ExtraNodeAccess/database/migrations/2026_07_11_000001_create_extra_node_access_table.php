<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 额外节点授权表：记录"用户 ↔ 节点"的额外多对多授权关系。
 * 安装时由 PluginManager::install() 自动执行，卸载时自动 rollback。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 清理上次安装失败可能残留的半成品表（外键创建失败时表可能已建出一半）
        Schema::dropIfExists('v2_extra_node_access');

        Schema::create('v2_extra_node_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('被额外授权的用户 ID');
            $table->unsignedBigInteger('server_id')->comment('额外授权的节点 ID');
            $table->string('remark')->nullable()->comment('备注，如「VIP客户A独享」');
            $table->timestamps();

            // 防重复授权：同一 (user_id, server_id) 只能存在一条
            $table->unique(['user_id', 'server_id'], 'uk_extra_user_server');
            // 同步侧会按 server_id 反查授权用户，建索引
            $table->index('server_id', 'idx_extra_server');

            // 注意：不使用外键约束。
            // 不同 Xboard 版本里 v2_user.id / v2_server.id 的列类型（integer / bigint /
            // unsigned 组合）可能与本表 user_id / server_id（unsignedBigInteger）不完全一致，
            // 外键要求两端类型逐字节相同，不一致会触发 MySQL 1215 错误。
            // 去掉外键后授权关系由应用层维护：用户/节点被删除后，残留的授权记录不影响功能
            // （订阅侧 Server::whereIn 查不到已删节点会自动忽略；同步侧同理）。
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_extra_node_access');
    }
};
