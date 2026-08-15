<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_moderation_logs')) {
            return;
        }

        Schema::table('content_moderation_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('content_moderation_logs', 'content_submission_id')) {
                $table->foreignId('content_submission_id')
                    ->nullable()
                    ->after('order_item_id')
                    ->constrained('content_submissions')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        $logs = DB::table('content_moderation_logs')
            ->whereNull('content_submission_id')
            ->where('document_url', 'like', 'upload:%')
            ->get(['id', 'document_url']);

        foreach ($logs as $log) {
            if (! preg_match('/^upload:(\d+)$/', (string) $log->document_url, $matches)) {
                continue;
            }

            $submissionId = (int) $matches[1];
            if (! DB::table('content_submissions')->where('id', $submissionId)->exists()) {
                continue;
            }

            DB::table('content_moderation_logs')->where('id', $log->id)->update([
                'content_submission_id' => $submissionId,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_moderation_logs')
            || ! Schema::hasColumn('content_moderation_logs', 'content_submission_id')) {
            return;
        }

        Schema::table('content_moderation_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_submission_id');
        });
    }
};
