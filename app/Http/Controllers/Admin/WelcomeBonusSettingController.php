<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WelcomeBonusSetting;
use App\Services\ActivityLogger;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WelcomeBonusSettingController extends Controller
{
    public function toggle(Request $request, WelcomeBonusService $welcomeBonus): RedirectResponse
    {
        try {
            WelcomeBonusSetting::ensureTable();
            if (! Schema::hasTable('welcome_bonus_settings')) {
                return back()->with('error', 'Welcome bonus settings are not available yet. Run migrations.');
            }
        } catch (\Throwable) {
            return back()->with('error', 'Welcome bonus settings are not available yet. Run migrations.');
        }

        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = $request->boolean('enabled');
        $already = $welcomeBonus->isEnabled() === $enabled;

        try {
            $welcomeBonus->setEnabled($enabled, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update welcome bonus setting: '.$e->getMessage());

            return back()->with('error', 'Could not update the welcome bonus. Please try again.');
        }

        if ($welcomeBonus->isEnabled() !== $enabled) {
            return back()->with('error', 'Could not update the welcome bonus. Please try again.');
        }

        if ($already) {
            return back()->with('success', $this->toggleSuccessMessage($welcomeBonus, $enabled));
        }

        ActivityLogger::tryLog(
            'welcome_bonus.toggled',
            ($request->user()?->name ?? 'Admin').' '.($enabled ? 'enabled' : 'disabled').' the welcome bonus',
            null,
            ['enabled' => $enabled]
        );

        return back()->with('success', $this->toggleSuccessMessage($welcomeBonus, $enabled));
    }

    public function updateAmount(Request $request, WelcomeBonusService $welcomeBonus): RedirectResponse
    {
        try {
            WelcomeBonusSetting::ensureTable();
            if (! Schema::hasTable('welcome_bonus_settings')) {
                return back()->with('error', 'Welcome bonus settings are not available yet. Run migrations.');
            }
        } catch (\Throwable) {
            return back()->with('error', 'Welcome bonus settings are not available yet. Run migrations.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:'.WelcomeBonusSetting::maxAmount()],
        ]);
        $amount = round((float) $data['amount'], 2);
        $already = abs($welcomeBonus->amount() - $amount) <= 0.001;

        try {
            $welcomeBonus->setAmount($amount, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update welcome bonus amount: '.$e->getMessage());

            return back()->with('error', 'Could not update the welcome bonus amount. Please try again.');
        }

        if (abs($welcomeBonus->amount() - $amount) > 0.001) {
            return back()->with('error', 'Could not update the welcome bonus amount. Please try again.');
        }

        if ($already) {
            return back()->with('success', $this->amountSuccessMessage($welcomeBonus, $amount));
        }

        ActivityLogger::tryLog(
            'welcome_bonus.amount_changed',
            ($request->user()?->name ?? 'Admin').' set the welcome bonus to €'.number_format($amount, 2),
            null,
            ['amount' => $amount]
        );

        return back()->with('success', $this->amountSuccessMessage($welcomeBonus, $amount));
    }

    private function toggleSuccessMessage(WelcomeBonusService $welcomeBonus, bool $enabled): string
    {
        if (! $enabled) {
            return 'Welcome bonus disabled. New advertisers will not receive the credit. Existing bonuses stay.';
        }

        if ($welcomeBonus->canGrant()) {
            return 'Welcome bonus enabled. New advertisers can receive the credit once per place.';
        }

        if ($welcomeBonus->amount() <= 0) {
            return 'Welcome bonus enabled. Amount is €0 — new advertisers will not receive credit.';
        }

        return 'Welcome bonus enabled. New advertisers will not receive credit until grant storage is ready.';
    }

    private function amountSuccessMessage(WelcomeBonusService $welcomeBonus, float $amount): string
    {
        $base = 'Welcome bonus amount set to €'.number_format($amount, 2).'.';

        if (! $welcomeBonus->isEnabled()) {
            return $base.' Bonus is still disabled — new advertisers will not receive it. Existing bonuses stay.';
        }

        if ($welcomeBonus->canGrant()) {
            return $base.' New advertisers receive this amount. Existing bonuses stay.';
        }

        if ($amount <= 0) {
            return $base.' New advertisers will not receive credit. Existing bonuses stay.';
        }

        return $base.' New advertisers will not receive credit until grant storage is ready. Existing bonuses stay.';
    }
}
