<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomLock;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RoomAvailabilityService
{
    /**
     * Statuses that mean a human deliberately took the room out of service.
     * syncRoomOccupancy() must never overwrite these with 'available'.
     */
    public const MAINTENANCE_STATUSES = ['maintenance', 'out_of_service', 'inactive', 'disabled'];

    /**
     * Statuses that mean a guest is committed to occupying the room tonight.
     *
     * Deliberately an allow-list, not a deny-list of cancelled/rejected: a new
     * status must not silently start counting as occupancy. Unpaid bookings
     * (Pending) and finished stays (Completed) do not occupy.
     */
    public const OCCUPYING_STATUSES = ['confirmed', 'checked in'];

    /**
     * Keep rooms.status honest as bookings come and go.
     *
     * rooms.status is a coarse "occupied right now" flag — real bookability is
     * decided night-by-night in checkRoomAvailability() from overlapping bookings
     * and locks, never from this column. It was previously only ever *set* to
     * 'booked' on payment success and never released, so a room stayed flagged
     * "booked" forever after its first stay.
     *
     * @return string The status written, or the untouched status if left alone.
     */
    public function syncRoomOccupancy(int $roomId, ?Room $roomModel = null): string
    {
        $room = $roomModel ?? Room::find($roomId);

        if (!$room) {
            return 'missing';
        }

        // Never clobber an admin's maintenance / out-of-service decision.
        $current = strtolower((string) ($room->status ?? 'available'));
        if (in_array($current, self::MAINTENANCE_STATUSES, true)) {
            return (string) $room->status;
        }

        $today = Carbon::today();

        $occupied = Booking::where('room_id', $roomId)
            ->whereIn(DB::raw('LOWER(status)'), self::OCCUPYING_STATUSES)
            ->whereDate('check_in', '<=', $today)
            ->whereDate('check_out', '>', $today)
            ->exists();

        $target = $occupied ? 'booked' : 'available';

        if ($current !== $target) {
            $room->update(['status' => $target]);
            Log::info("RoomAvailability: room #{$roomId} status {$current} -> {$target}.");
        }

        return $target;
    }

    /**
     * Determine night-by-night availability for a given room or total inventory over a date range.
     *
     * PERFORMANCE FIX: Previously fired 2 DB queries per night (N×2 queries total).
     * Now fires exactly 2 bulk queries regardless of date range length, then
     * counts per-night occupancy in PHP memory.
     *
     * @param int $roomId The database ID of the room (or room category instance)
     * @param string $checkIn Date string YYYY-MM-DD (check-in inclusive)
     * @param string $checkOut Date string YYYY-MM-DD (check-out exclusive)
     * @param int $requestedQuantity Number of units requested (e.g. 2 rooms)
     * @param int|null $excludeGuestId Optional guest ID (for re-checking or current user hold locks)
     * @param int|null $excludeBookingId Optional booking ID (for booking updates)
     * @param Room|null $roomModel Pre-loaded Room model to avoid an extra DB lookup
     * @return array Detailed breakdown containing overall boolean status and per-night inventory availability
     */
    public function checkRoomAvailability(
        int $roomId,
        string $checkIn,
        string $checkOut,
        int $requestedQuantity = 1,
        ?int $excludeGuestId = null,
        ?int $excludeBookingId = null,
        ?Room $roomModel = null
    ): array {
        $t0 = microtime(true);

        // Use pre-loaded room model if provided (avoids an extra DB round-trip)
        $room = $roomModel ?? Room::find($roomId);
        if (!$room) {
            throw new InvalidArgumentException("Room with ID {$roomId} does not exist.");
        }

        $start = Carbon::parse($checkIn)->startOfDay();
        $end   = Carbon::parse($checkOut)->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Check-out date must be after check-in date.');
        }

        // Room status maintenance check
        $statusLower    = strtolower($room->status ?? 'available');
        $isMaintenance  = in_array($statusLower, ['maintenance', 'out_of_service', 'inactive', 'disabled']);
        if ($isMaintenance) {
            return [
                'is_available'           => false,
                'requested_quantity'     => $requestedQuantity,
                'min_available_inventory'=> 0,
                'unavailability_reason'  => 'Room is currently under maintenance or out of service.',
                'nightly_breakdown'      => [],
            ];
        }

        // Total inventory for this room record.
        //
        // A NULL column means "not configured" and safely defaults to one unit.
        // An explicit 0 is a deliberate zero-inventory room and must stay 0 -
        // the previous max(1, ...) turned it back into a bookable room.
        $totalInventory = $room->total_inventory === null
            ? 1
            : max(0, (int) $room->total_inventory);

        if ($totalInventory === 0) {
            return [
                'is_available'            => false,
                'requested_quantity'      => $requestedQuantity,
                'min_available_inventory' => 0,
                'unavailability_reason'   => 'This room has no units available.',
                'nightly_breakdown'       => [],
            ];
        }

        // ─── PERFORMANCE FIX: 2 bulk queries instead of 2×N per-night queries ───────
        //
        // Fetch ALL overlapping confirmed bookings in ONE query.
        // Overlap condition: booking.check_in < requested check_out AND booking.check_out > requested check_in
        $bookingsQuery = Booking::where('room_id', $roomId)
            ->whereNotIn('status', ['Cancelled', 'cancelled', 'Rejected', 'rejected'])
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn);

        if ($excludeBookingId) {
            $bookingsQuery->where('id', '!=', $excludeBookingId);
        }

        $overlappingBookings = $bookingsQuery->get(['check_in', 'check_out']);

        // Fetch ALL active overlapping room locks in ONE query.
        $locksQuery = RoomLock::where('room_id', $roomId)
            ->where('expires_at', '>', now())
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn);

        if ($excludeGuestId) {
            $locksQuery->where('guest_id', '!=', $excludeGuestId);
        }

        $overlappingLocks = $locksQuery->get(['check_in', 'check_out']);

        Log::debug('RoomAvailability: bulk queries done', [
            'room_id'   => $roomId,
            'bookings'  => $overlappingBookings->count(),
            'locks'     => $overlappingLocks->count(),
            'ms'        => round((microtime(true) - $t0) * 1000),
        ]);
        // ─────────────────────────────────────────────────────────────────────────────

        // Generate nightly date periods [check_in, check_out - 1 day]
        $nightsPeriod = CarbonPeriod::create($start, '1 day', $end->copy()->subDay());

        $nightlyBreakdown       = [];
        $minAvailableInventory  = $totalInventory;
        $isAvailableForAllNights = true;
        $failureReason          = null;

        // Count occupancy per-night in PHP memory (fast — no further DB queries)
        foreach ($nightsPeriod as $nightDate) {
            $dateStr = $nightDate->format('Y-m-d');

            // Count confirmed bookings that cover this specific night
            $confirmedCount = $overlappingBookings->filter(function ($booking) use ($dateStr) {
                return $booking->check_in <= $dateStr && $booking->check_out > $dateStr;
            })->count();

            // Count active locks that cover this specific night
            $blockedCount = $overlappingLocks->filter(function ($lock) use ($dateStr) {
                return $lock->check_in <= $dateStr && $lock->check_out > $dateStr;
            })->count();

            // Total reserved/blocked inventory for this night
            $totalOccupied      = $confirmedCount + $blockedCount;
            $availableInventory = max(0, $totalInventory - $totalOccupied);

            if ($availableInventory < $minAvailableInventory) {
                $minAvailableInventory = $availableInventory;
            }

            $nightSatisfied = ($availableInventory >= $requestedQuantity);
            if (!$nightSatisfied) {
                $isAvailableForAllNights = false;
                if (!$failureReason) {
                    if ($availableInventory === 0) {
                        $failureReason = "No inventory available on {$dateStr}.";
                    } else {
                        $failureReason = "Only {$availableInventory} room(s) available on {$dateStr}, but {$requestedQuantity} was requested.";
                    }
                }
            }

            $nightlyBreakdown[] = [
                'date'                => $dateStr,
                'total_inventory'     => $totalInventory,
                'confirmed_inventory' => $confirmedCount,
                'blocked_inventory'   => $blockedCount,
                'available_inventory' => $availableInventory,
                'requested_quantity'  => $requestedQuantity,
                'is_available'        => $nightSatisfied,
            ];
        }

        Log::debug('RoomAvailability: nightly breakdown done', [
            'room_id' => $roomId,
            'nights'  => count($nightlyBreakdown),
            'total_ms'=> round((microtime(true) - $t0) * 1000),
        ]);

        return [
            'is_available'            => $isAvailableForAllNights,
            'requested_quantity'      => $requestedQuantity,
            'min_available_inventory' => $minAvailableInventory,
            'unavailability_reason'   => $failureReason,
            'nightly_breakdown'       => $nightlyBreakdown,
        ];
    }

    /**
     * Check availability for multiple room selections at once for a property stay.
     *
     * @param int $propertyId
     * @param string $checkIn
     * @param string $checkOut
     * @param array $roomSelections Array of ['room_id' => int, 'quantity' => int]
     * @param int|null $excludeGuestId
     * @return array Keyed by room_id with availability result
     */
    public function checkMultipleRoomsAvailability(
        int $propertyId,
        string $checkIn,
        string $checkOut,
        array $roomSelections,
        ?int $excludeGuestId = null
    ): array {
        $results = [];
        foreach ($roomSelections as $selection) {
            $roomId   = (int) ($selection['room_id'] ?? 0);
            $quantity = max(1, (int) ($selection['quantity'] ?? 1));

            $results[$roomId] = $this->checkRoomAvailability(
                $roomId,
                $checkIn,
                $checkOut,
                $quantity,
                $excludeGuestId
            );
        }

        return $results;
    }
}
