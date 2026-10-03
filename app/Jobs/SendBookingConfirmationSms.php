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
 * Queued Job: Send booking status SMS to guest phone number.
 *
 * The wording is derived from the booking's actual status. It previously hardcoded
 * "your booking ... is confirmed" regardless of state, so firing it while the
 * booking was still Pending told the guest their stay was confirmed when no money
 * had arrived.
 */
class SendBookingConfirmationSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int $bookingId
    ) {}

    /**
     * Human sentence describing what the booking's status actually means.
     */
    private static function statusSentence(string $status): string
    {
        return match (strtolower($status)) {
            'pending', 'pending verification' => 'is awaiting payment',
            'confirmed'                      => 'is confirmed',
            'checked in'                     => '— you have now checked in',
            'completed'                      => 'is now complete',
            'cancelled'                      => 'has been cancelled',
            default                          => "is currently {$status}",
        };
    }

    public function handle(): void
    {
        $booking = Booking::with(['room.property', 'guest'])->find($this->bookingId);

        if (!$booking || empty($booking->guest?->phone_number)) {
            return; // No phone number, skip silently
        }

        $guest    = $booking->guest;
        $property = $booking->room->property ?? null;

        if (!$property) {
            Log::warning("SendBookingConfirmationSms: no property for booking #{$this->bookingId}.");
            return;
        }

        $checkIn  = \Carbon\Carbon::parse($booking->check_in)->format('d M Y');
        $checkOut = \Carbon\Carbon::parse($booking->check_out)->format('d M Y');
        $price    = 'TSh ' . number_format((float) $booking->total_price);
        $code     = $booking->booking_code ?? "BK{$booking->id}";

        $message = "FastNetStays: Hi {$guest->name}! Your booking at {$property->name} "
                 . self::statusSentence((string) $booking->status) . ". "
                 . "Check-in: {$checkIn}, Check-out: {$checkOut}. Total: {$price}. "
                 . "Ref: {$code}.";

        try {
            NextSmsService::sendSms($guest->phone_number, $message);
            Log::info("Booking SMS sent to {$guest->phone_number} for booking #{$this->bookingId} (status={$booking->status}).");
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
