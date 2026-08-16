<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\NextSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued Job: Send booking confirmation SMS to guest phone number.
 * Dispatched after booking is created — guest doesn't wait for this.
 */
class SendBookingConfirmationSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int $bookingId
    ) {}

    public function handle(): void
    {
        $booking = Booking::with(['room.property', 'guest'])->find($this->bookingId);

        if (!$booking || empty($booking->guest?->phone_number)) {
            return; // No phone number, skip silently
        }

        $guest    = $booking->guest;
        $property = $booking->room->property;
        $checkIn  = \Carbon\Carbon::parse($booking->check_in)->format('d M Y');
        $checkOut = \Carbon\Carbon::parse($booking->check_out)->format('d M Y');
        $price    = 'TSh ' . number_format($booking->total_price);
        $code     = $booking->booking_code ?? "BK{$booking->id}";

        $message = "FastNetStays: Hi {$guest->name}! Your booking at {$property->name} is confirmed. "
                 . "Check-in: {$checkIn}, Check-out: {$checkOut}. Total: {$price}. "
                 . "Ref: {$code}. Enjoy your stay!";

        try {
            NextSmsService::sendSms($guest->phone_number, $message);
            Log::info("Booking SMS sent to {$guest->phone_number} for booking #{$this->bookingId}.");
        } catch (\Exception $e) {
            Log::error("Failed to send booking SMS for booking #{$this->bookingId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SendBookingConfirmationSms permanently failed for booking #{$this->bookingId}: " . $exception->getMessage());
    }
}
