<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Shared payload builder for booking lifecycle notifications.
 *
 * Subclasses supply only the wording. Everything else — resolving the property,
 * formatting dates, building the link — happens once, here.
 *
 * Like the booking email, this never invents a value: fields the booking does
 * not have are left out of the payload instead of being defaulted.
 */
abstract class BookingStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $bookingId
    ) {}

    /** Stable machine-readable key the clients switch on. */
    abstract protected function typeKey(): string;

    abstract protected function title(): string;

    /**
     * @param  array<string,mixed>  $booking
     * @param  array<string,mixed>  $property
     */
    abstract protected function body(array $booking, array $property): string;

    /**
     * @return array<int,string>
     */
    public function via(object $notifiable): array
    {
        // Database only. Mail already goes out separately via
        // ResendMailService, so adding a 'mail' channel here would send the
        // same event twice.
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        $booking = Booking::find($this->bookingId);

        if (! $booking) {
            // The booking vanished before the notification rendered. Keep the
            // generic copy rather than dropping the event.
            return [
                'title' => $this->title(),
                'body'  => 'A booking update is available in your account.',
                'url'   => '/my-booking',
            ];
        }

        $property = Property::find($booking->room?->property_id);

        return [
            'title'        => $this->title(),
            'body'         => $this->body($this->bookingData($booking), $this->propertyData($property)),
            'booking_id'   => $booking->id,
            'booking_code' => $booking->booking_code,
            'booking_status' => $booking->status,
            'url'          => '/my-booking',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bookingData(Booking $booking): array
    {
        return [
            'code'      => $booking->booking_code,
            'status'    => $booking->status,
            'check_in'  => $this->formatDate($booking->check_in),
            'check_out' => $this->formatDate($booking->check_out),
            'total'     => isset($booking->total_price)
                ? 'TSh ' . number_format((float) $booking->total_price)
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function propertyData(?Property $property): array
    {
        return [
            'name' => $property?->name,
            'city' => $property?->city,
        ];
    }

    protected function formatDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d M Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Assemble "Property, 09 Oct – 12 Oct" from whatever is actually known.
     * Skips any segment with no real value.
     */
    protected function staySummary(array $booking, array $property): string
    {
        $parts = array_values(array_filter([
            $property['name'] ?? null,
            ($booking['check_in'] ?? null) && ($booking['check_out'] ?? null)
                ? "{$booking['check_in']} – {$booking['check_out']}"
                : ($booking['check_in'] ?? null),
        ]));

        return $parts ? implode(', ', $parts) : 'your booking';
    }

    protected function reference(array $booking): string
    {
        return isset($booking['code']) && $booking['code'] !== ''
            ? " Ref {$booking['code']}."
            : '';
    }
}
