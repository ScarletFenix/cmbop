<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AudienceInventoryService;
use Illuminate\Http\Request;

class AudienceController extends Controller
{
    public function index(Request $request, AudienceInventoryService $inventory)
    {
        $audienceKey = $this->resolvedAudienceKey($request->get('tab', 'advertisers'));
        $tab = AudienceInventoryService::tabForAudienceKey($audienceKey);

        $search = search_text($request->get('q'));
        $users = $inventory->paginate($audienceKey, $search !== '' ? $search : null);
        $stats = $inventory->stats();
        $campaignAudience = $audienceKey;

        return view('admin.audiences.index', compact('tab', 'users', 'stats', 'search', 'campaignAudience'));
    }

    public function export(Request $request, AudienceInventoryService $inventory)
    {
        $audienceKey = $this->resolvedAudienceKey($request->get('audience', 'advertisers'));

        return $inventory->exportCsv($audienceKey);
    }

    protected function resolvedAudienceKey(mixed $raw): string
    {
        $key = AudienceInventoryService::normalizeAudienceKey(search_text($raw));

        if (! AudienceInventoryService::isListableKey($key)) {
            return AudienceInventoryService::AUDIENCE_ADVERTISERS;
        }

        return $key;
    }
}
