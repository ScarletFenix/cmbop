<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Supports one or more roles, e.g. RoleMiddleware:admin or RoleMiddleware:admin,marketing
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (empty($roles)) {
            abort(403, 'Unauthorized: No role specified.');
        }

        // Flatten comma-separated role lists if any
        $allowed = [];
        foreach ($roles as $role) {
            foreach (explode(',', $role) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $allowed[] = $part;
                }
            }
        }

        // User must have at least one of the allowed roles
        $userRoleNames = $user->roles()->pluck('name')->all();
        if (count(array_intersect($allowed, $userRoleNames)) === 0) {
            abort(403, 'Unauthorized: You do not have this role.');
        }

        $active = $user->activeRole();

        // If the staff role is attached but not active (e.g. grant happened while
        // they were in Advertiser/Publisher), activate the first allowed role they have
        // so Marketing/Admin panel links work without a manual switch first.
        if (! in_array($active, $allowed, true)) {
            $preferred = null;
            foreach (['admin', 'marketing'] as $staffRole) {
                if (in_array($staffRole, $allowed, true) && in_array($staffRole, $userRoleNames, true)) {
                    $preferred = $staffRole;
                    break;
                }
            }

            if ($preferred === null) {
                abort(403, 'Unauthorized: This role is not active.');
            }

            $roleId = \App\Models\Role::where('name', $preferred)->value('id');
            if (! $roleId) {
                abort(403, 'Unauthorized: This role is not active.');
            }

            $user->active_role_id = $roleId;
            $user->save();
            $user->unsetRelation('activeRoleRelation');
            $user->unsetRelation('roles');
        }

        return $next($request);
    }
}
