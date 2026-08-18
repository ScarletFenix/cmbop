<?php

// app/Http/Controllers/Admin/DepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\DepositRejected;
use App\Models\DepositRequest;
use App\Services\ActivityLogger;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\InAppNotificationService;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        if (! DepositRequest::tableAvailable()) {
            $deposits = new LengthAwarePaginator([], 0, 20);
            $deposits->withPath($request->url())->appends($request->query());
            $stats = [
                'pending' => 0,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ];
            $invoiceLinks = collect();

            return view('admin.deposits', compact('deposits', 'stats', 'invoiceLinks'));
        }

        $query = DepositRequest::with('user');

        $status = scalar_text($request->input('status'));
        if (in_array($status, ['pending', 'approved', 'completed', 'rejected', 'refunded'], true)) {
            $query->where('status', $status);
        }

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        try {
            $userReportedPaid = 0;
            if (DepositRequest::hasUserMarkedPaidAtColumn()) {
                $userReportedPaid = DepositRequest::where('status', 'pending')->whereUserMarkedPaidAtIsRecorded()->count();
                $query->orderByRaw(
                    'CASE WHEN status = ? AND user_marked_paid_at IS NOT NULL AND user_marked_paid_at >= ? AND user_marked_paid_at <= ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END',
                    ['pending', DepositRequest::PLAUSIBLE_SQL_DATETIME_FLOOR, DepositRequest::PLAUSIBLE_SQL_DATETIME_CEIL, 'pending']
                );
            }

            $stats = [
                'pending' => DepositRequest::where('status', 'pending')->count(),
                'user_reported_paid' => $userReportedPaid,
                'approved' => DepositRequest::where('status', 'approved')->count(),
                'completed' => DepositRequest::where('status', 'completed')->count(),
                'rejected' => DepositRequest::where('status', 'rejected')->count(),
                'refunded' => DepositRequest::where('status', 'refunded')->count(),
                'total_amount' => DepositRequest::where('status', 'completed')->sum('amount'),
            ];

            $deposits = $query
                ->latest()
                ->paginate(20);

            $invoiceLinks = app(AdminInvoiceLinks::class)->forDeposits($deposits->getCollection());

            return view('admin.deposits', compact('deposits', 'stats', 'invoiceLinks'));
        } catch (\Throwable $e) {
            Log::warning('Failed to load admin deposits index: '.$e->getMessage());
            $deposits = new LengthAwarePaginator([], 0, 20);
            $deposits->withPath($request->url())->appends($request->query());
            $stats = [
                'pending' => 0,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ];

            return view('admin.deposits', [
                'deposits' => $deposits,
                'stats' => $stats,
                'invoiceLinks' => collect(),
            ]);
        }
    }

    public function show($id)
    {
        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        try {
            $deposit = DepositRequest::with('user')->find($id);

            if (! $deposit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deposit request not found',
                ]);
            }

            $invoice = app(AdminInvoiceLinks::class)->forDeposits(collect([$deposit]))->get((int) $deposit->id);

            return response()->json([
                'success' => true,
                'deposit' => $deposit,
                'invoice' => $invoice,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to load admin deposit detail: '.$e->getMessage(), [
                'deposit_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }
    }

    public function approve(Request $request, $id, ManualDepositApprovalService $approvals)
    {
        $notes = $this->validatedAdminNotes($request);

        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $deposit = DepositRequest::find($id);

        if (! $deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        try {
            $result = $approvals->approve(
                $deposit,
                $request->user(),
                $notes
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'email_sent' => $result['email_sent'],
            ]);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This deposit was already processed.'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to approve deposit: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to approve deposit. Please try again.'),
            ]);
        }
    }

    public function reject(Request $request, $id)
    {
        $notes = $this->validatedAdminNotes($request);

        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $deposit = DepositRequest::find($id);

        if (! $deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        if ($deposit->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This deposit request has already been processed.',
            ]);
        }

        DB::beginTransaction();

        try {
            $deposit = DepositRequest::where('id', $deposit->id)->lockForUpdate()->firstOrFail();

            if ($deposit->status !== 'pending') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'This deposit request has already been processed.',
                ]);
            }

            $deposit->update(DepositRequest::attributesThatExist([
                'status' => 'rejected',
                'admin_notes' => $notes,
                'rejected_at' => now(),
            ]));

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject deposit: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to reject deposit. Please try again.'),
            ]);
        }

        $emailSent = false;
        $emailError = null;

        // Send email notification to user using markdown
        try {
            $user = $deposit->user;
            if ($user && $user->email) {
                Mail::to($user->email)->send(new DepositRejected($deposit));
                $emailSent = true;
                Log::info('Deposit rejection email sent to: '.$user->email);
            } else {
                $emailError = 'User has no email address';
                Log::warning('Cannot send rejection email - User has no email. User ID: '.$deposit->user_id);
            }
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            Log::error('Failed to send deposit rejected email: '.$e->getMessage());
        }

        $message = 'Deposit request rejected.';
        if ($emailSent) {
            $message .= ' Email notification sent to user.';
        } else {
            $message .= ' Email could not be sent.';
        }

        try {
            app(InAppNotificationService::class)->notifyDepositRejected($deposit->fresh());
        } catch (\Throwable $e) {
            Log::warning('Deposit rejection notification failed', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);
        }

        ActivityLogger::tryLog(
            'deposit.rejected',
            (auth()->user()?->name ?: 'System').' rejected deposit #'.$deposit->id.' (€'.number_format((float) $deposit->amount, 2).')',
            $deposit,
            ['amount' => $deposit->amount, 'user_id' => $deposit->user_id],
            'Deposit #'.$deposit->id
        );

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $emailSent,
        ]);
    }

    private function validatedAdminNotes(Request $request): ?string
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $notes = $data['admin_notes'] ?? null;

        return is_string($notes) ? $notes : null;
    }
}
