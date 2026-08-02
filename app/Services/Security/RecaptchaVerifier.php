<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side Google reCAPTCHA check.
 *
 * The widget alone proves nothing — without this the token is never validated.
 * Verification is skipped when no secret is configured so local and CI
 * environments (and installs that never set the keys) keep working.
 */
class RecaptchaVerifier
{
    public function configured(): bool
    {
        return config('services.recaptcha.secret_key') !== '';
    }

    /**
     * @return bool True when the request may proceed.
     */
    public function verifyRequest(Request $request): bool
    {
        if (! $this->configured()) {
            return true;
        }

        return $this->verify(
            (string) $request->input('g-recaptcha-response', ''),
            $request->ip()
        );
    }

    public function verify(string $token, ?string $ip = null): bool
    {
        if (! $this->configured()) {
            return true;
        }

        if (trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(config('services.recaptcha.verify_url'), array_filter([
                    'secret' => config('services.recaptcha.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));

            if (! $response->successful()) {
                // Google being unreachable must not lock users out of the platform.
                Log::warning('reCAPTCHA verification request failed', [
                    'status' => $response->status(),
                ]);

                return true;
            }

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable $e) {
            Log::warning('reCAPTCHA verification threw', ['error' => $e->getMessage()]);

            return true;
        }
    }
}
