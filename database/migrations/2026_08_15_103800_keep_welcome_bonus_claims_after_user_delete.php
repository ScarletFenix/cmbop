<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Claims are the once-per-place lock. cascadeOnDelete freed the IP when
     * the claimant was removed, so a later signup from the same place could
     * collect another €20. Keep the row; drop the user pointer.
     */
    public function up(): void
    {
        $this->replaceUserIdForeign(nullOnDelete: true);
    }

    public function down(): void
    {
        $this->replaceUserIdForeign(nullOnDelete: false);
    }

    private function replaceUserIdForeign(bool $nullOnDelete): void
    {
        if (! Schema::hasTable('welcome_bonus_claims') || ! Schema::hasColumn('welcome_bonus_claims', 'user_id')) {
            return;
        }

        if (! Schema::hasTable('users')) {
            return;
        }

        foreach (Schema::getForeignKeys('welcome_bonus_claims') as $key) {
            $columns = $key['columns'] ?? [];
            if (! in_array('user_id', $columns, true)) {
                continue;
            }

            $name = $key['name'] ?? null;
            Schema::table('welcome_bonus_claims', function (Blueprint $table) use ($name) {
                if (is_string($name) && $name !== '') {
                    $table->dropForeign($name);
                } else {
                    $table->dropForeign(['user_id']);
                }
            });
        }

        Schema::table('welcome_bonus_claims', function (Blueprint $table) use ($nullOnDelete) {
            $table->unsignedBigInteger('user_id')->nullable($nullOnDelete)->change();
        });

        Schema::table('welcome_bonus_claims', function (Blueprint $table) use ($nullOnDelete) {
            $foreign = $table->foreign('user_id')->references('id')->on('users');
            if ($nullOnDelete) {
                $foreign->nullOnDelete();
            } else {
                $foreign->cascadeOnDelete();
            }
        });
    }
};
