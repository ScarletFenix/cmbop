<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Services\ActivityLogger;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ContentUploadService;
use App\Support\PhpIniSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContentModerationController extends Controller
{
    public function index(Request $request, ContentModerationService $moderation, ContentUploadService $uploads): View
    {
        $cfg = $moderation->effectiveConfig();
        $uploadCfg = $uploads->effectiveConfig();
        $stats = $moderation->adminStats();

        $status = strtolower(trim(scalar_text($request->query('status', 'all'))));
        if (! in_array($status, ['all', 'approved', 'rejected', 'error', 'skipped', 'overridden'], true)) {
            $status = 'all';
        }
        $search = search_text($request->query('q'));
        $category = strtolower(trim(scalar_text($request->query('category', 'all'))));
        $from = scalar_text($request->query('from', ''));
        $to = scalar_text($request->query('to', ''));

        $query = ContentModerationLog::query()
            ->with(['user:id,name,email', 'submission'])
            ->latest('id');

        if ($status === 'approved') {
            $query->where('status', ContentModerationLog::STATUS_APPROVED)
                ->notSkipped()
                ->where('admin_override', false);
        } elseif ($status === 'rejected') {
            $query->where('status', ContentModerationLog::STATUS_REJECTED);
        } elseif ($status === 'error') {
            $query->where('status', ContentModerationLog::STATUS_ERROR);
        } elseif ($status === 'skipped') {
            $query->skipped();
        } elseif ($status === 'overridden') {
            $query->where('admin_override', true);
        }

        if ($search !== '') {
            $like = like_contains($search);
            $query->where(function ($q) use ($like, $search) {
                $q->where('document_url', 'like', $like)
                    ->orWhere('detected_category', 'like', $like)
                    ->orWhere('error_message', 'like', $like)
                    ->orWhereHas('user', function ($u) use ($like) {
                        $u->where('email', 'like', $like)->orWhere('name', 'like', $like);
                    });
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('content_submission_id', (int) $search);
                }
            });
        }

        if ($category !== '' && $category !== 'all') {
            $query->where('detected_category', $category);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->whereDate('created_at', '>=', $from);
        } else {
            $from = '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->whereDate('created_at', '<=', $to);
        } else {
            $to = '';
        }

        $page = (int) scalar_text($request->query('page', 1));
        if ($page < 1) {
            $page = 1;
        }
        $logs = $query->paginate(25, ['*'], 'page', $page)->withQueryString();

        $phpUploadMaxKb = PhpIniSize::uploadMaxKilobytes();
        $articleUploadMaxKb = $uploads->effectiveMaxKilobytes($uploadCfg);
        $phpBlocksArticleUploads = $phpUploadMaxKb < $articleUploadMaxKb;

        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = ContentModerationSetting::getValue('exceptions', []) ?: [];
        $disabledCategories = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $enabledCategories = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
        $activeCategories = $moderation->activeCategories();
        $builtinExceptions = $this->builtinExceptionPhrases();

        return view('admin.moderation.index', compact(
            'cfg',
            'activeCategories',
            'uploadCfg',
            'stats',
            'logs',
            'extraKeywords',
            'exceptions',
            'disabledCategories',
            'enabledCategories',
            'phpUploadMaxKb',
            'articleUploadMaxKb',
            'phpBlocksArticleUploads',
            'status',
            'search',
            'category',
            'from',
            'to',
            'builtinExceptions',
        ));
    }

    public function show(ContentModerationLog $log, ContentModerationService $moderation): View
    {
        $log->load(['user:id,name,email', 'submission.user:id,name,email', 'overrider:id,name,email']);

        return view('admin.moderation.show', [
            'log' => $log,
            'submission' => $moderation->submissionForLog($log),
            'report' => $moderation->publicReport($log),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $allCats = array_keys(config('content_moderation.categories', []));
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'confidence_threshold' => ['required', 'integer', 'min:1', 'max:99'],
            'min_word_count' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'block_on_quality_failure' => ['sometimes', 'boolean'],
            'extra_keywords' => ['nullable', 'string'],
            'exceptions' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in($allCats)],
            'allowed_extensions' => ['nullable', 'string'],
            'max_kilobytes' => ['nullable', 'integer', 'min:10240', 'max:10240'],
            'scheduling_enabled' => ['sometimes', 'boolean'],
            'uploads_enabled' => ['sometimes', 'boolean'],
            'require_same_language' => ['sometimes', 'boolean'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'min_uniqueness' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $wasEnabled = (bool) ((ContentModerationSetting::getValue('config_override', []) ?: [])['enabled']
            ?? config('content_moderation.enabled', true));
        $previousDisabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];

        $override = ContentModerationSetting::getValue('config_override', []) ?: [];
        $override['enabled'] = $request->boolean('enabled');
        $override['confidence_threshold'] = (int) $data['confidence_threshold'];
        $override['quality'] = $override['quality'] ?? config('content_moderation.quality', []);
        $override['quality']['min_word_count'] = (int) ($data['min_word_count'] ?? 500);
        $override['quality']['block_on_quality_failure'] = $request->boolean('block_on_quality_failure');

        ContentModerationSetting::setValue('config_override', $override);

        $keywords = $this->linesToArray($data['extra_keywords'] ?? '');
        $exceptions = $this->linesToArray($data['exceptions'] ?? '');
        ContentModerationSetting::setValue('extra_keywords', $keywords);
        ContentModerationSetting::setValue('exceptions', $exceptions);

        $selected = $data['categories'] ?? [];
        $disabled = array_values(array_diff($allCats, $selected));
        $enabled = array_values(array_intersect($allCats, $selected));
        ContentModerationSetting::setValue('disabled_categories', $disabled);
        ContentModerationSetting::setValue('enabled_categories', $enabled);

        $uploadOverride = ContentModerationSetting::getValue('upload_config', []) ?: [];
        $uploadOverride['allowed_extensions'] = ['docx'];
        $uploadOverride['preferred_extension'] = 'docx';
        $uploadOverride['enabled'] = $request->boolean('uploads_enabled');
        $uploadOverride['max_kilobytes'] = ContentUploadService::MAX_KILOBYTES;
        $uploadOverride['retention_months'] = (int) ($data['retention_months'] ?? 6);
        $uploadOverride['scheduling'] = $uploadOverride['scheduling'] ?? config('content_upload.scheduling', []);
        $uploadOverride['scheduling']['enabled'] = $request->boolean('scheduling_enabled');
        $uploadOverride['placement'] = $uploadOverride['placement'] ?? config('content_upload.placement', []);
        $uploadOverride['placement']['require_same_language'] = $request->boolean('require_same_language');
        $uploadOverride['evaluation'] = $uploadOverride['evaluation'] ?? config('content_upload.evaluation', []);
        $uploadOverride['evaluation']['min_uniqueness'] = (int) ($data['min_uniqueness'] ?? 50);
        ContentModerationSetting::setValue('upload_config', $uploadOverride);

        ContentModerationSetting::clearCache();

        try {
            ActivityLogger::log(
                'moderation.settings_updated',
                ($request->user()?->name ?? 'Admin').' updated content moderation settings',
                null,
                [
                    'enabled' => $request->boolean('enabled'),
                    'was_enabled' => $wasEnabled,
                    'confidence_threshold' => (int) $data['confidence_threshold'],
                    'disabled_categories' => $disabled,
                    'previous_disabled_categories' => is_array($previousDisabled) ? array_values($previousDisabled) : [],
                    'extra_keyword_count' => count($keywords),
                    'uploads_enabled' => $request->boolean('uploads_enabled'),
                ]
            );
        } catch (\Throwable) {
        }

        return back()->with('success', 'Moderation and content upload settings saved.');
    }

    public function override(Request $request, ContentModerationLog $log, ContentModerationService $moderation): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $result = $moderation->applyAdminOverride($log, $request->user(), trim($data['notes']));

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function revert(Request $request, ContentModerationLog $log, ContentModerationService $moderation): RedirectResponse
    {
        $result = $moderation->revertAdminOverride($log, $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * @return array<int, string>
     */
    protected function linesToArray(string $text): array
    {
        $parts = preg_split('/[\r\n,]+/', $text) ?: [];
        $parts = array_map(fn ($p) => trim($p), $parts);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @return list<string>
     */
    protected function builtinExceptionPhrases(): array
    {
        $out = [];
        foreach (config('content_moderation.exceptions', []) as $key => $value) {
            if (is_int($key) && is_string($value) && trim($value) !== '') {
                $out[] = $value;
            } elseif (is_string($key) && is_array($value)) {
                foreach ($value as $phrase) {
                    if (is_string($phrase) && trim($phrase) !== '') {
                        $out[] = $phrase;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }
}
