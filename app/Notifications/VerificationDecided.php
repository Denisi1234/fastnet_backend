<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An admin decided on the host's verification submission.
 */
class VerificationDecided extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $status,
        public readonly string $reason = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $status = strtolower($this->status);
        if ($status === 'approved') {
            $title = 'Verification approved';
            $body = 'Your identity is verified — your properties can now go live.';
        } elseif ($status === 'changes_requested') {
            $title = 'Verification needs changes';
            $body = 'Our team requested changes to your documents.'
                . ($this->reason !== '' ? " Reason: {$this->reason}" : '');
        } else {
            $title = 'Verification update: ' . $this->status;
            $body = $this->reason !== ''
                ? $this->reason
                : 'Your verification status changed. Contact support for details.';
        }

        return [
            'title'  => $title,
            'body'   => $body,
            'url'    => '/host/verification',
            'status' => $this->status,
        ];
    }
}
