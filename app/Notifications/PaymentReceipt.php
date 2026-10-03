<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Payment receipt issued after a successful checkout.
 */
class PaymentReceipt extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $bookingId
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        $booking = Booking::find($this->bookingId);

        if (! $booking) {
            return [
                'title' => 'Payment received',
                'body'  => 'Your receipt is available in your bookings.',
                'url'   => '/my-booking',
            ];
        }

        $property = Property::find($booking->room?->property_id);
        $code     = $booking->booking_code;

        $body = ($property?->name ? "{$property->name}. " : '')
            . (isset($booking->total_price)
                ? 'TSh ' . number_format((float) $booking->total_price) . ' paid.'
                : 'Payment recorded.');

        $receipt = $code
            ? "/booking/e-receipt.html?code={$code}&action=download"
            : '/my-booking';

        return [
            'title'      => 'Payment receipt',
            'body'       => $body,
            'booking_id' => $booking->id,
            'booking_code' => $code,
            'url'        => $receipt,
        ];
    }
}
