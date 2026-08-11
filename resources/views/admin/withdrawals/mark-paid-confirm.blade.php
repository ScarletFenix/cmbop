@extends('layouts.app')

@section('title', 'Confirm payout marked paid - SEOLinkBuildings')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow">
                <div class="card-body p-4 p-md-5">
                    @if($canMarkPaid)
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-money-check-dollar fa-3x text-primary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Confirm marked paid</h1>
                            <p class="text-muted mb-0">
                                Only confirm after you have sent the net amount outside the app. Funds were already deducted when the publisher requested withdrawal.
                            </p>
                        </div>

                        <dl class="row mb-3">
                            <dt class="col-sm-4 text-muted">Pay now (net)</dt>
                            <dd class="col-sm-8 fw-semibold fs-5">€{{ number_format((float) $withdrawal->net_amount, 2) }}</dd>

                            <dt class="col-sm-4 text-muted">Gross / fee</dt>
                            <dd class="col-sm-8">
                                €{{ number_format((float) $withdrawal->amount, 2) }}
                                @if((float) $withdrawal->fee > 0)
                                    <span class="text-muted small">(fee €{{ number_format((float) $withdrawal->fee, 2) }})</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4 text-muted">Method</dt>
                            <dd class="col-sm-8">{{ strtoupper((string) $withdrawal->payment_method) }}</dd>

                            <dt class="col-sm-4 text-muted">Reference</dt>
                            <dd class="col-sm-8"><code>WD-{{ $withdrawal->id }}</code></dd>

                            <dt class="col-sm-4 text-muted">Publisher</dt>
                            <dd class="col-sm-8">
                                {{ $withdrawal->user->name ?? 'Unknown' }}
                                @if($withdrawal->user?->email)
                                    <br><span class="text-muted small">{{ $withdrawal->user->email }}</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4 text-muted">Requested</dt>
                            <dd class="col-sm-8">{{ optional($withdrawal->created_at)->format('M d, Y H:i') }}</dd>

                            <dt class="col-sm-4 text-muted">Status</dt>
                            <dd class="col-sm-8">{{ $withdrawal->status }}</dd>
                        </dl>

                        <div class="border rounded p-3 mb-4 bg-light">
                            <h2 class="h6 text-uppercase text-muted mb-2">Destination</h2>
                            <pre class="mb-0 small" style="white-space: pre-wrap;">{{ $withdrawal->destination_copy_text }}</pre>
                        </div>

                        <form method="POST" action="{{ $confirmAction }}">
                            @csrf
                            <div class="mb-3">
                                <label for="notes" class="form-label">Admin notes (optional)</label>
                                <textarea name="notes" id="notes" rows="2" class="form-control" maxlength="2000" placeholder="e.g. Wise transfer sent">{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-success w-100 mb-2">
                                <i class="fa fa-check me-1" aria-hidden="true"></i>
                                Confirm marked paid — €{{ number_format((float) $withdrawal->net_amount, 2) }}
                            </button>
                        </form>

                        <a href="{{ route('admin.withdrawals') }}" class="btn btn-link w-100 text-muted">
                            Cancel — back to payout queue
                        </a>
                    @else
                        <div class="text-center">
                            <i class="fa-solid fa-circle-info fa-3x text-secondary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Withdrawal already settled</h1>
                            <p class="text-muted mb-4">
                                This withdrawal is <strong>{{ $withdrawal->status }}</strong> and cannot be marked paid again from this link.
                            </p>
                            <a href="{{ route('admin.withdrawals') }}" class="btn btn-primary">Open payout queue</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
