<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\UserMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

class ForgotPasswordController extends Controller
{
    public function show()
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Rate limiting: max 5 attempts per 10 minutes per IP
        $key = 'forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $retry = max(RateLimiter::availableIn($key), 1);

            return response()->json([
                'status' => 'error',
                'message' => UserMessages::get('password.throttled'),
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($key, 600);

        // Send reset link (generic message even if email doesn't exist)
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'status' => 'success',
            'message' => UserMessages::get('password.reset_sent'),
        ]);
    }
}
