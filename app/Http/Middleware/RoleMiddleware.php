<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Supports one or more roles, e.g. RoleMiddleware:admin or RoleMiddleware:admin,marketing
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (! $user) {
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
        try {
            $userRoleNames = $user->roles()->pluck('name')->all();
        } catch (\Throwable) {
            // Leftover Hostinger: missing role_user must not 500 every admin page.
            $activeName = $user->activeRole();
            $userRoleNames = $activeName ? [$activeName] : [];
        }
        if (count(array_intersect($allowed, $userRoleNames)) === 0) {
            abort(403, 'Unauthorized: You do not have this role.');
        }

        $active = $user->activeRole();

        // Role is attached but not active (common for Advertiser+Publisher accounts,
        // and when Marketing/Admin was granted while in a portal role). Activate an
        // allowed role so deep links like /publisher/websites?status=pending work
        // without a manual switch first.
        if (! in_array($active, $allowed, true)) {
            $preferred = null;

            // Prefer staff roles when the route allows them (admin panel entry).
            foreach (['admin', 'marketing'] as $staffRole) {
                if (in_array($staffRole, $allowed, true) && in_array($staffRole, $userRoleNames, true)) {
                    $preferred = $staffRole;
                    break;
                }
            }

            // Otherwise activate the first allowed portal role the user has
            // (e.g. /publisher/* while still active as advertiser).
            if ($preferred === null) {
                foreach ($allowed as $roleName) {
                    if (in_array($roleName, $userRoleNames, true)) {
                        $preferred = $roleName;
                        break;
                    }
                }
            }

            if ($preferred === null) {
                abort(403, 'Unauthorized: This role is not active.');
            }

            $roleId = Role::where('name', $preferred)->value('id');
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
