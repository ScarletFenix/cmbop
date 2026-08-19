<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\AudienceInventoryService;
use Illuminate\Http\Request;

class AudienceController extends Controller
{
    public function index(Request $request, AudienceInventoryService $inventory)
    {
        $audienceKey = $this->resolvedAudienceKey($request->get('tab', 'advertisers'));
        $tab = AudienceInventoryService::tabForAudienceKey($audienceKey);
        $search = search_text($request->get('q'));
        $filters = $this->inventoryFilters($request);
        $users = $inventory->paginate($audienceKey, $search !== '' ? $search : null, 25, $filters);
        try {
            $stats = $inventory->stats();
        } catch (\Throwable) {
            $stats = [];
        }
        $campaignAudience = $audienceKey;
        $filterQuery = $this->filterQuery($search, $filters);

        return view('admin.audiences.index', compact(
            'tab',
            'users',
            'stats',
            'search',
            'campaignAudience',
            'filters',
            'filterQuery'
        ));
    }

    public function export(Request $request, AudienceInventoryService $inventory)
    {
        $audienceKey = $this->resolvedAudienceKey($request->get('audience', 'advertisers'));
        $search = search_text($request->get('q'));
        $filters = $this->inventoryFilters($request);

        $query = $inventory->prepareExportQuery($audienceKey, $search !== '' ? $search : null, $filters);
        if ($query === null) {
            return $inventory->exportCsv($audienceKey, $search !== '' ? $search : null, $filters);
        }

        try {
            $rowCount = $inventory->exportMatchCount($audienceKey, $search !== '' ? $search : null, $filters);
        } catch (\Throwable) {
            $rowCount = 0;
        }

        ActivityLogger::tryLog(
            'audience.exported',
            'Exported '.AudienceInventoryService::label($audienceKey).' audience CSV.',
            null,
            [
                'audience' => $audienceKey,
                'search' => $search,
                'filters' => $filters,
                'rows_exported' => min($rowCount, AudienceInventoryService::EXPORT_LIMIT),
                'truncated' => $rowCount > AudienceInventoryService::EXPORT_LIMIT,
            ]
        );

        return $inventory->exportCsv($audienceKey, $search !== '' ? $search : null, $filters, $query);
    }

    protected function resolvedAudienceKey(mixed $raw): string
    {
        $key = AudienceInventoryService::normalizeAudienceKey(search_text($raw));

        if (! AudienceInventoryService::isListableKey($key)) {
            return AudienceInventoryService::AUDIENCE_ADVERTISERS;
        }

        return $key;
    }

    /**
     * @return array{
     *     verified: string,
     *     registered_from: string,
     *     registered_to: string,
     *     country: string,
     *     marketing: string,
     *     exclude_dual_role: bool,
     *     sort: string,
     *     dir: string
     * }
     */
    protected function inventoryFilters(Request $request): array
    {
        $verified = search_text($request->get('verified'));
        if (! in_array($verified, ['all', 'yes', 'no'], true)) {
            $verified = 'all';
        }

        $marketing = search_text($request->get('marketing'));
        if (! in_array($marketing, ['all', 'opted_in', 'opted_out'], true)) {
            $marketing = 'all';
        }

        $sort = search_text($request->get('sort'));
        if (! in_array($sort, ['name', 'registered'], true)) {
            $sort = 'name';
        }

        $dir = search_text($request->get('dir'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $from = search_text($request->get('registered_from'));
        $to = search_text($request->get('registered_to'));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            $from = '';
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            $to = '';
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'verified' => $verified,
            'registered_from' => $from,
            'registered_to' => $to,
            'country' => mb_substr(search_text($request->get('country')), 0, 64),
            'marketing' => $marketing,
            'exclude_dual_role' => $request->boolean('exclude_dual_role'),
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function filterQuery(string $search, array $filters): array
    {
        return array_filter([
            'q' => $search !== '' ? $search : null,
            'verified' => ($filters['verified'] ?? 'all') !== 'all' ? $filters['verified'] : null,
            'registered_from' => $filters['registered_from'] ?: null,
            'registered_to' => $filters['registered_to'] ?: null,
            'country' => $filters['country'] ?: null,
            'marketing' => ($filters['marketing'] ?? 'all') !== 'all' ? $filters['marketing'] : null,
            'exclude_dual_role' => ! empty($filters['exclude_dual_role']) ? 1 : null,
            'sort' => ($filters['sort'] ?? 'name') !== 'name' ? $filters['sort'] : null,
            'dir' => ($filters['dir'] ?? 'asc') !== 'asc' ? $filters['dir'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
