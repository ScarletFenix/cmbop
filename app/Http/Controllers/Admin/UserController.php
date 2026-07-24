<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PayoutProfileUpdatedBySupport;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Wallet\PayoutProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    /** Hard cap on how many users may hold the admin role. */
    public const MAX_ADMINS = 2;

    /** Hard cap on how many users may hold the marketing role. */
    public const MAX_MARKETING = 5;

    public function __construct(
        private PayoutProfileService $payoutProfiles,
    ) {}

    // ✅ Users listing
    public function index()
    {
        $users = User::with('roles')->latest()->paginate(10);
        $adminCount = $this->adminCount();
        $marketingCount = $this->marketingCount();
        $maxMarketing = self::MAX_MARKETING;

        return view('admin.users', compact('users', 'adminCount', 'marketingCount', 'maxMarketing'));
    }

    // ✅ Update Company (AJAX)
    public function updateCompany(Request $request, $id)
    {
        try {
            $request->validate([
                'company_name' => 'nullable|string|max:255',
            ]);

            $user = User::findOrFail($id);

            $user->company_name = $request->company_name;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }

    /**
     * Support-only: update a user's locked payout destinations and email them.
     */
    public function updatePayoutProfile(Request $request, $id)
    {
        $data = $request->validate([
            'payment_method' => 'required|in:bank,paypal,wise,crypto',
            'paypal_email' => 'nullable|email|max:255',
            'wise_email' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:255',
            'account_holder' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'swift_code' => 'nullable|string|max:50',
            'crypto_type' => 'nullable|string|in:BTC,ETH,USDT,BNB',
            'wallet_address' => 'nullable|string|max:255',
        ]);

        $method = $data['payment_method'];
        if ($method === 'paypal' && empty($data['paypal_email'])) {
            return response()->json(['success' => false, 'message' => 'PayPal email is required.'], 422);
        }
        if ($method === 'wise' && empty($data['wise_email'])) {
            return response()->json(['success' => false, 'message' => 'Wise email is required.'], 422);
        }
        if ($method === 'bank' && (empty($data['bank_name']) || empty($data['account_holder']) || empty($data['account_number']))) {
            return response()->json(['success' => false, 'message' => 'Bank name, holder, and account are required.'], 422);
        }
        if ($method === 'crypto' && (empty($data['wallet_address']) || empty($data['crypto_type']))) {
            return response()->json(['success' => false, 'message' => 'Crypto type and wallet address are required.'], 422);
        }

        $user = User::findOrFail($id);
        $this->payoutProfiles->adminUpdateProfile($user, $method, $data);

        try {
            Mail::to($user->email)->send(new PayoutProfileUpdatedBySupport($user->fresh(), $method));
        } catch (\Throwable $e) {
            report($e);
        }

        ActivityLogger::log(
            'user.payout_profile_updated',
            auth()->user()->name.' updated payout profile for user #'.$user->id.' ('.$method.')',
            $user,
            ['method' => $method],
            'User #'.$user->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Payout details updated. The publisher was notified by email.',
            'payout_profile' => $user->fresh()->payoutProfile(),
        ]);
    }

    /**
     * Grant or revoke the Marketing role for a team member (AJAX).
     *
     * Only admins may change Marketing (route + explicit check).
     * At most {@see MAX_MARKETING} users may hold Marketing at once.
     * Registration already gives Advertiser + Publisher; those are never changed here.
     */
    public function updateRoles(Request $request, $id)
    {
        $actor = auth()->user();
        if (! $actor || (! $actor->isAdmin() && ! $actor->hasRole('admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Only an admin can grant or revoke Marketing access.',
            ], 403);
        }

        $validated = $request->validate([
            'marketing' => 'required|boolean',
        ], [
            'marketing.required' => 'Please choose whether this user should have Marketing access.',
        ]);

        $user = User::findOrFail($id);
        $previousRoles = $user->roles()->pluck('name')->all();
        $marketingRole = Role::where('name', 'marketing')->firstOrFail();

        $grantMarketing = (bool) $validated['marketing'];
        $alreadyHasMarketing = $user->hasRole('marketing');

        if ($grantMarketing && ! $alreadyHasMarketing) {
            $current = $this->marketingCount();
            if ($current >= self::MAX_MARKETING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marketing is limited to '.self::MAX_MARKETING.' people. Revoke access from someone else first.',
                    'marketing_count' => $current,
                    'max_marketing' => self::MAX_MARKETING,
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($user, $marketingRole, $grantMarketing) {
                if ($grantMarketing) {
                    $user->roles()->syncWithoutDetaching([$marketingRole->id]);
                    // Activate Marketing so they can open the admin panel immediately.
                    $user->active_role_id = $marketingRole->id;
                    $user->save();
                } else {
                    $user->roles()->detach($marketingRole->id);

                    // If their active role was marketing, fall back to another role they still have.
                    if ((int) $user->active_role_id === (int) $marketingRole->id) {
                        $fallbackId = $user->roles()
                            ->where('roles.id', '!=', $marketingRole->id)
                            ->value('roles.id');

                        $user->active_role_id = $fallbackId;
                        $user->save();
                    }
                }
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update marketing access. Please try again.',
            ], 500);
        }

        $user->load('roles');
        $newRoles = $user->roles->pluck('name')->all();
        $marketingCount = $this->marketingCount();

        ActivityLogger::log(
            $grantMarketing ? 'user.marketing_granted' : 'user.marketing_revoked',
            auth()->user()->name.($grantMarketing ? ' granted' : ' revoked').' Marketing for '.$user->name,
            $user,
            [
                'from' => $previousRoles,
                'to' => $newRoles,
                'active_role' => $user->activeRole(),
                'marketing_count' => $marketingCount,
            ],
            $user->name
        );

        return response()->json([
            'success' => true,
            'message' => $grantMarketing
                ? 'Marketing access granted. Their active workspace is now Marketing.'
                : 'Marketing access removed.',
            'roles' => $newRoles,
            'active_role' => $user->activeRole(),
            'marketing' => $grantMarketing,
            'marketing_count' => $marketingCount,
            'max_marketing' => self::MAX_MARKETING,
        ]);
    }

    private function adminCount(): int
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');
        if (! $adminRoleId) {
            return 0;
        }

        return (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id');
    }

    private function marketingCount(): int
    {
        $marketingRoleId = Role::where('name', 'marketing')->value('id');
        if (! $marketingRoleId) {
            return 0;
        }

        return (int) DB::table('role_user')->where('role_id', $marketingRoleId)->distinct()->count('user_id');
    }
}
