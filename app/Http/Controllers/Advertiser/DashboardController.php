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
        $user = auth()->user();

        try {
            $payload = $this->dashboard->build($user);

            return view('advertiser.dashboard', $payload);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load your dashboard. Please try again shortly.')
            );

            return view('advertiser.dashboard', $this->dashboard->failedPayload($user));
        }
    }
}
