<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailNotificationSetting;
use App\Models\User;
use App\Models\WeeklyNewSitesRecipient;
use App\Models\WeeklyNewSitesRun;
use App\Models\WeeklyNewSitesSend;
use App\Services\ActivityLogger;
use App\Services\WeeklyNewSites\AttributionReport;
use App\Services\WeeklyNewSites\DigestDispatcher;
use App\Services\WeeklyNewSites\MatchSelector;
use App\Services\WeeklyNewSites\PermissionGate;
use App\Services\WeeklyNewSites\Watermark;
use Illuminate\Http\Request;

class WeeklyNewSitesDigestController extends Controller
{
    public function index(
        AttributionReport $report,
        DigestDispatcher $dispatcher,
        Watermark $watermark,
    ) {
        $enabled = EmailNotificationSetting::isEnabled(PermissionGate::TYPE);
        $runs = WeeklyNewSitesRun::tableReady()
            ? WeeklyNewSitesRun::query()->orderByDesc('id')->limit(20)->get()
            : collect();
        $recipients = WeeklyNewSitesRecipient::tableReady()
            ? WeeklyNewSitesRecipient::query()->orderByDesc('id')->limit(50)->get()
            : collect();
        $sends = WeeklyNewSitesSend::tableReady()
            ? WeeklyNewSitesSend::query()->orderByDesc('id')->limit(50)->get()
            : collect();

        return view('admin.weekly-new-sites.index', [
            'enabled' => $enabled,
            'tablesReady' => $dispatcher->tablesReady(),
            'watermark' => $watermark->establishedAt(),
            'weekKey' => $dispatcher->weekKey(),
            'timezone' => (string) config('weekly_new_sites_digest.timezone', 'Asia/Karachi'),
            'time' => (string) config('weekly_new_sites_digest.time', '10:00'),
            'minSites' => (int) config('weekly_new_sites_digest.min_sites', 2),
            'maxSites' => (int) config('weekly_new_sites_digest.max_sites', 5),
            'report' => $report->summary(),
            'runs' => $runs,
            'recipients' => $recipients,
            'sends' => $sends,
        ]);
    }

    public function toggle(Request $request)
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');

        EmailNotificationSetting::query()->updateOrCreate(
            ['type' => PermissionGate::TYPE],
            ['enabled' => $enabled]
        );
        EmailNotificationSetting::flushCache(PermissionGate::TYPE);

        ActivityLogger::tryLog(
            'weekly_new_sites.toggled',
            ($request->user()?->name ?? 'Admin').' '.($enabled ? 'enabled' : 'disabled').' the weekly new-site digest',
            null,
            ['enabled' => $enabled]
        );

        return back()->with('success', $enabled
            ? 'Weekly new-site digest is on. The Monday schedule can queue mail.'
            : 'Weekly new-site digest is off. Queued messages will also be dropped at send time.');
    }

    public function dryRun(DigestDispatcher $dispatcher)
    {
        if (! $dispatcher->tablesReady()) {
            return back()->with('error', 'Digest tables are not installed yet.');
        }

        $result = $dispatcher->run(true, (int) config('weekly_new_sites_digest.recipient_limit', 500));

        return back()->with('success', sprintf(
            'Dry run for week %s: %d would queue, %d thin, %d permission, %d other. No mail was sent.',
            $result['week_key'],
            $result['queued'],
            $result['skipped_thin'],
            $result['skipped_permission'],
            $result['skipped_other']
        ));
    }

    public function preview(Request $request, MatchSelector $matches)
    {
        $email = trim((string) $request->input('email', ''));
        $user = $email !== '' ? User::query()->where('email', $email)->first() : null;
        $cards = collect();
        $pairs = [];
        $permission = ['allowed' => false, 'basis' => null, 'reason' => 'no_user'];

        if ($user) {
            $permission = app(PermissionGate::class)->inspect($user);
            $cards = $matches->matches($user);
            $pairs = $matches->distinctPairs($cards);
        }

        return view('admin.weekly-new-sites.preview', [
            'email' => $email,
            'user' => $user,
            'permission' => $permission,
            'cards' => $cards,
            'pairs' => $pairs,
        ]);
    }
}
