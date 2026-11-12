<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                if (! Schema::hasColumn('sites', 'accepts_link_insertions')) {
                    $table->boolean('accepts_link_insertions')->default(false);
                }
                if (! Schema::hasColumn('sites', 'listing_link_insertion_price')) {
                    $table->decimal('listing_link_insertion_price', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('sites', 'link_insertion_price')) {
                    $table->decimal('link_insertion_price', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('sites', 'link_insertion_min_article_age_days')) {
                    $table->unsignedInteger('link_insertion_min_article_age_days')->nullable();
                }
                if (! Schema::hasColumn('sites', 'link_insertion_allow_high_traffic')) {
                    $table->boolean('link_insertion_allow_high_traffic')->default(false);
                }
                if (! Schema::hasColumn('sites', 'link_insertion_link_type')) {
                    $table->string('link_insertion_link_type', 20)->default('dofollow');
                }
                if (! Schema::hasColumn('sites', 'link_insertion_delivery_days')) {
                    $table->unsignedInteger('link_insertion_delivery_days')->default(7);
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'product_type')) {
                    $table->string('product_type', 32)->default('guest_post');
                }
                if (! Schema::hasColumn('order_items', 'preferred_article_url')) {
                    $table->string('preferred_article_url', 1000)->nullable();
                }
                if (! Schema::hasColumn('order_items', 'insertion_sentence_same')) {
                    $table->boolean('insertion_sentence_same')->default(false);
                }
                if (! Schema::hasColumn('order_items', 'insertion_sentence_new')) {
                    $table->boolean('insertion_sentence_new')->default(false);
                }
                if (! Schema::hasColumn('order_items', 'insertion_sentence_new_text')) {
                    $table->text('insertion_sentence_new_text')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'link_guarantee')) {
                    $table->boolean('link_guarantee')->default(false);
                }
                if (! Schema::hasColumn('order_items', 'link_guarantee_fee')) {
                    $table->decimal('link_guarantee_fee', 10, 2)->default(0);
                }
                if (! Schema::hasColumn('order_items', 'link_guarantee_covered_amount')) {
                    $table->decimal('link_guarantee_covered_amount', 10, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                foreach ([
                    'accepts_link_insertions',
                    'listing_link_insertion_price',
                    'link_insertion_price',
                    'link_insertion_min_article_age_days',
                    'link_insertion_allow_high_traffic',
                    'link_insertion_link_type',
                    'link_insertion_delivery_days',
                ] as $column) {
                    if (Schema::hasColumn('sites', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                foreach ([
                    'product_type',
                    'preferred_article_url',
                    'insertion_sentence_same',
                    'insertion_sentence_new',
                    'insertion_sentence_new_text',
                    'link_guarantee',
                    'link_guarantee_fee',
                    'link_guarantee_covered_amount',
                ] as $column) {
                    if (Schema::hasColumn('order_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
