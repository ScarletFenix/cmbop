<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites') && ! Schema::hasColumn('sites', 'first_catalogued_at')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->timestamp('first_catalogued_at')->nullable()->after('active');
            });
        }

        if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'first_catalogued_at')) {
            // Existing rows are the historical catalogue — never email them as "new".
            DB::table('sites')->whereNull('first_catalogued_at')->update([
                'first_catalogued_at' => DB::raw('created_at'),
            ]);
        }

        if (! Schema::hasTable('weekly_new_sites_settings')) {
            Schema::create('weekly_new_sites_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('weekly_new_sites_interests')) {
            Schema::create('weekly_new_sites_interests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('niche', 191);
                $table->string('country_code', 8);
                $table->string('source', 16)->default('inferred');
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'niche', 'country_code'], 'wns_interests_user_pair');
                $table->index(['user_id', 'enabled'], 'wns_interests_user_enabled');
            });
        }

        if (! Schema::hasTable('weekly_new_sites_runs')) {
            Schema::create('weekly_new_sites_runs', function (Blueprint $table) {
                $table->id();
                $table->string('week_key', 16);
                $table->boolean('dry_run')->default(false);
                $table->string('status', 24)->default('running');
                $table->unsignedInteger('matched_advertisers')->default(0);
                $table->unsignedInteger('queued')->default(0);
                $table->unsignedInteger('skipped_thin')->default(0);
                $table->unsignedInteger('skipped_permission')->default(0);
                $table->unsignedInteger('skipped_other')->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['week_key', 'dry_run'], 'wns_runs_week');
            });
        }

        if (! Schema::hasTable('weekly_new_sites_recipients')) {
            Schema::create('weekly_new_sites_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->nullable();
                $table->unsignedBigInteger('user_id');
                $table->string('week_key', 16);
                $table->string('status', 24)->default('queued');
                $table->string('permission_basis', 40)->nullable();
                $table->unsignedTinyInteger('site_count')->default(0);
                $table->json('match_pairs')->nullable();
                $table->string('dedupe_key', 191)->nullable();
                $table->string('skip_reason', 64)->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'week_key'], 'wns_recipients_user_week');
                $table->index('run_id', 'wns_recipients_run');
                $table->index('status', 'wns_recipients_status');
            });
        }

        if (! Schema::hasTable('weekly_new_sites_sends')) {
            Schema::create('weekly_new_sites_sends', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipient_id')->nullable();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('site_id');
                $table->string('domain', 191)->nullable();
                $table->string('niche', 191)->nullable();
                $table->string('country_code', 8)->nullable();
                $table->string('status', 24)->default('queued');
                $table->decimal('price_eur', 10, 2)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'site_id'], 'wns_sends_user_site');
                $table->index(['user_id', 'domain'], 'wns_sends_user_domain');
                $table->index('recipient_id', 'wns_sends_recipient');
            });
        }

        if (! Schema::hasTable('weekly_new_sites_clicks')) {
            Schema::create('weekly_new_sites_clicks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('site_id')->nullable();
                $table->unsignedBigInteger('recipient_id')->nullable();
                $table->string('kind', 24)->default('site');
                $table->string('destination_path', 512)->nullable();
                $table->timestamp('clicked_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'site_id', 'clicked_at'], 'wns_clicks_user_site');
                $table->index('recipient_id', 'wns_clicks_recipient');
            });
        }

        if (! Schema::hasTable('weekly_new_sites_tokens')) {
            Schema::create('weekly_new_sites_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('token_hash', 64)->unique();
                $table->unsignedBigInteger('user_id');
                $table->string('purpose', 24);
                $table->json('payload')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'purpose'], 'wns_tokens_user_purpose');
            });
        }

        if (Schema::hasTable('weekly_new_sites_settings')) {
            $exists = DB::table('weekly_new_sites_settings')->where('key', 'activated_at')->exists();
            if (! $exists) {
                $now = now()->toIso8601String();
                DB::table('weekly_new_sites_settings')->insert([
                    'key' => 'activated_at',
                    'value' => $now,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_new_sites_tokens');
        Schema::dropIfExists('weekly_new_sites_clicks');
        Schema::dropIfExists('weekly_new_sites_sends');
        Schema::dropIfExists('weekly_new_sites_recipients');
        Schema::dropIfExists('weekly_new_sites_runs');
        Schema::dropIfExists('weekly_new_sites_interests');
        Schema::dropIfExists('weekly_new_sites_settings');

        if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'first_catalogued_at')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('first_catalogued_at');
            });
        }
    }
};
