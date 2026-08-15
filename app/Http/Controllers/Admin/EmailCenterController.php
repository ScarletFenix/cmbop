<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PlatformMailable;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Support\EmailCatalog;
use App\Support\UserFacingError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmailCenterController extends Controller
{
    public function index(Request $request)
    {
        $stats = EmailLog::dashboardKpis();

        $recentLogs = EmailLog::query()
            ->latest('id')
            ->limit(25)
            ->get();

        $templateStats = EmailLog::query()
            ->selectRaw('template_key, COUNT(*) as sent_count, MAX(sent_at) as last_sent_at')
            ->where('status', EmailLog::STATUS_DELIVERED)
            ->whereNotNull('template_key')
            ->groupBy('template_key')
            ->get()
            ->keyBy('template_key');

        $templates = collect(EmailCatalog::templates())->map(function (array $meta) use ($templateStats) {
            $row = $templateStats->get($meta['key']);
            $meta['last_sent_at'] = $row?->last_sent_at;
            $meta['sent_count'] = (int) ($row?->sent_count ?? 0);

            return $meta;
        })->values();

        $smtp = [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'),
            'encryption' => config('mail.mailers.smtp.scheme') ?: (config('mail.mailers.smtp.port') == 465 ? 'ssl' : 'tls'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'admin_email' => config('mail.admin_email'),
            'queue_connection' => config('queue.default'),
            'configured' => config('mail.default') !== 'log' && filled(config('mail.mailers.smtp.host')),
        ];

        $queue = [
            'connection' => config('queue.default'),
            'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'mail_pending_jobs' => $this->queuedMailJobsCount(),
            'mail_failed_jobs' => $this->failedMailJobsCount(),
        ];

        $failedLogs = EmailLog::failed()->latest('id')->limit(20)->get();

        $settingRows = EmailNotificationSetting::query()->pluck('enabled', 'type');
        $settings = collect(config('email_notifications.types', []))->map(function (array $meta, string $type) use ($settingRows) {
            $default = (bool) ($meta['default_enabled'] ?? true);

            return [
                'type' => $type,
                'name' => $meta['name'] ?? $type,
                'audience' => $meta['audience'] ?? 'user',
                'enabled' => $settingRows->has($type) ? (bool) $settingRows->get($type) : $default,
                'preference' => $meta['preference'] ?? null,
                'framework' => (bool) ($meta['framework'] ?? false),
            ];
        })->values();

        $brand = config('email_notifications.brand', []);

        return view('admin.emails.index', compact(
            'stats',
            'recentLogs',
            'templates',
            'smtp',
            'queue',
            'failedLogs',
            'settings',
            'brand'
        ));
    }

    public function updateSettings(Request $request)
    {
        $types = config('email_notifications.types', []);
        $editable = collect($types)
            ->reject(fn (array $meta) => ! empty($meta['framework']))
            ->keys()
            ->all();

        $rules = ['enabled' => ['required', 'array']];
        foreach ($editable as $type) {
            $rules['enabled.'.$type] = ['required', Rule::in(['0', '1'])];
        }

        $data = $request->validate($rules);

        foreach ($editable as $type) {
            EmailNotificationSetting::updateOrCreate(
                ['type' => $type],
                ['enabled' => (string) $data['enabled'][$type] === '1']
            );
        }

        EmailNotificationSetting::flushCache();

        return back()->with('success', 'Email notification settings saved.');
    }

    public function preview(string $key)
    {
        $template = EmailCatalog::get($key);
        abort_unless($template, 404);

        if ($html = $this->frameworkPreviewHtml($key)) {
            return response($html);
        }

        $mailable = EmailCatalog::makeMailable($key);
        abort_unless($mailable, 404);

        return response($mailable->render());
    }

    public function sendTest(Request $request)
    {
        $adminEmail = (string) $request->user()->email;
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys(EmailCatalog::templates()))],
            'email' => ['required', 'email', Rule::in([$adminEmail])],
        ]);

        $key = $data['template'];
        $template = EmailCatalog::get($key);
        abort_unless($template, 404);

        try {
            if ($html = $this->frameworkPreviewHtml($key)) {
                $subject = $key === 'email_verification'
                    ? 'Verify your email (Test Preview)'
                    : 'Password Reset (Test Preview)';
                Mail::html($html, function ($message) use ($adminEmail, $key, $subject) {
                    $message->to($adminEmail)->subject($subject);
                    if (method_exists($message, 'getSymfonyMessage')) {
                        $message->getSymfonyMessage()->getHeaders()
                            ->addTextHeader('X-Platform-Notification-Type', $key);
                    }
                });
            } else {
                $mailable = EmailCatalog::makeMailable($key);
                abort_unless($mailable, 404);
                if ($mailable instanceof PlatformMailable) {
                    $mailable->forceSend = true;
                    $mailable->skipUserPreference = true;
                    $mailable->dedupeKey = 'email_center_test:'.$key.':'.(string) Str::uuid();
                }
                Mail::to($adminEmail)->sendNow($mailable);
            }

            return back()->with(
                'success',
                'Test email sent to '.$adminEmail.' (synthetic preview — ignores global disable).'
            );
        } catch (\Throwable $e) {
            EmailLog::create([
                'uuid' => (string) Str::uuid(),
                'mailable' => $template['mailable'] ?? null,
                'template_key' => $key,
                'to_email' => $adminEmail,
                'subject' => ($template['name'] ?? $key).' (Test)',
                'status' => EmailLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'attempts' => 1,
                'meta' => ['source' => 'email_center_test'],
            ]);

            return back()->with('error', UserFacingError::message($e, 'Failed to send test email. Please try again.'));
        }
    }

    public function retryFailed(Request $request)
    {
        $data = $request->validate([
            'log_id' => ['nullable', 'integer', 'exists:email_logs,id'],
        ]);

        if (! empty($data['log_id'])) {
            return $this->retryFailedLog((int) $data['log_id']);
        }

        $uuids = $this->mailFailedJobUuids();

        if ($uuids === []) {
            return back()->with('success', 'No failed mail jobs to retry.');
        }

        try {
            Artisan::call('queue:retry', ['id' => $uuids]);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not retry mail jobs. Please try again.'));
        }

        return back()->with('success', 'Retried '.count($uuids).' failed mail job(s). Other failed jobs were left untouched.');
    }

    protected function retryFailedLog(int $logId)
    {
        $log = EmailLog::query()->findOrFail($logId);
        if ($log->status !== EmailLog::STATUS_FAILED) {
            return back()->with('error', 'That email log is not failed.');
        }

        $source = data_get($log->meta, 'source');
        if ($source === 'email_center_test') {
            return $this->retryTestLog($log);
        }

        $uuid = $this->failedJobUuidForLog($log);
        if ($uuid) {
            try {
                Artisan::call('queue:retry', ['id' => [$uuid]]);
            } catch (\Throwable $e) {
                return back()->with('error', UserFacingError::message($e, 'Could not retry the mail job. Please try again.'));
            }

            $log->update([
                'status' => EmailLog::STATUS_PENDING,
                'error' => null,
                'attempts' => max(1, (int) $log->attempts) + 1,
            ]);

            return back()->with('success', 'Re-queued the failed mail job for this log.');
        }

        return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
    }

    protected function retryTestLog(EmailLog $log)
    {
        $key = (string) $log->template_key;
        $template = EmailCatalog::get($key);
        if (! $template) {
            return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
        }

        $adminEmail = (string) request()->user()->email;
        $dedupe = $log->dedupe_key ?: 'email_center_test:'.$key.':retry:'.$log->id;
        $log->update(['dedupe_key' => $dedupe]);

        try {
            if ($html = $this->frameworkPreviewHtml($key)) {
                $subject = $key === 'email_verification'
                    ? 'Verify your email (Test Preview)'
                    : 'Password Reset (Test Preview)';
                Mail::html($html, function ($message) use ($adminEmail, $key, $subject) {
                    $message->to($adminEmail)->subject($subject);
                    if (method_exists($message, 'getSymfonyMessage')) {
                        $message->getSymfonyMessage()->getHeaders()
                            ->addTextHeader('X-Platform-Notification-Type', $key);
                    }
                });
                $log->update([
                    'status' => EmailLog::STATUS_DELIVERED,
                    'error' => null,
                    'to_email' => $adminEmail,
                    'subject' => $subject,
                    'attempts' => max(1, (int) $log->attempts) + 1,
                    'sent_at' => now(),
                ]);
            } else {
                $mailable = EmailCatalog::makeMailable($key);
                abort_unless($mailable, 404);
                if ($mailable instanceof PlatformMailable) {
                    $mailable->forceSend = true;
                    $mailable->skipUserPreference = true;
                    $mailable->dedupeKey = $dedupe;
                }
                Mail::to($adminEmail)->sendNow($mailable);
            }

            return back()->with('success', 'Retried the Email Center test send to '.$adminEmail.'.');
        } catch (\Throwable $e) {
            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'attempts' => max(1, (int) $log->attempts) + 1,
            ]);

            return back()->with('error', UserFacingError::message($e, 'Failed to retry the test email. Please try again.'));
        }
    }

    protected function failedJobUuidForLog(EmailLog $log): ?string
    {
        if (! Schema::hasTable('failed_jobs') || blank($log->mailable)) {
            return null;
        }

        foreach (DB::table('failed_jobs')->where($this->mailJobPayloadConstraint())->get(['uuid', 'payload']) as $job) {
            if (str_contains((string) $job->payload, (string) $log->mailable)) {
                return (string) $job->uuid;
            }
        }

        return null;
    }

    protected function queuedMailJobsCount(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->where($this->mailJobPayloadConstraint())->count();
    }

    protected function failedMailJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->where($this->mailJobPayloadConstraint())->count();
    }

    /**
     * @return list<string>
     */
    protected function mailFailedJobUuids(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->where($this->mailJobPayloadConstraint())
            ->pluck('uuid')
            ->filter()
            ->map(fn ($uuid) => (string) $uuid)
            ->values()
            ->all();
    }

    /**
     * @return \Closure(Builder): void
     */
    protected function mailJobPayloadConstraint(): \Closure
    {
        return function ($q) {
            $q->where('payload', 'like', '%SendQueuedMailable%')
                ->orWhere('payload', 'like', '%App\\\\Mail\\\\%')
                ->orWhere('payload', 'like', '%Illuminate\\\\Mail\\\\%');
        };
    }

    protected function frameworkPreviewHtml(string $key): ?string
    {
        return match ($key) {
            'password_reset' => $this->renderMarkdown('emails.password-reset-preview', [
                'resetUrl' => url('/password/reset/preview-token'),
            ]),
            'email_verification' => $this->renderMarkdown('emails.email-verification-preview', [
                'verifyUrl' => EmailCatalog::previewVerificationUrl(),
            ]),
            default => null,
        };
    }

    protected function renderMarkdown(string $view, array $data = []): string
    {
        return app(Markdown::class)->render($view, $data);
    }
}
