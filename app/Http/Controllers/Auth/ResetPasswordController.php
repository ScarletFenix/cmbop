<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedMail;
use App\Support\UserMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ResetPasswordController extends Controller
{
    public function show($token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $key = 'reset:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $retry = max(RateLimiter::availableIn($key), 1);

            return response()->json([
                'status' => 'error',
                'message' => UserMessages::get('password.reset_throttled'),
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($key, 600);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) use ($request) {
                // Hashed cast hashes once — do not bcrypt here or login breaks.
                $user->password = $password;
                $user->save();
                PasswordChangedMail::notify($user);

                if (Auth::check() && (int) Auth::id() === (int) $user->id) {
                    Auth::logoutOtherDevices($password);
                    $request->session()->regenerate();

                    return;
                }

                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
            }
        );

        return response()->json([
            'status' => $status === Password::PASSWORD_RESET ? 'success' : 'error',
            'message' => $status === Password::PASSWORD_RESET
                ? UserMessages::get('password.reset_success')
                : UserMessages::get('password.reset_invalid'),
        ]);
    }
}
