<?php

namespace App\Http\Controllers;

use App\Models\EmailNotificationPreference;
use App\Models\User;
use Illuminate\Http\Request;

class EmailUnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user)
    {
        if (! $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params())) {
            abort(403, 'This unsubscribe link is invalid or has expired.');
        }

        if ($request->isMethod('get')) {
            return view('email.unsubscribe-confirm', [
                'user' => $user,
                'confirmAction' => $request->fullUrl(),
                'brand' => config('email_notifications.brand.name', config('app.name')),
            ]);
        }

        EmailNotificationPreference::updateOrCreate(
            [
                'user_id' => $user->id,
                'preference_key' => 'marketing_emails',
            ],
            ['enabled' => false]
        );

        if ($this->isOneClick($request)) {
            return response('', 200);
        }

        return view('email.unsubscribed', [
            'user' => $user,
            'brand' => config('email_notifications.brand.name', config('app.name')),
        ]);
    }

    protected function isOneClick(Request $request): bool
    {
        return $request->input('List-Unsubscribe') === 'One-Click'
            || $request->header('List-Unsubscribe') === 'One-Click'
            || $request->expectsJson();
    }
}
