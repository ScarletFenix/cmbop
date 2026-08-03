<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_chat_messages')) {
            return;
        }

        $addingBlocked = ! Schema::hasColumn('order_chat_messages', 'is_blocked');

        Schema::table('order_chat_messages', function (Blueprint $table) use ($addingBlocked) {
            if ($addingBlocked) {
                $table->boolean('is_blocked')->default(false);
            }
            if (! Schema::hasColumn('order_chat_messages', 'blocked_reason')) {
                $table->string('blocked_reason', 40)->nullable();
            }
            if ($addingBlocked) {
                $table->index(['order_id', 'is_blocked'], 'order_chat_messages_order_blocked_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_chat_messages')) {
            return;
        }

        Schema::table('order_chat_messages', function (Blueprint $table) {
            try {
                $table->dropIndex('order_chat_messages_order_blocked_idx');
            } catch (Throwable $e) {
                // Index may not exist on some drivers / re-runs.
            }

            $cols = [];
            if (Schema::hasColumn('order_chat_messages', 'blocked_reason')) {
                $cols[] = 'blocked_reason';
            }
            if (Schema::hasColumn('order_chat_messages', 'is_blocked')) {
                $cols[] = 'is_blocked';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
