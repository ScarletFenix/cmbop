<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit receipts are numbered independently of sales invoices, so the yearly
 * counter has to be tracked per series instead of once per year.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_sequences')) {
            return;
        }

        if (! Schema::hasColumn('invoice_sequences', 'series')) {
            Schema::table('invoice_sequences', function (Blueprint $table) {
                $table->string('series', 12)->default('INV')->after('id');
            });

            DB::table('invoice_sequences')->update([
                'series' => (string) config('billing.invoice_number.prefix', 'INV'),
            ]);
        }

        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->dropUnique('invoice_sequences_year_unique');
        });

        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->unique(['series', 'year']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_sequences')) {
            return;
        }

        $prefix = (string) config('billing.invoice_number.prefix', 'INV');

        DB::table('invoice_sequences')->where('series', '!=', $prefix)->delete();

        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->dropUnique(['series', 'year']);
            $table->dropColumn('series');
        });

        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->unique('year');
        });
    }
};
