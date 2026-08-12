<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'homepage_placement_prices')) {
                $table->json('homepage_placement_prices')->nullable()->after('sensitive_prices');
            }
            if (! Schema::hasColumn('sites', 'social_promotion')) {
                $table->json('social_promotion')->nullable()->after('homepage_placement_prices');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'homepage_days')) {
                $table->unsignedSmallInteger('homepage_days')->nullable()->after('additional_price');
            }
            if (! Schema::hasColumn('order_items', 'homepage_price')) {
                $table->decimal('homepage_price', 10, 2)->default(0)->after('homepage_days');
            }
            if (! Schema::hasColumn('order_items', 'social_channels')) {
                $table->json('social_channels')->nullable()->after('homepage_price');
            }
            if (! Schema::hasColumn('order_items', 'social_post_urls')) {
                $table->json('social_post_urls')->nullable()->after('social_channels');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            foreach (['social_post_urls', 'social_channels', 'homepage_price', 'homepage_days'] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('sites', function (Blueprint $table) {
            foreach (['social_promotion', 'homepage_placement_prices'] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
