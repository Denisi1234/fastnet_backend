<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomLock;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

class RoomAvailabilityService
{
    /**
     * Determine night-by-night availability for a given room or total inventory over a date range.
     *
     * @param int $roomId The database ID of the room (or room category instance)
     * @param string $checkIn Date string YYYY-MM-DD (check-in inclusive)
     * @param string $checkOut Date string YYYY-MM-DD (check-out exclusive)
     * @param int $requestedQuantity Number of units requested (e.g. 2 rooms)
     * @param int|null $excludeGuestId Optional guest ID (for re-checking or current user hold locks)
     * @param int|null $excludeBookingId Optional booking ID (for booking updates)
     * @return array Detailed breakdown containing overall boolean status and per-night inventory availability
     */
    public function checkRoomAvailability(
        int $roomId,
        string $checkIn,
        string $checkOut,
        int $requestedQuantity = 1,
        ?int $excludeGuestId = null,
        ?int $excludeBookingId = null
    ): array {
        $room = Room::find($roomId);
        if (!$room) {
            throw new InvalidArgumentException("Room with ID {$roomId} does not exist.");
        }

        $start = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Check-out date must be after check-in date.');
        }

        // Room status maintenance check
        $statusLower = strtolower($room->status ?? 'available');
        $isMaintenance = in_array($statusLower, ['maintenance', 'out_of_service', 'inactive', 'disabled']);
        if ($isMaintenance) {
            return [
                'is_available' => false,
                'requested_quantity' => $requestedQuantity,
                'min_available_inventory' => 0,
                'unavailability_reason' => 'Room is currently under maintenance or out of service.',
                'nightly_breakdown' => [],
            ];
        }

        // Total inventory for this room record (defaults to total_inventory column or 1 unit)
        $totalInventory = max(1, (int) ($room->total_inventory ?? 1));

        // Generate nightly date periods [check_in, check_out - 1 day]
        $nightsPeriod = CarbonPeriod::create($start, '1 day', $end->copy()->subDay());

        $nightlyBreakdown = [];
        $minAvailableInventory = $totalInventory;
        $isAvailableForAllNights = true;
        $failureReason = null;

        foreach ($nightsPeriod as $nightDate) {
            $dateStr = $nightDate->format('Y-m-d');

            // 1. Confirmed / Active overlapping bookings for this specific night
            // Strict date overlap logic: check_in <= date AND check_out > date
            $bookingsQuery = Booking::where('room_id', $roomId)
                ->whereNotIn('status', ['Cancelled', 'cancelled', 'Rejected', 'rejected'])
                ->whereDate('check_in', '<=', $dateStr)
                ->whereDate('check_out', '>', $dateStr);

            if ($excludeBookingId) {
                $bookingsQuery->where('id', '!=', $excludeBookingId);
            }

            $confirmedCount = (int) $bookingsQuery->count();

            // 2. Active temporary locks held by other users
            $locksQuery = RoomLock::where('room_id', $roomId)
                ->where('expires_at', '>', now())
                ->whereDate('check_in', '<=', $dateStr)
                ->whereDate('check_out', '>', $dateStr);

            if ($excludeGuestId) {
                $locksQuery->where('guest_id', '!=', $excludeGuestId);
            }

            $blockedCount = (int) $locksQuery->count();

            // Total reserved/blocked inventory for this night
            $totalOccupied = $confirmedCount + $blockedCount;
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
                'date' => $dateStr,
                'total_inventory' => $totalInventory,
                'confirmed_inventory' => $confirmedCount,
                'blocked_inventory' => $blockedCount,
                'available_inventory' => $availableInventory,
                'requested_quantity' => $requestedQuantity,
                'is_available' => $nightSatisfied,
            ];
        }

        return [
            'is_available' => $isAvailableForAllNights,
            'requested_quantity' => $requestedQuantity,
            'min_available_inventory' => $minAvailableInventory,
            'unavailability_reason' => $failureReason,
            'nightly_breakdown' => $nightlyBreakdown,
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
            $roomId = (int) ($selection['room_id'] ?? 0);
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
