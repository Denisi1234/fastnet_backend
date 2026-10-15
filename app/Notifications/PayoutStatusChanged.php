<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A payout moved to a new status (PROCESSING / PAID / FAILED).
 */
class PayoutStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly float $amount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $status = strtoupper($this->status);
        if ($status === 'PAID') {
            $title = 'Payout completed';
            $body = "Payout {$this->reference} (" . $this->money() . ') was sent to your account.';
        } elseif ($status === 'FAILED') {
            $title = 'Payout failed';
            $body = "Payout {$this->reference} (" . $this->money() . ') could not be completed. Contact support.';
        } else {
            $title = "Payout {$status}";
            $body = "Payout {$this->reference} (" . $this->money() . ") is now {$status}.";
        }

        return [
            'title'     => $title,
            'body'      => $body,
            'url'       => '/host/earnings',
            'reference' => $this->reference,
            'status'    => $status,
        ];
    }

    private function money(): string
    {
        return 'TSh ' . number_format($this->amount);
    }
}
