<?php

namespace Tests\Feature;

use App\Support\UserMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EverydayErrorCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_json_uses_the_error_catalog(): void
    {
        $this->postJson(route('login.post'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', UserMessages::get('login.invalid'));
    }

    public function test_stripe_webhook_not_configured_uses_catalog(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $this->postJson('/api/stripe/webhook', [])
            ->assertStatus(500)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_unavailable'));
    }

    public function test_stripe_webhook_bad_signature_uses_catalog(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_copy']);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't=1,v1=bad',
            ],
            '{}'
        )
            ->assertStatus(400)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_signature'));
    }

    public function test_stripe_webhook_failure_uses_catalog_not_exception_text(): void
    {
        $secret = 'whsec_test_copy';
        config(['services.stripe.webhook_secret' => $secret]);
        $payload = 'not-json';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        )
            ->assertStatus(500)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_failed'))
            ->assertJsonMissingPath('exception')
            ->assertDontSee('Invalid payload');
    }
}
