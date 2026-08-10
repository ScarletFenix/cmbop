<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking for the order reminder system.
 *
 * The EmailLog dedupe window is ten minutes, nowhere near "send this stage
 * once", so each nudge track needs a durable marker of its own. Stage counters
 * rather than one timestamp per stage: the cadences have four and five steps and
 * a column each would be unreadable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'accept_nudge_stage')) {
                $table->unsignedTinyInteger('accept_nudge_stage')->default(0);
            }
            if (! Schema::hasColumn('order_items', 'accept_nudge_sent_at')) {
                $table->timestamp('accept_nudge_sent_at')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'publish_nudge_stage')) {
                $table->unsignedTinyInteger('publish_nudge_stage')->default(0);
            }
            if (! Schema::hasColumn('order_items', 'publish_nudge_sent_at')) {
                $table->timestamp('publish_nudge_sent_at')->nullable();
            }
            // Advertiser: the earlier "still waiting on you" nudge. The later
            // one keeps using auto_approve_reminder_sent_at.
            if (! Schema::hasColumn('order_items', 'review_nudge_sent_at')) {
                $table->timestamp('review_nudge_sent_at')->nullable();
            }
            // Advertiser: told once that their publisher is late.
            if (! Schema::hasColumn('order_items', 'stalled_notice_sent_at')) {
                $table->timestamp('stalled_notice_sent_at')->nullable();
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            // The nudge commands scan by stage and timestamp every run.
            $table->index(['publish_nudge_stage', 'publish_nudge_sent_at'], 'order_items_publish_nudge_idx');
            $table->index(['accept_nudge_stage', 'accept_nudge_sent_at'], 'order_items_accept_nudge_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'new_sites_digest_sent_at')) {
                $table->timestamp('new_sites_digest_sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_publish_nudge_idx');
            $table->dropIndex('order_items_accept_nudge_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'accept_nudge_stage',
                'accept_nudge_sent_at',
                'publish_nudge_stage',
                'publish_nudge_sent_at',
                'review_nudge_sent_at',
                'stalled_notice_sent_at',
            ] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'new_sites_digest_sent_at')) {
                $table->dropColumn('new_sites_digest_sent_at');
            }
        });
    }
};
