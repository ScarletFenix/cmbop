<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use App\Models\SiteAnnouncement;
use App\Services\PromotionService;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PromotionController extends Controller
{
    public function index(PromotionService $promotions, WelcomeBonusService $welcomeBonus)
    {
        $stats = $promotions->dashboardStats();

        $announcements = collect();
        $banners = collect();
        $sizes = config('promotions.banner_sizes', []);
        $featuredNotices = config('promotions.featured_notices', []);
        $noticeCounts = [];

        foreach (array_keys($featuredNotices) as $type) {
            $noticeCounts[$type] = ['live' => 0, 'total' => 0];
        }

        // Hostinger often lacks these tables — never 500 the Promotions hub.
        try {
            if (Schema::hasTable('site_announcements')) {
                $announcements = SiteAnnouncement::query()
                    ->latest('id')
                    ->limit(8)
                    ->get();

                foreach (array_keys($featuredNotices) as $type) {
                    $noticeCounts[$type] = [
                        'live' => SiteAnnouncement::query()->active()->where('type', $type)->count(),
                        'total' => SiteAnnouncement::query()->where('type', $type)->count(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Admin promotions hub announcements failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if (Schema::hasTable('ad_banners')) {
                $banners = AdBanner::query()
                    ->latest('id')
                    ->limit(8)
                    ->get();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin promotions hub banners failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $welcomeBonusEnabled = true;
        $welcomeBonusAmount = 20.0;
        try {
            $welcomeBonusEnabled = $welcomeBonus->isEnabled();
            $welcomeBonusAmount = $welcomeBonus->amount();
        } catch (\Throwable $e) {
            Log::warning('Admin promotions hub welcome bonus status failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return view('admin.promotions.index', compact(
            'stats',
            'announcements',
            'banners',
            'sizes',
            'featuredNotices',
            'noticeCounts',
            'welcomeBonusEnabled',
            'welcomeBonusAmount'
        ));
    }
}
