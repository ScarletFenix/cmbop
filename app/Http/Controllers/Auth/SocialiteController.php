<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

class SocialiteController extends Controller
{
    public function redirectToGoogle(): RedirectResponse
    {
        if (! google_oauth_configured()) {
            Log::warning('Google OAuth redirect blocked: credentials not configured');

            return redirect()->route('login')
                ->with('error', 'Google sign-in is not configured yet. Please use email and password, or contact support.');
        }

        try {
            return Socialite::driver('google')->redirect();
        } catch (\Throwable $e) {
            Log::error('Google OAuth redirect failed: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);

            return redirect()->route('login')
                ->with('error', 'Google sign-in is temporarily unavailable. Please try again or use email and password.');
        }
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        if ($error = request('error')) {
            $denied = $error === 'access_denied';
            Log::info('Google OAuth callback returned error', [
                'error' => $error,
                'description' => request('error_description'),
            ]);

            return redirect()->route('login')->with(
                'error',
                $denied
                    ? 'Google sign-in was cancelled. You can try again or use email and password.'
                    : 'Google sign-in failed. Please try again or use email and password.'
            );
        }

        if (! google_oauth_configured()) {
            return redirect()->route('login')
                ->with('error', 'Google sign-in is not configured yet. Please use email and password, or contact support.');
        }

        try {
            $socialUser = $this->resolveGoogleUser();
            $providerId = $socialUser->getId();
            $email = $socialUser->getEmail();
            $name = $socialUser->getName() ?: ($email ? Str::before($email, '@') : 'Google User');

            $existingUser = null;
            if ($email) {
                $existingUser = User::where('email', $email)->first();
            }
            if (! $existingUser && $providerId) {
                $existingUser = User::where('google_id', $providerId)->first();
            }

            if ($existingUser) {
                $existingUser->google_id = $providerId;
                $existingUser->google_token = $socialUser->token ?? null;
                $existingUser->google_refresh_token = $socialUser->refreshToken ?? null;
                if ($socialUser->getAvatar()) {
                    $existingUser->avatar = $socialUser->getAvatar();
                }
                if (! $existingUser->email_verified_at) {
                    $existingUser->email_verified_at = now();
                }
                $existingUser->save();

                return $this->loginAndRedirect($existingUser);
            }

            if (! $email) {
                return redirect()->route('login')
                    ->with('error', 'Google did not share an email address. Please use another sign-in method.');
            }

            DB::beginTransaction();

            $advertiserRole = Role::where('name', 'advertiser')->first();
            $publisherRole = Role::where('name', 'publisher')->first();

            if (! $advertiserRole || ! $publisherRole) {
                throw new \RuntimeException('Roles not found. Please run database seeders.');
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt(Str::random(24)),
                'email_verified_at' => now(),
                'google_id' => $providerId,
                'google_token' => $socialUser->token ?? null,
                'google_refresh_token' => $socialUser->refreshToken ?? null,
                'avatar' => $socialUser->getAvatar(),
                'active_role_id' => $advertiserRole->id,
            ]);

            $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);

            $welcomeBonus = 20.00;
            Wallet::insertRegistrationPair(
                $user->id,
                $advertiserRole->id,
                $publisherRole->id,
                $welcomeBonus
            );

            if (Schema::hasTable('user_consents')) {
                UserConsent::create([
                    'user_id' => $user->id,
                    'terms_accepted' => true,
                    'marketing_consent' => false,
                    'newsletter_consent' => false,
                    'consented_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }

            DB::commit();

            try {
                $advertiserWallet = Wallet::where('user_id', $user->id)
                    ->where('role_id', $advertiserRole->id)
                    ->first();
                if ($advertiserWallet && $welcomeBonus > 0) {
                    app(WalletLedgerService::class)->recordBonusCredit(
                        $advertiserWallet,
                        (float) $welcomeBonus,
                        'Welcome promotional bonus',
                        ['source' => 'socialite']
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Welcome bonus ledger write failed during Google signup', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->loginAndRedirect($user);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Google authentication failed: '.($e->getMessage() !== '' ? $e->getMessage() : $e::class), [
                'exception' => $e::class,
            ]);

            return redirect()->route('login')
                ->with('error', 'Google authentication failed. Please try again or use email and password.');
        }
    }

    /**
     * Resolve the Google user, falling back to stateless when the OAuth
     * session "state" cookie/session was lost (common on localhost / SameSite).
     */
    private function resolveGoogleUser(): SocialiteUser
    {
        try {
            return Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            Log::warning('Google OAuth state mismatch; retrying stateless user resolve', [
                'exception' => $e::class,
            ]);

            return Socialite::driver('google')->stateless()->user();
        }
    }

    private function loginAndRedirect(User $user): RedirectResponse
    {
        Auth::login($user, true);
        request()->session()->regenerate();

        $user->load('activeRoleRelation', 'roles');

        return redirect()->to($this->postLoginDestination($user));
    }

    /**
     * Prefer a safe intended URL; never bounce back to login/register/OAuth.
     */
    private function postLoginDestination(User $user): string
    {
        $dashboard = $user->getDashboardRoute();
        $intended = session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $dashboard;
        }

        $path = parse_url($intended, PHP_URL_PATH) ?: $intended;
        $blocked = ['/login', '/register', '/auth/google', '/forgot-password', '/reset-password', '/email/verify'];

        foreach ($blocked as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $dashboard;
            }
        }

        return $intended;
    }
}
