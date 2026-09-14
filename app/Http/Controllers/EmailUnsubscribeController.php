<?php

namespace App\Http\Controllers;

use App\Models\EmailNotificationPreference;
use App\Models\User;
use Illuminate\Http\Request;

class EmailUnsubscribeController extends Controller
{
    public function __invoke(Request $request, int $user)
    {
        // Validate the signature before looking up the user so missing ids
        // and bad signatures both 403 (implicit User binding would 404).
        if (! $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params())) {
            abort(403, 'This unsubscribe link is invalid or has expired.');
        }

        $account = User::query()->find($user);
        if (! $account) {
            abort(403, 'This unsubscribe link is invalid or has expired.');
        }

        if ($request->isMethod('get')) {
            return view('email.unsubscribe-confirm', [
                'user' => $account,
                'confirmAction' => $request->getRequestUri(),
                'brand' => function_exists('mail_brand_name') ? mail_brand_name() : 'SEOLinkBuildings',
            ]);
        }

        EmailNotificationPreference::updateOrCreate(
            [
                'user_id' => $account->id,
                'preference_key' => 'marketing_emails',
            ],
            ['enabled' => false]
        );

        if ($this->isOneClick($request)) {
            return response('', 200);
        }

        return view('email.unsubscribed', [
            'user' => $account,
            'brand' => function_exists('mail_brand_name') ? mail_brand_name() : 'SEOLinkBuildings',
        ]);
    }

    protected function isOneClick(Request $request): bool
    {
        return $request->input('List-Unsubscribe') === 'One-Click'
            || $request->header('List-Unsubscribe') === 'One-Click'
            || $request->expectsJson();
    }
}
