<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'fee',
        'net_amount',
        'payment_method',
        'payment_details',
        'status',
        'admin_notes',
        'processed_at',
    ];

    protected $casts = [
        'payment_details' => 'array',
        'processed_at' => 'datetime',
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    protected $appends = [
        'destination_snippet',
        'destination_copy_text',
        'waiting_days',
        'publisher_status_label',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(?string $notes = null): void
    {
        $payload = [
            'status' => 'completed',
            'processed_at' => now(),
        ];

        if ($notes !== null && $notes !== '') {
            $payload['admin_notes'] = $notes;
        }

        $this->update($payload);
    }

    public function markAsCancelled(?string $notes = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'admin_notes' => $notes,
        ]);
    }

    public function isActionable(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    /**
     * Short destination shown in the payout queue table.
     */
    public function getDestinationSnippetAttribute(): string
    {
        $details = is_array($this->payment_details)
            ? $this->payment_details
            : (json_decode((string) $this->payment_details, true) ?: []);

        return match ($this->payment_method) {
            'bank' => $this->bankSnippet($details),
            'paypal' => 'PayPal · '.$this->maskEmail((string) ($details['email'] ?? '')),
            'wise' => 'Wise · '.$this->maskEmail((string) ($details['email'] ?? '')),
            'crypto' => trim(($details['crypto_type'] ?? 'Crypto').' · '.$this->maskWallet((string) ($details['wallet_address'] ?? ''))),
            default => ucfirst((string) $this->payment_method),
        };
    }

    /**
     * Full text for clipboard paste into bank / Wise / PayPal.
     */
    public function getDestinationCopyTextAttribute(): string
    {
        $details = is_array($this->payment_details)
            ? $this->payment_details
            : (json_decode((string) $this->payment_details, true) ?: []);

        $ref = 'WD-'.$this->id;
        $net = number_format((float) $this->net_amount, 2, '.', '');

        return match ($this->payment_method) {
            'bank' => implode("\n", array_filter([
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Bank: '.($details['bank_name'] ?? ''),
                'Account holder: '.($details['account_holder'] ?? ''),
                'IBAN / Account: '.($details['account_number'] ?? ''),
                ! empty($details['swift_code']) ? 'SWIFT: '.$details['swift_code'] : null,
            ])),
            'paypal' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'PayPal: '.($details['email'] ?? ''),
            ]),
            'wise' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Wise: '.($details['email'] ?? ''),
            ]),
            'crypto' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Coin: '.($details['crypto_type'] ?? ''),
                'Wallet: '.($details['wallet_address'] ?? ''),
            ]),
            default => 'Amount: €'.$net."\nReference: ".$ref,
        };
    }

    public function getWaitingDaysAttribute(): ?int
    {
        if (! $this->isActionable() || ! $this->created_at) {
            return null;
        }

        return (int) $this->created_at->diffInDays(now());
    }

    /**
     * Publisher-facing status labels.
     */
    public function getPublisherStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Requested',
            'processing' => 'Processing',
            'completed' => 'Paid',
            'cancelled' => 'Rejected',
            default => ucfirst((string) $this->status),
        };
    }

    private function bankSnippet(array $details): string
    {
        $account = preg_replace('/\s+/', '', (string) ($details['account_number'] ?? ''));
        $last4 = $account !== '' ? substr($account, -4) : '????';
        $prefix = strlen($account) >= 2 ? strtoupper(substr($account, 0, 2)) : 'Bank';

        return $prefix.' · ···'.$last4;
    }

    private function maskEmail(string $email): string
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return $email !== '' ? $email : '—';
        }

        [$local, $domain] = explode('@', $email, 2);
        $keep = max(1, min(2, strlen($local)));

        return substr($local, 0, $keep).'***@'.$domain;
    }

    private function maskWallet(string $wallet): string
    {
        if ($wallet === '') {
            return '—';
        }

        if (strlen($wallet) <= 10) {
            return $wallet;
        }

        return substr($wallet, 0, 6).'…'.substr($wallet, -4);
    }
}
