<?php

namespace App\Mail;

use App\Models\User;

class AdminNewUserRegistered extends PlatformMailable
{
    public function __construct(public User $newUser, public ?User $admin = null)
    {
        parent::__construct();
        $this->notificationType = 'admin_new_user';
        $this->recipientUser = $admin;
    }

    public function build()
    {
        $first = $this->firstName($this->admin);
        $role = $this->newUser->activeRole();
        $roleLabel = match ($role) {
            'advertiser' => 'Advertiser',
            'publisher' => 'Publisher',
            default => $role ? ucfirst($role) : 'User',
        };
        $subject = match ($role) {
            'advertiser' => 'New advertiser registered — '.$this->newUser->name,
            'publisher' => 'New publisher registered — '.$this->newUser->name,
            default => 'New user registered — '.$this->newUser->name,
        };
        $ctaUrl = match ($role) {
            'advertiser' => $this->publicRoute('admin.audiences.index', ['tab' => 'no_orders']),
            'publisher' => $this->publicRoute('admin.audiences.index', ['tab' => 'no_sites']),
            default => $this->publicRoute('admin.users.index'),
        };
        $ctaLabel = match ($role) {
            'advertiser' => 'View advertisers (no orders)',
            'publisher' => 'View publishers (no sites)',
            default => 'View Users',
        };

        return $this->subject($subject)
            ->markdown('emails.admin.new-user-registered')
            ->with([
                'adminFirstName' => $first,
                'newUser' => $this->newUser,
                'roleLabel' => $roleLabel,
                'headline' => match ($role) {
                    'advertiser' => 'New advertiser registered',
                    'publisher' => 'New publisher registered',
                    default => 'New user registered',
                },
                'ctaUrl' => $ctaUrl,
                'ctaLabel' => $ctaLabel,
                'brand' => $this->brand(),
            ]);
    }
}
