<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Advertiser\AdvertiserDashboardService;

class DashboardController extends Controller
{
    public function __construct(private AdvertiserDashboardService $dashboard) {}

    public function index()
    {
        $payload = $this->dashboard->build(auth()->user());

        return view('advertiser.dashboard', $payload);
    }
}
