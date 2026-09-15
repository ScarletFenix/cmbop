<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\UserMessages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class BlockSuspendedUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isSuspended()) {
            return $next($request);
        }

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = UserMessages::get('login.suspended');

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 403);
        }

        return redirect()->route('login')->with('error', $message);
    }
}
