<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * First-run welcome, shown once inside the app.
 *
 * Dispatched on a user's FIRST successful sign-in only — the caller gates it
 * on a welcome_seen_at stamp so it can never repeat.
 */
class WelcomeBack extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Welcome to FastNetStays',
            'body'  => 'Save your favourite stays, book instantly, and manage '
                     . 'every trip from one place. Start by searching a destination.',
            'url'   => '/',
        ];
    }
}
