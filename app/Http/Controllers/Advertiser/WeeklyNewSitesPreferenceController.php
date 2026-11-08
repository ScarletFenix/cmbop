<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\EmailNotificationPreference;
use App\Models\WeeklyNewSitesInterest;
use App\Services\WeeklyNewSites\InterestSync;
use Illuminate\Http\Request;

class WeeklyNewSitesPreferenceController extends Controller
{
    public function edit(Request $request, InterestSync $interests)
    {
        $user = $request->user();
        $interests->syncInferred($user);
        $pairs = WeeklyNewSitesInterest::tableReady()
            ? WeeklyNewSitesInterest::query()->where('user_id', $user->id)->orderBy('niche')->get()
            : collect();

        return view('advertiser.weekly-new-sites-preferences', [
            'pairs' => $pairs,
            'topicOn' => EmailNotificationPreference::allows($user, 'weekly_new_sites'),
            'formAction' => route('advertiser.weekly-new-sites.update'),
            'signed' => false,
        ]);
    }

    public function update(Request $request, InterestSync $interests)
    {
        $user = $request->user();
        $enabled = $request->boolean('digest_enabled', true);
        EmailNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'preference_key' => 'weekly_new_sites'],
            ['enabled' => $enabled]
        );

        if (WeeklyNewSitesInterest::tableReady()) {
            $enabledIds = array_map('intval', (array) $request->input('enabled_ids', []));
            WeeklyNewSitesInterest::query()
                ->where('user_id', $user->id)
                ->get()
                ->each(function (WeeklyNewSitesInterest $row) use ($enabledIds) {
                    $row->enabled = in_array((int) $row->id, $enabledIds, true);
                    $row->save();
                });

            $niche = trim((string) $request->input('add_niche', ''));
            $country = strtolower(trim((string) $request->input('add_country', '')));
            if ($niche !== '' && $country !== '' && preg_match('/^[a-z]{2}$/', $country)) {
                $interests->upsertManual($user, $niche, $country, true);
            }
        }

        return back()->with('success', 'New-site digest preferences saved.');
    }
}
