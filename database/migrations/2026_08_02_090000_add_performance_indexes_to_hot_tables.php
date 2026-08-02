<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboards, publisher task lists, admin queues and the catalog all filter on
 * these columns, but `orders` had no indexes at all beyond its primary key and
 * unique order number. Adding them turns full scans into index lookups.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, string[]>>
     */
    private array $indexes = [
        'orders' => [
            'orders_status_index' => ['status'],
            'orders_payment_status_index' => ['payment_status'],
            'orders_user_id_status_index' => ['user_id', 'status'],
            'orders_payment_status_status_index' => ['payment_status', 'status'],
            'orders_created_at_index' => ['created_at'],
        ],
        'sites' => [
            'sites_country_index' => ['country'],
            'sites_language_index' => ['language'],
            'sites_price_index' => ['price'],
            'sites_active_verified_index' => ['active', 'verified'],
            'sites_publisher_id_active_index' => ['publisher_id', 'active'],
        ],
        'order_items' => [
            'order_items_site_id_created_at_index' => ['site_id', 'created_at'],
        ],
        'deposit_requests' => [
            'deposit_requests_status_index' => ['status'],
            'deposit_requests_user_id_status_index' => ['user_id', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);

            foreach ($definitions as $name => $columns) {
                if (in_array($name, $existing, true)) {
                    continue;
                }

                if (! $this->hasAllColumns($table, $columns)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->existingIndexNames($table);

            foreach (array_keys($definitions) as $name) {
                if (! in_array($name, $existing, true)) {
                    continue;
                }

                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                } catch (Throwable $e) {
                    // MySQL reuses a composite index as the backing index for a foreign
                    // key when the FK column is its prefix (e.g. orders_user_id_status_index
                    // covering orders.user_id), and then refuses to drop it. Leaving that
                    // index in place is harmless, so keep rolling back the rest.
                    continue;
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function existingIndexNames(string $table): array
    {
        try {
            return array_map(
                fn (array $index) => (string) $index['name'],
                Schema::getIndexes($table)
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param  string[]  $columns
     */
    private function hasAllColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
