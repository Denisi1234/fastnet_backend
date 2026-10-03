<?php

namespace App\Notifications;

/**
 * Booking cancelled — by the guest, or automatically after a failed payment.
 */
class BookingCancelled extends BookingStatusNotification
{
    protected function typeKey(): string
    {
        return 'booking_cancelled';
    }

    protected function title(): string
    {
        return 'Booking cancelled';
    }

    protected function body(array $booking, array $property): string
    {
        return 'Your booking at ' . ($property['name'] ?? 'the property')
            . ' has been cancelled.' . $this->reference($booking);
    }
}
