<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Security\RecaptchaVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    public function __construct(private RecaptchaVerifier $recaptcha) {}

    /**
     * Show login form
     */
    public function show()
    {
        return view('auth.login');
    }

    /**
     * Handle login (AJAX)
     */
    public function login(Request $request)
    {
        // 🔒 Rate limiting (5 attempts per minute per email + IP)
        $key = 'login:'.$request->ip().'|'.$request->email;

        // Per-IP budget as well: the email+IP key alone lets one host spray
        // credentials across many accounts without ever tripping the limit.
        $ipKey = 'login-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many login attempts. Please try again later.',
            ]);
        }

        RateLimiter::hit($key, 60); // 60 seconds
        RateLimiter::hit($ipKey, 300); // 5 minutes

        if (! $this->recaptcha->verifyRequest($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Captcha verification failed. Please try again.',
            ], 422);
        }

        // ✅ Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation',
                'errors' => $validator->errors(),
            ]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Attempt login
        if (! Auth::attempt($credentials, $remember)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password.',
            ]);
        }

        $user = Auth::user();

        // 🚨 Email verification check
        if (! $user->hasVerifiedEmail()) {
            Auth::logout();

            return response()->json([
                'status' => 'unverified',
                'message' => 'Your email is not verified.',
                'email' => $user->email,
            ]);
        }

        // ✅ Relative dashboard path — survives APP_URL=localhost misconfig
        $user->load('activeRoleRelation', 'roles');
        $redirect = $user->getDashboardRoute();

        // ✅ Clear rate limiter on successful login
        RateLimiter::clear($key);
        RateLimiter::clear($ipKey);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful!',
            'redirect' => $redirect,
        ]);
    }

    /**
     * Logout
     */
    public function logout()
    {
        Auth::logout();

        return redirect('/');
    }
}
