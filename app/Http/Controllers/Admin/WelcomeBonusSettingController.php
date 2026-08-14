<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WelcomeBonusSettingController extends Controller
{
    public function toggle(Request $request, WelcomeBonusService $welcomeBonus): RedirectResponse
    {
        if (! Schema::hasTable('welcome_bonus_settings')) {
            return back()->with('error', 'Welcome bonus settings are not available yet. Run migrations.');
        }

        $enabled = ! $welcomeBonus->isEnabled();
        $welcomeBonus->setEnabled($enabled, $request->user()?->id);

        return back()->with(
            'success',
            $enabled
                ? 'Welcome bonus enabled. New advertisers can receive the credit once per place.'
                : 'Welcome bonus disabled. New advertisers will not receive the credit. Existing bonuses stay.'
        );
    }
}
