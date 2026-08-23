<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomLock;
use App\Jobs\InvalidatePropertyCache;
use App\Jobs\SendBookingConfirmationEmail;
use App\Jobs\SendBookingConfirmationSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingCreationService
{
    protected BookingCalculationService $calculator;
    protected RoomAvailabilityService $availabilityService;

    public function __construct(BookingCalculationService $calculator, RoomAvailabilityService $availabilityService)
    {
        $this->calculator = $calculator;
        $this->availabilityService = $availabilityService;
    }

    public function createBooking(int $roomId, int $guestId, string $checkIn, string $checkOut, int $quantity, int $guests, ?string $promoCode = null): array
    {
        $lockKey = "booking_lock:room_{$roomId}";

        $acquired = false;
        try {
            $acquired = Redis::funnel($lockKey)->limit(1)->then(function () {
                return true;
            }, function () {
                return false;
            });
        } catch (\Exception $e) {
            Log::warning('Redis connection failed, falling back to database locking: ' . $e->getMessage());
            $acquired = true;
        }

        if (!$acquired) {
            return [
                'success' => false,
                'status' => 429,
                'message' => 'Room booking is currently being processed. Please try again in a few seconds.'
            ];
        }

        return DB::transaction(function () use ($roomId, $guestId, $checkIn, $checkOut, $quantity, $guests, $promoCode) {
            $room = Room::with('property')->where('id', $roomId)->lockForUpdate()->first();
            if (!$room) {
                return [
                    'success' => false,
                    'status' => 444,
                    'message' => 'Room not found.'
                ];
            }

            $availability = $this->availabilityService->checkRoomAvailability(
                $roomId,
                $checkIn,
                $checkOut,
                $quantity,
                $guestId
            );

            if (!$availability['is_available']) {
                return [
                    'success' => false,
                    'status' => 409,
                    'message' => 'Double booking prevented! ' . ($availability['unavailability_reason'] ?? 'This room is no longer available for your selected dates.')
                ];
            }

            try {
                $calc = $this->calculator->calculate(
                    (int) $room->property_id,
                    $checkIn,
                    $checkOut,
                    [['room_id' => $room->id, 'quantity' => $quantity]],
                    $guests,
                    $promoCode
                );
            } catch (\InvalidArgumentException $e) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => $e->getMessage()
                ];
            }

            if (!$calc['capacity_satisfied']) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => "Selected {$quantity} room(s) can accommodate a maximum of {$calc['total_capacity']} guests."
                ];
            }

            $totalPrice = (float) $calc['pricing']['total'];
            $commissionRate = 10.00; // Authoritative 10% platform fee
            $platformFee = round($totalPrice * ($commissionRate / 100), 2);
            $ownerPayout = round($totalPrice - $platformFee, 2);

            $booking = Booking::create([
                'room_id'         => $roomId,
                'guest_id'        => $guestId,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
                'total_price'     => $totalPrice,
                'commission_rate' => $commissionRate,
                'platform_fee'    => $platformFee,
                'owner_payout'    => $ownerPayout,
                'status'          => 'Pending',
                'payment_status'  => 'pending',
                'booking_code'    => 'BK' . strtoupper(Str::random(8)),
            ]);

            RoomLock::where('room_id', $roomId)->where('guest_id', $guestId)->delete();

            SendBookingConfirmationEmail::dispatch($booking->id)->onQueue('notifications');
            SendBookingConfirmationSms::dispatch($booking->id)->onQueue('notifications');
            InvalidatePropertyCache::dispatch($room->property_id, $room->property->city ?? null)->onQueue('cache');

            return [
                'success' => true,
                'status' => 201,
                'data' => [
                    'message'      => 'Booking initialized successfully.',
                    'booking'      => $booking,
                    'booking_code' => $booking->booking_code,
                    'currency'     => $calc['currency'],
                    'nights'       => $calc['nights'],
                    'guests'       => $calc['guests'],
                    'quantity'     => $quantity,
                    'room_details' => $calc['rooms'],
                    'nightly_rate' => $calc['rooms'][0]['nightly_rate'] ?? 0,
                    'subtotal'     => $calc['pricing']['subtotal'],
                    'tax'          => $calc['pricing']['taxes'],
                    'fees'         => $calc['pricing']['fees'],
                    'discount'     => $calc['pricing']['discount'],
                    'grand_total'  => $calc['pricing']['grand_total'],
                    'total_price'  => $calc['pricing']['grand_total'],
                    'pricing'      => $calc['pricing'],
                ]
            ];
        });
    }
}
