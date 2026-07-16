<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use App\Models\SiteAnnouncement;
use App\Services\PromotionService;

class PromotionController extends Controller
{
    public function index(PromotionService $promotions)
    {
        $stats = $promotions->dashboardStats();

        $announcements = SiteAnnouncement::query()
            ->latest('id')
            ->limit(8)
            ->get();

        $banners = AdBanner::query()
            ->latest('id')
            ->limit(8)
            ->get();

        $sizes = config('promotions.banner_sizes', []);

        return view('admin.promotions.index', compact('stats', 'announcements', 'banners', 'sizes'));
    }
}
