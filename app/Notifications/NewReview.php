<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A guest reviewed one of the host's properties.
 */
class NewReview extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $propertyId,
        public readonly string $propertyName,
        public readonly int $rating,
        public readonly string $comment,
        public readonly string $reviewerName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $comment = trim($this->comment);
        if (mb_strlen($comment) > 140) {
            $comment = mb_substr($comment, 0, 137) . '...';
        }
        $who = $this->reviewerName !== '' ? $this->reviewerName : 'A guest';

        return [
            'title'         => "New {$this->rating}-star review" . ($this->propertyName !== '' ? " on {$this->propertyName}" : ''),
            'body'          => $who . ($comment !== '' ? ": {$comment}" : ' left you a rating.'),
            'url'           => '/host/reviews',
            'property_id'   => $this->propertyId,
            'property_name' => $this->propertyName,
        ];
    }
}
