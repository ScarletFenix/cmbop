<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Http\Request;

class ContentModerationController extends Controller
{
    public function index(ContentModerationService $moderation)
    {
        $cfg = $moderation->effectiveConfig();
        $stats = $moderation->adminStats();
        $logs = ContentModerationLog::query()
            ->with('user')
            ->latest('id')
            ->paginate(25);

        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = ContentModerationSetting::getValue('exceptions', []) ?: [];
        $disabledCategories = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $enabledCategories = ContentModerationSetting::getValue('enabled_categories', []) ?: [];

        return view('admin.moderation.index', compact(
            'cfg',
            'stats',
            'logs',
            'extraKeywords',
            'exceptions',
            'disabledCategories',
            'enabledCategories'
        ));
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'confidence_threshold' => ['required', 'integer', 'min:1', 'max:99'],
            'min_word_count' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'block_on_quality_failure' => ['sometimes', 'boolean'],
            'extra_keywords' => ['nullable', 'string'],
            'exceptions' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string'],
        ]);

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

        $allCats = array_keys(config('content_moderation.categories', []));
        $selected = $data['categories'] ?? [];
        $disabled = array_values(array_diff($allCats, $selected));
        $enabled = array_values(array_intersect($allCats, $selected));
        ContentModerationSetting::setValue('disabled_categories', $disabled);
        ContentModerationSetting::setValue('enabled_categories', $enabled);
        ContentModerationSetting::clearCache();

        return back()->with('success', 'Moderation settings saved.');
    }

    public function override(Request $request, ContentModerationLog $log)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $log->update([
            'passed' => true,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'admin_override' => true,
            'overridden_by' => auth()->id(),
            'overridden_at' => now(),
            'admin_notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Submission overridden as approved. Advertiser may resubmit the same link.');
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
}
