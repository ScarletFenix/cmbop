<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_feature_purchases')
            || ! Schema::hasColumn('site_feature_purchases', 'stripe_session_id')) {
            return;
        }

        if ($this->hasIndex('site_feature_purchases', 'site_feature_purchases_stripe_session_id_unique')
            || $this->hasDuplicateStripeSessions()) {
            return;
        }

        Schema::table('site_feature_purchases', function (Blueprint $table) {
            if ($this->hasIndex('site_feature_purchases', 'site_feature_purchases_stripe_session_id_index')) {
                $table->dropIndex(['stripe_session_id']);
            }
        });

        Schema::table('site_feature_purchases', function (Blueprint $table) {
            $table->unique('stripe_session_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_feature_purchases')
            || ! Schema::hasColumn('site_feature_purchases', 'stripe_session_id')) {
            return;
        }

        Schema::table('site_feature_purchases', function (Blueprint $table) {
            if ($this->hasIndex('site_feature_purchases', 'site_feature_purchases_stripe_session_id_unique')) {
                $table->dropUnique(['stripe_session_id']);
            }
        });

        Schema::table('site_feature_purchases', function (Blueprint $table) {
            if (! $this->hasIndex('site_feature_purchases', 'site_feature_purchases_stripe_session_id_index')) {
                $table->index('stripe_session_id');
            }
        });
    }

    private function hasDuplicateStripeSessions(): bool
    {
        return DB::table('site_feature_purchases')
            ->select('stripe_session_id')
            ->whereNotNull('stripe_session_id')
            ->where('stripe_session_id', '!=', '')
            ->groupBy('stripe_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            $sm = Schema::getConnection()->getSchemaBuilder();
            if (method_exists($sm, 'hasIndex')) {
                return $sm->hasIndex($table, $index);
            }
        } catch (Throwable) {
            // Fall through to getIndexes probe.
        }

        try {
            $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes($table);
            foreach ($indexes as $row) {
                if (($row['name'] ?? '') === $index) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
