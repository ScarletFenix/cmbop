<?php

namespace App\Services\Advertiser;

use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ContentLibraryDuplicator
{
    public function __construct(
        private readonly ContentUploadService $uploads,
    ) {}

    /**
     * Copy an article into a new unused library row. Paid lock / live placement
     * stay on the source. Completed/LIVE rows are rejected.
     *
     * @return array{ok:bool, submission?:ContentSubmission, message:string, title?:string}
     */
    public function duplicate(ContentSubmission $source, User $actor, ?string $country = null, ?string $language = null): array
    {
        if ((int) $source->user_id !== (int) $actor->id) {
            throw new RuntimeException('This article is not in your library.');
        }

        if (! $source->canDuplicateForLibrary()) {
            return [
                'ok' => false,
                'title' => 'Cannot duplicate',
                'message' => 'Completed articles cannot be duplicated. Upload a new article instead.',
            ];
        }

        $country = strtolower(trim((string) ($country ?: $source->country)));
        $language = strtolower(trim((string) ($language ?: $source->language)));
        $marketError = $this->uploads->validateMarket($country, $language);
        if ($marketError !== null) {
            return [
                'ok' => false,
                'title' => 'Market required',
                'message' => $marketError,
            ];
        }

        $marketChanged = $country !== strtolower((string) $source->country)
            || $language !== strtolower((string) $source->language);

        $cfg = $this->uploads->effectiveConfig();
        $retentionMonths = max(1, (int) ($cfg['retention_months'] ?? 6));
        [$disk, $path, $sizeBytes] = $this->copyStoredFile($source);

        $draft = is_array($source->draft_payload) ? $source->draft_payload : [];
        unset($draft['order_id'], $draft['order_item_id'], $draft['cart_key']);

        $attrs = [
            'user_id' => $actor->id,
            'site_id' => null,
            'copy_index' => 0,
            'cart_key' => null,
            'original_filename' => $source->original_filename,
            'title' => $this->copyTitle($source),
            'country' => $country,
            'language' => $language,
            'disk' => $disk,
            'path' => $path,
            'mime' => $source->mime,
            'extension' => $source->extension,
            'size_bytes' => $sizeBytes,
            'extracted_text' => $source->extracted_text,
            'preview_html' => $source->preview_html,
            'word_count' => $source->word_count,
            'anchor_text' => $source->anchor_text,
            'target_url' => $source->target_url,
            'feature_image_url' => $source->feature_image_url,
            'image_rights' => $source->image_rights,
            'image_rights_source' => $source->image_rights_source,
            'image_rights_declared_at' => $source->image_rights_declared_at,
            'publication_mode' => ContentSubmission::MODE_IMMEDIATE,
            'scheduled_publish_at' => null,
            'timezone' => $source->timezone ?: ($cfg['scheduling']['default_timezone'] ?? 'UTC'),
            'wizard_step' => $marketChanged ? 1 : max(1, (int) $source->wizard_step),
            'draft_payload' => $draft,
            'order_id' => null,
            'order_item_id' => null,
            'expires_at' => now()->addMonths($retentionMonths),
            'archived_at' => null,
            'approval_notified_at' => null,
            'moderation_log_id' => null,
            'scan_token' => null,
        ];

        if (! $marketChanged) {
            $attrs['uniqueness_score'] = $source->uniqueness_score;
            $attrs['quality_score'] = $source->quality_score;
            $attrs['evaluation_status'] = $source->evaluation_status;
            $attrs['evaluation_report'] = $source->evaluation_report;
            $attrs['evaluated_at'] = $source->evaluated_at;
            $attrs['moderation_status'] = $source->moderation_status;
        } else {
            $attrs['moderation_status'] = ContentSubmission::STATUS_PROCESSING;
            $attrs['evaluation_status'] = 'processing';
        }

        $clone = ContentSubmission::create($attrs);

        if ($marketChanged) {
            $this->uploads->reEvaluateSubmission($clone->fresh(), true);
            $clone = $clone->fresh();
        }

        return [
            'ok' => true,
            'submission' => $clone,
            'title' => $clone->title,
            'message' => 'Created a new unused article. The original is unchanged.',
        ];
    }

    private function copyTitle(ContentSubmission $source): string
    {
        $base = trim((string) ($source->title ?: $source->original_filename ?: 'Untitled article'));
        if ($base === '') {
            $base = 'Untitled article';
        }

        return mb_substr($base.' (copy)', 0, 200);
    }

    /**
     * @return array{0:string,1:?string,2:int}
     */
    private function copyStoredFile(ContentSubmission $source): array
    {
        $disk = (string) ($source->disk ?: 'local');
        $path = trim((string) $source->path);
        if ($path === '') {
            return [$disk, '', 0];
        }

        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return [$disk, '', 0];
            }

            $extension = strtolower((string) ($source->extension ?: pathinfo($path, PATHINFO_EXTENSION) ?: 'docx'));
            $dir = trim(str_replace('\\', '/', dirname($path)), '.');
            $next = ($dir !== '' ? $dir.'/' : '').Str::uuid()->toString().'.'.$extension;
            if (! $storage->copy($path, $next)) {
                return [$disk, '', 0];
            }

            $size = (int) ($storage->size($next) ?: $source->size_bytes ?: 0);

            return [$disk, $next, $size];
        } catch (\Throwable $e) {
            report($e);

            return [$disk, '', 0];
        }
    }
}
