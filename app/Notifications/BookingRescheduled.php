<?php

namespace App\Notifications;

/**
 * Booking dates changed (guest reschedule, applied after live reprice).
 */
class BookingRescheduled extends BookingStatusNotification
{
    public function __construct(
        int $bookingId,
        public readonly string $from,
        public readonly string $to
    ) {
        parent::__construct($bookingId);
    }

    protected function typeKey(): string
    {
        return 'booking_rescheduled';
    }

    protected function title(): string
    {
        return 'Booking dates changed';
    }

    protected function body(array $booking, array $property): string
    {
        return 'Your stay at ' . ($property['name'] ?? 'the property')
            . ' moved from ' . $this->from . ' to ' . $this->to . '.'
            . $this->reference($booking);
    }
}
