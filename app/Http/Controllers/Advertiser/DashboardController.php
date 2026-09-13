<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Advertiser\AdvertiserDashboardService;
use App\Support\UserFacingError;

class DashboardController extends Controller
{
    public function __construct(private AdvertiserDashboardService $dashboard) {}

    public function index()
    {
        try {
            $payload = $this->dashboard->build(auth()->user());

            return view('advertiser.dashboard', $payload);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('advertiser.catalog')
                ->with('error', UserFacingError::message($e, 'We could not load your dashboard. Please try again shortly.'));
        }
    }
}
