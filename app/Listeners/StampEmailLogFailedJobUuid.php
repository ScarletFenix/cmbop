<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Support\EmailCatalog;
use App\Support\MailJobPayload;
use Illuminate\Queue\Events\JobFailed;

class StampEmailLogFailedJobUuid
{
    public function handle(JobFailed $event): void
    {
        $job = $event->job;
        if (! is_object($job) || ! method_exists($job, 'uuid') || ! method_exists($job, 'getRawBody')) {
            return;
        }

        try {
            $uuid = $job->uuid();
            $payload = (string) $job->getRawBody();
        } catch (\Throwable) {
            return;
        }

        if (! is_string($uuid) || $uuid === '' || ! MailJobPayload::isQueuedMailable($payload)) {
            return;
        }

        $matches = EmailLog::query()
            ->whereIn('status', [EmailLog::STATUS_FAILED, EmailLog::STATUS_PENDING])
            ->where('updated_at', '>=', now()->subHour())
            ->latest('id')
            ->limit(40)
            ->get()
            ->filter(fn (EmailLog $log) => $this->payloadMatchesLog($payload, $log));

        if ($matches->count() !== 1) {
            return;
        }

        $log = $matches->first();
        $meta = (array) $log->meta;
        if (($meta['failed_job_uuid'] ?? null) === $uuid) {
            return;
        }

        $meta['failed_job_uuid'] = $uuid;
        $log->update(['meta' => $meta]);
    }

    protected function payloadMatchesLog(string $payload, EmailLog $log): bool
    {
        $catalog = EmailCatalog::get((string) $log->template_key) ?? [];
        $class = (string) ($log->mailable ?: ($catalog['mailable'] ?? ''));
        if ($class !== '' && ! MailJobPayload::containsMailable($payload, $class)) {
            return false;
        }

        return MailJobPayload::containsToken($payload, (string) $log->to_email)
            || MailJobPayload::containsToken($payload, (string) $log->dedupe_key);
    }
}
