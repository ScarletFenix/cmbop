<?php

namespace App\Services\ContentUpload;

use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class ContentUploadService
{
    public function __construct(
        private DocumentTextExtractor $extractor,
        private ContentModerationService $moderation,
    ) {
    }

    public function effectiveConfig(): array
    {
        $base = config('content_upload', []);
        $override = ContentModerationSetting::getValue('upload_config', []) ?: [];

        if (!is_array($override) || $override === []) {
            return $base;
        }

        return array_replace_recursive($base, $override);
    }

    public function schedulingEnabled(): bool
    {
        $cfg = $this->effectiveConfig();

        return (bool) ($cfg['scheduling']['enabled'] ?? true);
    }

    /**
     * Validate, store, extract, and moderate an uploaded article for one placement.
     *
     * @return array{ok:bool, submission?:ContentSubmission, message?:string, title?:string, report?:array}
     */
    public function uploadAndProcess(
        UploadedFile $file,
        User $user,
        int $siteId,
        int $copyIndex = 0,
        ?string $cartKey = null,
        ?ContentSubmission $replace = null,
    ): array {
        $cfg = $this->effectiveConfig();
        $validationError = $this->validateUpload($file, $cfg);
        if ($validationError !== null) {
            return ['ok' => false, 'title' => 'Upload rejected', 'message' => $validationError];
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $disk = (string) ($cfg['disk'] ?? 'local');
        $dir = trim((string) ($cfg['directory'] ?? 'content-uploads'), '/');
        $filename = Str::uuid()->toString() . '.' . $extension;
        $path = $file->storeAs($dir . '/' . $user->id, $filename, $disk);

        if (!$path) {
            return ['ok' => false, 'title' => 'Upload failed', 'message' => 'Unable to store the file. Please try again.'];
        }

        $absolute = Storage::disk($disk)->path($path);
        $extracted = $this->extractor->extract($absolute, $extension);

        if (!$extracted['ok']) {
            Storage::disk($disk)->delete($path);

            return [
                'ok' => false,
                'title' => 'Document processing failed',
                'message' => $extracted['error_message'] ?? 'Unable to process this document.',
                'report' => ['error_code' => $extracted['error_code']],
            ];
        }

        $retentionMonths = max(1, (int) ($cfg['retention_months'] ?? 6));

        if ($replace) {
            $replace->deleteStoredFile();
            $submission = $replace;
            $submission->fill([
                'site_id' => $siteId,
                'copy_index' => $copyIndex,
                'cart_key' => $cartKey,
                'original_filename' => $file->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'mime' => $file->getMimeType(),
                'extension' => $extension,
                'size_bytes' => (int) $file->getSize(),
                'extracted_text' => $extracted['text'],
                'preview_html' => $extracted['html'],
                'word_count' => $extracted['word_count'],
                'moderation_status' => ContentSubmission::STATUS_PROCESSING,
                'expires_at' => now()->addMonths($retentionMonths),
            ]);
            $submission->save();
        } else {
            $submission = ContentSubmission::create([
                'user_id' => $user->id,
                'site_id' => $siteId,
                'copy_index' => $copyIndex,
                'cart_key' => $cartKey,
                'original_filename' => $file->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'mime' => $file->getMimeType(),
                'extension' => $extension,
                'size_bytes' => (int) $file->getSize(),
                'extracted_text' => $extracted['text'],
                'preview_html' => $extracted['html'],
                'word_count' => $extracted['word_count'],
                'moderation_status' => ContentSubmission::STATUS_PROCESSING,
                'publication_mode' => ContentSubmission::MODE_IMMEDIATE,
                'timezone' => $cfg['scheduling']['default_timezone'] ?? 'UTC',
                'wizard_step' => 1,
                'expires_at' => now()->addMonths($retentionMonths),
            ]);
        }

        $scan = $this->moderation->scanExtractedContent(
            text: (string) $extracted['text'],
            html: (string) $extracted['html'],
            sourceLabel: 'upload:' . $submission->id,
            user: $user,
            title: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Article',
        );

        $submission->update([
            'moderation_status' => $scan['passed']
                ? ContentSubmission::STATUS_APPROVED
                : ($scan['status'] === 'error' ? ContentSubmission::STATUS_ERROR : ContentSubmission::STATUS_REJECTED),
            'moderation_log_id' => $scan['log']?->id,
            'scan_token' => $scan['scan_token'],
            'wizard_step' => $scan['passed'] ? max(2, (int) $submission->wizard_step) : 1,
        ]);

        if (!$scan['passed']) {
            return [
                'ok' => false,
                'submission' => $submission->fresh(),
                'title' => $scan['user_title'] ?? 'Article Cannot Be Accepted',
                'message' => $cfg['help']['compliance_reject'] ?? $scan['user_message'],
                'report' => $scan['report'] ?? [],
            ];
        }

        return [
            'ok' => true,
            'submission' => $submission->fresh(),
            'title' => 'Article approved',
            'message' => 'Your document passed content validation.',
            'report' => $scan['report'] ?? [],
        ];
    }

    public function validateUpload(UploadedFile $file, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? $this->effectiveConfig();
        $maxKb = max(100, (int) ($cfg['max_kilobytes'] ?? 5120));
        $allowedExt = array_map('strtolower', $cfg['allowed_extensions'] ?? ['docx']);
        $allowedMimes = $cfg['allowed_mimes'] ?? [];

        if (!$file->isValid()) {
            return 'The upload failed. Please try again.';
        }

        if ($file->getSize() > $maxKb * 1024) {
            return 'File is too large. Maximum size is ' . round($maxKb / 1024, 1) . ' MB.';
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($extension, $allowedExt, true)) {
            return 'Unsupported file type. Allowed: ' . implode(', ', $allowedExt) . '.';
        }

        $mime = (string) ($file->getMimeType() ?: '');
        $guessed = MimeTypes::getDefault()->getMimeTypes($extension);
        $mimeOk = $mime === ''
            || in_array($mime, $allowedMimes, true)
            || in_array($mime, $guessed, true)
            || str_contains($mime, 'word')
            || str_contains($mime, 'pdf')
            || $mime === 'application/octet-stream';

        if (!$mimeOk) {
            return 'File MIME type is not allowed.';
        }

        // Basic malware / executable signature rejection
        $head = @file_get_contents($file->getRealPath(), false, null, 0, 8) ?: '';
        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF")) {
            return 'This file type is not allowed for security reasons.';
        }

        return null;
    }
}
