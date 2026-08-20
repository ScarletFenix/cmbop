<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordChangedMail extends PlatformMailable
{
    public function __construct(public User $user)
    {
        parent::__construct();
        $this->notificationType = 'password_changed';
        $this->recipientUser = $user;
        // Security notice — must not be suppressed by Email Preferences.
        $this->skipUserPreference = true;
        $this->dedupeKey = 'password_changed:'.$user->id.':'.Str::uuid();
    }

    /**
     * Security mail must still go out after a backed-up queue. Dropping
     * "your password was changed" a day late is worse than a late notice.
     */
    protected function isStale(): bool
    {
        return false;
    }

    public function build()
    {
        $resetUrl = $this->publicRoute('password.request');

        return $this->subject('Your password was changed on '.config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.password-changed')
            ->with([
                'user' => $this->user,
                'firstName' => $this->firstName($this->user),
                'changedAt' => now()->timezone(config('app.timezone'))->format('M j, Y g:i A T'),
                'profileUrl' => $this->publicRoute('profile'),
                'resetUrl' => $resetUrl,
                'loginUrl' => $this->publicRoute('login'),
                'brand' => $this->brand(),
            ]);
    }

    /**
     * Queue after a successful password write. Never include the password.
     * A mail/queue failure must not undo the password change.
     */
    public static function notify(User $user): void
    {
        try {
            Mail::to($user->email)->send(new static($user));
        } catch (\Throwable $e) {
            Log::warning('Password changed mail failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
