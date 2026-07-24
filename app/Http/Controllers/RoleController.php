<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;

class RoleController extends Controller
{
    /**
     * Switch the active role for the logged-in user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function switchRole(Request $request)
    {
        $request->validate([
            'active_role_id' => 'required|exists:roles,id',
        ]);

        $user = auth()->user();
        $roleId = (int) $request->active_role_id;

        // Ensure user actually has this role (cast avoids string/int miss on some drivers)
        $hasRole = $user->roles()
            ->where('roles.id', $roleId)
            ->exists();

        if (! $hasRole) {
            return back()->with('error', 'You cannot switch to this role.');
        }

        $user->active_role_id = $roleId;
        $user->save();
        $user->unsetRelation('roles');
        $user->unsetRelation('activeRoleRelation');

        return redirect($user->getDashboardRoute())
            ->with('success', 'Role switched to '.$user->activeRole());
    }
}