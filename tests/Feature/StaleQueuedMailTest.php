<?php

namespace Tests\Feature;

use App\Mail\PlatformMailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A queue that sat unattended for days holds news nobody wants any more. When
 * something finally consumes it, "your order moved to review" arriving for
 * something that happened on Tuesday is worse than never arriving.
 */
class StaleQueuedMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_mail_is_delivered(): void
    {
        $mailable = new StaleProbeMail;

        $this->assertNotNull($mailable->send(app('mailer')));
    }

    public function test_mail_queued_before_the_cutoff_is_dropped(): void
    {
        config(['email_notifications.max_age_hours' => 24]);

        $mailable = new StaleProbeMail;
        $mailable->queuedAt = Carbon::now()->subHours(30)->toIso8601String();

        $this->assertNull($mailable->send(app('mailer')));
    }

    public function test_mail_inside_the_window_still_goes_out(): void
    {
        config(['email_notifications.max_age_hours' => 24]);

        $mailable = new StaleProbeMail;
        $mailable->queuedAt = Carbon::now()->subHours(6)->toIso8601String();

        $this->assertNotNull($mailable->send(app('mailer')));
    }

    public function test_the_cutoff_can_be_disabled(): void
    {
        config(['email_notifications.max_age_hours' => 0]);

        $mailable = new StaleProbeMail;
        $mailable->queuedAt = Carbon::now()->subYear()->toIso8601String();

        $this->assertNotNull($mailable->send(app('mailer')));
    }

    public function test_mail_queued_before_this_change_is_not_thrown_away(): void
    {
        config(['email_notifications.max_age_hours' => 24]);

        // Jobs already on the queue were serialised without the timestamp, so
        // the guard has to fail open rather than silently bin the backlog.
        $mailable = new StaleProbeMail;
        $mailable->queuedAt = null;

        $this->assertNotNull($mailable->send(app('mailer')));
    }

    public function test_queued_at_is_stamped_when_the_mailable_is_built(): void
    {
        $mailable = new StaleProbeMail;

        $this->assertNotNull($mailable->queuedAt);
        $this->assertTrue(Carbon::parse($mailable->queuedAt)->greaterThan(Carbon::now()->subMinute()));
    }
}

class StaleProbeMail extends PlatformMailable
{
    public function __construct()
    {
        parent::__construct();
        $this->to('stale-probe@example.com');
    }

    public function build(): self
    {
        return $this->subject('Stale probe')->html('<p>probe</p>');
    }
}
