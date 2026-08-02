<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Some columns used to exist only because CheckoutSchemaService added them at
 * runtime, so a database built purely from migrations was silently incomplete
 * and blew up on the first write. Migrations must stand on their own.
 */
class SchemaCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: class-string<Model>}>
     */
    public static function coreModels(): array
    {
        return [
            [Order::class],
            [OrderItem::class],
            [OrderChatMessage::class],
            [DepositRequest::class],
            [Wallet::class],
            [Site::class],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[DataProvider('coreModels')]
    public function test_every_fillable_attribute_has_a_migrated_column(string $modelClass): void
    {
        $model = new $modelClass;
        $table = $model->getTable();

        $this->assertTrue(Schema::hasTable($table), "Table [{$table}] was not created by any migration.");

        $missing = array_values(array_filter(
            $model->getFillable(),
            fn (string $column): bool => ! Schema::hasColumn($table, $column)
        ));

        $this->assertSame([], $missing, sprintf(
            '%s declares fillable columns that no migration creates on [%s]: %s',
            class_basename($modelClass),
            $table,
            implode(', ', $missing)
        ));
    }

    public function test_checkout_runtime_patcher_only_repairs_columns_migrations_also_create(): void
    {
        // CheckoutSchemaService is a deploy-time safety net, not the source of
        // truth: anything it can add must already exist after a clean migrate.
        preg_match_all(
            "/addColumn\('(?<table>[a-z_]+)', '(?<column>[a-z_]+)'/",
            file_get_contents(app_path('Services/CheckoutSchemaService.php')),
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'Expected to find addColumn() calls to verify.');

        $missing = [];
        foreach ($matches as $match) {
            if (! Schema::hasColumn($match['table'], $match['column'])) {
                $missing[] = $match['table'].'.'.$match['column'];
            }
        }

        $this->assertSame([], $missing, 'Columns only created at runtime, never by a migration: '.implode(', ', $missing));
    }
}
