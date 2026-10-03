<?php

namespace App\Notifications;

/**
 * Payment succeeded and the booking moved to Confirmed.
 */
class BookingConfirmed extends BookingStatusNotification
{
    protected function typeKey(): string
    {
        return 'booking_confirmed';
    }

    protected function title(): string
    {
        return 'Booking confirmed';
    }

    protected function body(array $booking, array $property): string
    {
        return $this->staySummary($booking, $property)
            . ' is confirmed.' . $this->reference($booking)
            . ($booking['total'] ? " Paid {$booking['total']}." : '');
    }
}
