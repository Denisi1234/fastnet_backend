<?php

namespace App\Notifications;

/**
 * Stay finished (booking reached Completed).
 */
class BookingCompleted extends BookingStatusNotification
{
    protected function typeKey(): string
    {
        return 'booking_completed';
    }

    protected function title(): string
    {
        return 'Stay completed';
    }

    protected function body(array $booking, array $property): string
    {
        return 'Thanks for staying with us at '
            . ($property['name'] ?? 'FastNetStays') . '.' . $this->reference($booking);
    }
}
