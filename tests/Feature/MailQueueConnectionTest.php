<?php

namespace Tests\Feature;

use App\Mail\PlatformMailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class MailQueueConnectionTest extends TestCase
{
    public function test_mail_queue_connection_follows_app_queue_by_default(): void
    {
        $config = file_get_contents(config_path('email_notifications.php'));

        // Without an explicit override, platform mail must ride the app queue so
        // checkout does not block on SMTP.
        $this->assertStringContainsString(
            "env('MAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync'))",
            $config
        );
    }

    public function test_env_example_does_not_pin_mail_to_sync(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString('MAIL_QUEUE_CONNECTION=sync', $env);
        $this->assertStringContainsString('MAIL_QUEUE_CONNECTION=', $env);
    }

    public function test_platform_mailables_are_queueable(): void
    {
        $this->assertTrue(
            is_subclass_of(PlatformMailable::class, ShouldQueue::class),
            'PlatformMailable must implement ShouldQueue so Mail::send() queues it.'
        );
    }
}
