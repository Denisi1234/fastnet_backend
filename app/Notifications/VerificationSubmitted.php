<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A host submitted identity verification documents — admins review these.
 */
class VerificationSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $ownerId,
        public readonly string $ownerName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'    => 'New host verification',
            'body'     => ($this->ownerName !== '' ? $this->ownerName : 'A host') . ' submitted identity documents for review.',
            'url'      => '/admin/owners',
            'owner_id' => $this->ownerId,
        ];
    }
}
