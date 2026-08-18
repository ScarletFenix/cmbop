<?php

namespace Tests\Unit;

use App\Support\UserMessages;
use Tests\TestCase;

class UserMessagesTest extends TestCase
{
    public function test_catalog_sentences_are_everyday_english(): void
    {
        $keys = [
            'login.invalid',
            'login.throttled',
            'login.unverified',
            'register.throttled',
            'register.unavailable',
            'password.throttled',
            'password.reset_sent',
            'session.expired',
            'generic.retry',
            'payment.webhook_failed',
            'payment.leftover_credit_failed',
            'cron.disabled',
        ];

        foreach ($keys as $key) {
            $line = UserMessages::get($key);
            $this->assertNotSame('', $line, $key);
            $this->assertNotSame('errors.'.$key, $line, $key);
            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|stack trace|Exception/i', $line);
        }
    }

    public function test_unknown_key_falls_back_without_leaking_the_key(): void
    {
        $this->assertSame(
            UserMessages::get('generic.retry'),
            UserMessages::get('this.key.does.not.exist')
        );
    }
}
