<?php

namespace App\Http\Controllers;

use App\Models\EmailNotificationPreference;
use App\Models\WeeklyNewSitesClick;
use App\Models\WeeklyNewSitesInterest;
use App\Models\WeeklyNewSitesToken;
use App\Services\WeeklyNewSites\InterestSync;
use App\Support\WeeklyNewSitesLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesMailController extends Controller
{
    public function __invoke(Request $request, string $token, InterestSync $interests)
    {
        if (! $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params())) {
            abort(403, 'This link is invalid or has expired.');
        }

        $record = WeeklyNewSitesLink::find($token);
        if (! $record || $record->isExpired()) {
            abort(403, 'This link is invalid or has expired.');
        }

        $user = $record->user;
        if (! $user) {
            abort(403, 'This link is invalid or has expired.');
        }

        return match ($record->purpose) {
            WeeklyNewSitesToken::PURPOSE_CLICK => $this->handleClick($record),
            WeeklyNewSitesToken::PURPOSE_UNSUB => $this->handleUnsub($request, $record),
            WeeklyNewSitesToken::PURPOSE_PREFERENCES => $this->handlePreferences($request, $record, $interests),
            default => abort(403, 'This link is invalid or has expired.'),
        };
    }

    private function handleClick(WeeklyNewSitesToken $record)
    {
        $payload = is_array($record->payload) ? $record->payload : [];
        $path = (string) ($payload['path'] ?? '/advertiser/catalog');
        if (! WeeklyNewSitesLink::isSafeInternalPath($path)) {
            $path = '/advertiser/catalog';
        }

        if (WeeklyNewSitesClick::tableReady()) {
            WeeklyNewSitesClick::query()->create([
                'user_id' => $record->user_id,
                'site_id' => isset($payload['site_id']) ? (int) $payload['site_id'] : null,
                'recipient_id' => isset($payload['recipient_id']) ? (int) $payload['recipient_id'] : null,
                'kind' => (string) ($payload['kind'] ?? WeeklyNewSitesClick::KIND_SITE),
                'destination_path' => $path,
                'clicked_at' => now(),
            ]);
        }

        return redirect($path);
    }

    private function handleUnsub(Request $request, WeeklyNewSitesToken $record)
    {
        if ($request->isMethod('get') && ! $this->isOneClick($request)) {
            return view('emails.advertiser.weekly-new-sites-unsub-confirm', [
                'confirmAction' => $request->getRequestUri(),
                'brand' => function_exists('mail_brand_name') ? mail_brand_name() : 'SEOLinkBuildings',
            ]);
        }

        $this->disableTopic($record->user_id);
        $record->forceFill(['used_at' => $record->used_at ?? now()])->save();

        if ($this->isOneClick($request)) {
            return response('', 200);
        }

        return view('emails.advertiser.weekly-new-sites-unsubscribed', [
            'brand' => function_exists('mail_brand_name') ? mail_brand_name() : 'SEOLinkBuildings',
        ]);
    }

    private function handlePreferences(Request $request, WeeklyNewSitesToken $record, InterestSync $interests)
    {
        $user = $record->user;
        $interests->syncInferred($user);

        if ($request->isMethod('post')) {
            $this->savePreferences($request, $user);
            $enabled = $request->boolean('digest_enabled', true);
            EmailNotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'preference_key' => 'weekly_new_sites'],
                ['enabled' => $enabled]
            );

            return redirect($request->getRequestUri())->with('success', 'Preferences saved.');
        }

        $pairs = WeeklyNewSitesInterest::tableReady()
            ? WeeklyNewSitesInterest::query()->where('user_id', $user->id)->orderBy('niche')->get()
            : collect();
        $topicOn = EmailNotificationPreference::allows($user, 'weekly_new_sites');

        return view('advertiser.weekly-new-sites-preferences', [
            'pairs' => $pairs,
            'topicOn' => $topicOn,
            'formAction' => $request->getRequestUri(),
            'signed' => true,
        ]);
    }

    private function savePreferences(Request $request, $user): void
    {
        if (! WeeklyNewSitesInterest::tableReady()) {
            return;
        }

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
            app(InterestSync::class)->upsertManual($user, $niche, $country, true);
        }
    }

    private function disableTopic(int $userId): void
    {
        if (! Schema::hasTable('email_notification_preferences')) {
            return;
        }

        EmailNotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'preference_key' => 'weekly_new_sites'],
            ['enabled' => false]
        );
    }

    private function isOneClick(Request $request): bool
    {
        return $request->input('List-Unsubscribe') === 'One-Click'
            || $request->header('List-Unsubscribe') === 'One-Click'
            || $request->expectsJson();
    }
}
