<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone wrote to you in property chat.
 */
class NewMessage extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $senderId,
        public readonly string $senderName,
        public readonly string $lodgeName,
        public readonly string $preview,
        public readonly string $recipientRole = 'customer',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $preview = trim($this->preview);
        if (mb_strlen($preview) > 140) {
            $preview = mb_substr($preview, 0, 137) . '...';
        }
        $url = strtolower($this->recipientRole) === 'admin'
            ? '/admin/support'
            : (strtolower($this->recipientRole) === 'owner'
                ? '/host/messages?partner_id=' . $this->senderId
                : '/messages?host_id=' . $this->senderId);

        return [
            'title' => 'New message from ' . ($this->senderName !== '' ? $this->senderName : 'a guest'),
            'body'  => $preview,
            'url'   => $url,
        ];
    }
}
