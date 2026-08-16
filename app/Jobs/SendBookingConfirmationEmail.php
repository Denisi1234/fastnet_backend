<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\User;
use App\Services\ResendMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued Job: Send booking confirmation email to guest.
 * Dispatched after booking is created — guest doesn't wait for this.
 */
class SendBookingConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // seconds between retries

    public function __construct(
        public readonly int $bookingId
    ) {}

    public function handle(): void
    {
        $booking = Booking::with(['room.property', 'guest'])->find($this->bookingId);

        if (!$booking) {
            Log::warning("SendBookingConfirmationEmail: Booking #{$this->bookingId} not found.");
            return;
        }

        $guest = $booking->guest;
        $property = $booking->room->property ?? null;

        if (!$guest || !$property) {
            Log::warning("SendBookingConfirmationEmail: Missing guest or property for booking #{$this->bookingId}.");
            return;
        }

        try {
            ResendMailService::sendBookingConfirmation($guest, $booking, $property);
            Log::info("Booking confirmation email sent to {$guest->email} for booking #{$this->bookingId}.");
        } catch (\Exception $e) {
            Log::error("Failed to send booking confirmation email for booking #{$this->bookingId}: " . $e->getMessage());
            throw $e; // Re-throw so the queue retries
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendBookingConfirmationEmail permanently failed for booking #{$this->bookingId}: " . $exception->getMessage());
    }
}
