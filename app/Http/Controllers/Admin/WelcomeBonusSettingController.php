<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WelcomeBonusSettingController extends Controller
{
    public function toggle(Request $request, WelcomeBonusService $welcomeBonus): RedirectResponse
    {
        if (! Schema::hasTable('welcome_bonus_settings')) {
            return back()->with('error', 'Welcome bonus settings are not available yet. Run migrations.');
        }

        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = $request->boolean('enabled');

        try {
            $welcomeBonus->setEnabled($enabled, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update welcome bonus setting: '.$e->getMessage());

            return back()->with('error', 'Could not update the welcome bonus. Please try again.');
        }

        if ($welcomeBonus->isEnabled() !== $enabled) {
            return back()->with('error', 'Could not update the welcome bonus. Please try again.');
        }

        return back()->with(
            'success',
            $enabled
                ? 'Welcome bonus enabled. New advertisers can receive the credit once per place.'
                : 'Welcome bonus disabled. New advertisers will not receive the credit. Existing bonuses stay.'
        );
    }
}
