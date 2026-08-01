<?php

namespace App\Mail;

use App\Models\User;

class GoogleTempPasswordMail extends PlatformMailable
{
    public function __construct(
        public User $user,
        public string $temporaryPassword
    ) {
        parent::__construct();
        $this->notificationType = 'google_temp_password';
        $this->recipientUser = $user;
        // Account credential email — must not be suppressed by user prefs.
        $this->skipUserPreference = true;
    }

    public function build()
    {
        $profileUrl = rtrim(app_public_url(), '/').'/profile';
        $loginUrl = rtrim(app_public_url(), '/').'/login';

        return $this->subject('Your temporary password for '.config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.google-temp-password')
            ->with([
                'user' => $this->user,
                'firstName' => $this->firstName($this->user),
                'email' => $this->user->email,
                'temporaryPassword' => $this->temporaryPassword,
                'profileUrl' => $profileUrl,
                'loginUrl' => $loginUrl,
                'brand' => $this->brand(),
            ]);
    }
}
