<?php

namespace App\Mail;

use App\Models\User;
use App\Services\ResendMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email.
 *
 * The HTML now lives in resources/views/emails/welcome.blade.php and is shared
 * with ResendMailService::sendWelcomeEmail(). This class previously carried its
 * own 400-line copy of that markup, which the service reached by reflection —
 * so there were two sources of truth for the same email.
 */
class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user
    ) {}

    public function build(): self
    {
        return $this->subject('Welcome to FastNetStays.com — Your account is ready')
                    ->html($this->renderHtmlContent());
    }

    /**
     * Render the shared template. Kept as a named method so callers that used to
     * reflect into it continue to work.
     */
    public function renderHtmlContent(): string
    {
        $base = rtrim(env('FRONTEND_URL', 'https://fastnetstays.com'), '/');

        return view('emails.welcome', [
            'userName'       => $this->user->name,
            'email'          => $this->user->email,
            'searchUrl'      => $base . '/',
            'accountUrl'     => $base . '/settings',
            'preferencesUrl' => $base . '/notifications',
            'privacyUrl'     => $base . '/privacy-policy',
        ])->render();
    }
}
