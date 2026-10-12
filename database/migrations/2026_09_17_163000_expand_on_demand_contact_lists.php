<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('on_demand_contacts')) {
            return;
        }

        foreach (Schema::getIndexes('on_demand_contacts') as $index) {
            $columns = $index['columns'] ?? [];
            if (($index['unique'] ?? false) && $columns === ['site_url']) {
                Schema::table('on_demand_contacts', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
            }
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE on_demand_contacts MODIFY site_url TEXT NOT NULL');
        DB::statement('ALTER TABLE on_demand_contacts MODIFY email TEXT NOT NULL');
        DB::statement('ALTER TABLE on_demand_contacts MODIFY whatsapp TEXT NOT NULL');
        DB::statement('ALTER TABLE on_demand_contacts MODIFY contacted_via_email TEXT NOT NULL');
    }

    public function down(): void
    {
        // Lists stay as text; shrinking would truncate saved values.
    }
};
