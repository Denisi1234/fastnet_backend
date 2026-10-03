<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingCompleted;
use App\Notifications\BookingConfirmed;
use App\Notifications\PaymentReceipt;
use Illuminate\Support\Facades\Log;

/**
 * The single place booking events become in-app notifications.
 *
 * Controllers call these instead of touching notify() directly, so the set of
 * guest-facing events stays enumerable in one file.
 *
 * Notifications are sent synchronously: the classes deliberately do not
 * implement ShouldQueue, because the database channel writes on notify() and
 * the notifications queue is not reliably consumed in this deployment.
 */
class BookingNotificationService
{
    /**
     * Payment succeeded and the booking is now Confirmed.
     */
    public function notifyConfirmed(Booking $booking): void
    {
        $this->toGuest($booking, new BookingConfirmed($booking->id));
    }

    /**
     * Guest receives their receipt. Kept separate from the confirmation so a
     * failure to build one does not lose the other.
     */
    public function notifyReceipt(Booking $booking): void
    {
        $this->toGuest($booking, new PaymentReceipt($booking->id));
    }

    public function notifyCancelled(Booking $booking): void
    {
        $this->toGuest($booking, new BookingCancelled($booking->id));
    }

    public function notifyCompleted(Booking $booking): void
    {
        $this->toGuest($booking, new BookingCompleted($booking->id));
    }

    /**
     * Notify the guest, never letting a notification failure roll back the
     * booking state change that triggered it.
     */
    private function toGuest(Booking $booking, object $notification): void
    {
        $guest = $booking->guest ?? User::find($booking->guest_id);

        if (! $guest) {
            Log::warning("BookingNotification: no guest for booking #{$booking->id} — skipped.");
            return;
        }

        try {
            $guest->notify($notification);
        } catch (\Throwable $e) {
            // A missing notification must never fail the booking itself.
            Log::error(
                "BookingNotification: failed for booking #{$booking->id}: " . $e->getMessage()
            );
        }
    }
}
