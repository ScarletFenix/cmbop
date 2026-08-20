<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_site_imports')) {
            Schema::create('agency_site_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('publisher_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 32)->default('processing')->index();
                $table->string('original_filename')->nullable();
                $table->boolean('dry_run')->default(false);
                $table->unsignedSmallInteger('processed_count')->default(0);
                $table->unsignedSmallInteger('created_count')->default(0);
                $table->unsignedSmallInteger('failed_count')->default(0);
                $table->unsignedSmallInteger('would_create_count')->default(0);
                $table->text('admin_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['publisher_id', 'status']);
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('agency_site_import_failures')) {
            Schema::create('agency_site_import_failures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_site_import_id')
                    ->constrained('agency_site_imports')
                    ->cascadeOnDelete();
                $table->unsignedInteger('row_number');
                $table->string('site_url', 255)->nullable();
                $table->string('site_name', 190)->nullable();
                $table->json('errors');
                $table->timestamps();

                // Named: Laravel's default is 65 chars and MySQL/MariaDB reject it (1059).
                $table->index(['agency_site_import_id', 'row_number'], 'asif_import_row_idx');
            });
        } else {
            $hasCompositeIndex = false;
            try {
                $indexNames = collect(Schema::getIndexes('agency_site_import_failures'))
                    ->pluck('name')
                    ->all();
                $hasCompositeIndex = in_array('asif_import_row_idx', $indexNames, true)
                    || in_array('agency_site_import_failures_agency_site_import_id_row_number_index', $indexNames, true);
            } catch (Throwable) {
                // Locked-down hosts may not list indexes; try to add anyway.
            }

            if (! $hasCompositeIndex) {
                try {
                    Schema::table('agency_site_import_failures', function (Blueprint $table) {
                        $table->index(['agency_site_import_id', 'row_number'], 'asif_import_row_idx');
                    });
                } catch (Throwable) {
                    // Leftover table already has this index, or we cannot add it.
                    // Do not block later migrations (welcome_bonus_settings).
                }
            }
        }

        if (! Schema::hasColumn('sites', 'agency_site_import_id')) {
            Schema::table('sites', function (Blueprint $table) {
                $after = Schema::hasColumn('sites', 'bulk_site_request_id')
                    ? 'bulk_site_request_id'
                    : 'publisher_id';
                $table->foreignId('agency_site_import_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('agency_site_imports')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'agency_site_import_id')) {
                $table->dropConstrainedForeignId('agency_site_import_id');
            }
        });

        Schema::dropIfExists('agency_site_import_failures');
        Schema::dropIfExists('agency_site_imports');
    }
};
