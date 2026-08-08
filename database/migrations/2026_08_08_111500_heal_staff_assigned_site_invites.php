<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair staff-assigned invites that never reached the publisher Invites tab.
 *
 * Causes this heals:
 * - Original acceptance migration backfilled publisher_accepted_at = created_at
 *   onto open staff invites
 * - Sites created before acceptance columns existed (assigned_by never saved),
 *   with activity_logs.action = site.assigned_for_acceptance
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')
            || ! Schema::hasColumn('sites', 'publisher_accepted_at')
            || ! Schema::hasColumn('sites', 'assigned_by_user_id')) {
            return;
        }

        // 1) Invites auto-accepted by backfill (accepted_at stamped equal to created_at).
        DB::table('sites')
            ->whereNotNull('assigned_by_user_id')
            ->whereNotNull('publisher_accepted_at')
            ->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            })
            ->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })
            ->whereRaw('publisher_accepted_at = created_at')
            ->update(['publisher_accepted_at' => null]);

        // 2) Staff creates that skipped assigned_by when columns were missing.
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $logs = DB::table('activity_logs')
            ->where('action', 'site.assigned_for_acceptance')
            ->where('subject_type', Site::class)
            ->whereNotNull('subject_id')
            ->orderByDesc('id')
            ->get(['subject_id', 'user_id', 'properties']);

        $seen = [];
        foreach ($logs as $log) {
            $siteId = (int) $log->subject_id;
            if ($siteId <= 0 || isset($seen[$siteId])) {
                continue;
            }
            $seen[$siteId] = true;

            $site = DB::table('sites')->where('id', $siteId)->first();
            if (! $site) {
                continue;
            }

            if ((int) ($site->active ?? 0) === 1 || (int) ($site->verified ?? 0) === 1) {
                continue;
            }

            // Already a healthy open invite.
            if (blank($site->publisher_accepted_at) && filled($site->assigned_by_user_id)) {
                continue;
            }

            // Do not reopen a genuine later Accept (accepted_at after created_at).
            if (filled($site->publisher_accepted_at)
                && filled($site->assigned_by_user_id)
                && (string) $site->publisher_accepted_at !== (string) $site->created_at) {
                continue;
            }

            $props = json_decode((string) ($log->properties ?? ''), true);
            if (! is_array($props)) {
                $props = [];
            }

            $assignedBy = (int) ($site->assigned_by_user_id
                ?: ($props['assigned_by_user_id'] ?? $log->user_id ?? 0));
            if ($assignedBy <= 0) {
                continue;
            }

            DB::table('sites')->where('id', $siteId)->update([
                'assigned_by_user_id' => $assignedBy,
                'publisher_accepted_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data heal.
    }
};
