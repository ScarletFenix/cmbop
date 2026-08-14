<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Support\StaffWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marketing staff use /marketing/* — bounce leftover /admin links there when possible.
 */
class RedirectMarketingFromAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isAdmin() || ! $user->hasRole('marketing')) {
            return $next($request);
        }

        // Role may still be Advertiser/Publisher until they open the marketing panel.
        if (! $user->isMarketing()) {
            $roleId = Role::where('name', 'marketing')->value('id');
            if ($roleId) {
                $user->active_role_id = $roleId;
                $user->save();
                $user->unsetRelation('activeRoleRelation');
                $user->unsetRelation('roles');
            }
        }

        $rest = ltrim((string) preg_replace('#^admin/?#', '', $request->path()), '/');

        if (StaffWorkspace::isMarketingOpsPath($rest)) {
            $target = '/marketing/'.($rest !== '' ? $rest : 'dashboard');
            if ($qs = $request->getQueryString()) {
                $target .= '?'.$qs;
            }

            return redirect()->to($target);
        }

        if ($request->expectsJson()) {
            abort(403, 'Marketing staff use the /marketing panel for site ops.');
        }

        return redirect()->route('marketing.dashboard');
    }
}
