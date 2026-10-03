<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * New-sign-in security alert.
 *
 * HTML lives in resources/views/emails/login-alert.blade.php and is shared with
 * ResendMailService::sendLoginAlertEmail().
 */
class LoginAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user
    ) {}

    public function build(): self
    {
        return $this->subject('Security Alert: New sign-in to your FastNetStays account')
                    ->html($this->renderHtmlContent());
    }

    public function renderHtmlContent(): string
    {
        return view('emails.login-alert', [
            'accountEmail' => $this->user->email,
            'signedInAt'   => now()->format('d M Y, H:i'),
        ])->render();
    }
}
