<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use App\Models\RoomLock;

class PropertyDetailService
{
    public function getPropertyDetail(Property $property, ?string $checkIn, ?string $checkOut, int $guests = 1, int $requiredRooms = 1, ?int $userId = null): array
    {
        $hasValidDates = false;
        if (!empty($checkIn) && !empty($checkOut)) {
            $cIn = strtotime($checkIn);
            $cOut = strtotime($checkOut);
            if ($cIn && $cOut && $cOut > $cIn) {
                $hasValidDates = true;
            }
        }

        $roomIds = $property->rooms->pluck('id')->toArray();
        $bookedRoomIds = [];
        $activeLocksMap = [];

        if (!empty($roomIds)) {
            if ($hasValidDates) {
                $bookedRoomIds = Booking::whereIn('room_id', $roomIds)
                    ->whereNotIn('status', ['Cancelled', 'cancelled'])
                    ->where('check_in', '<', $checkOut)
                    ->where('check_out', '>', $checkIn)
                    ->pluck('room_id')
                    ->flip()
                    ->toArray();
            }

            $activeLocks = RoomLock::whereIn('room_id', $roomIds)
                ->where('expires_at', '>', now())
                ->get();
            foreach ($activeLocks as $lock) {
                $activeLocksMap[$lock->room_id] = $lock->guest_id;
            }
        }

        foreach ($property->rooms as $room) {
            $capacityOk = true;
            if ($guests > 0) {
                $capacityOk = ($room->capacity >= $guests) ||
                             ($room->max_adults && $room->max_adults >= $guests);
            }
            $room->meets_capacity = $capacityOk;

            $isBooked = isset($bookedRoomIds[$room->id]);

            $lockedByGuestId = $activeLocksMap[$room->id] ?? null;
            if ($lockedByGuestId !== null) {
                $room->is_locked = true;
                $room->locked_by_me = ($userId && $lockedByGuestId == $userId);
            } else {
                $room->is_locked = false;
                $room->locked_by_me = false;
            }

            $isLockedByOther = $room->is_locked && !$room->locked_by_me;
            $statusLower = strtolower($room->status ?? '');
            $permanentlyUnavailable = in_array($statusLower, ['maintenance', 'out_of_service', 'inactive', 'disabled']);

            $room->is_available = $capacityOk && !$isBooked && !$isLockedByOther && !$permanentlyUnavailable;
            $room->unavailability_reason = !$capacityOk
                ? "Exceeds max room capacity ({$room->capacity} guests max)"
                : ($permanentlyUnavailable
                    ? "Room is currently under maintenance"
                    : ($isBooked
                        ? "Reserved for selected dates"
                        : ($isLockedByOther ? "Temporarily held by another guest" : null)));
        }

        return [
            'has_valid_dates' => $hasValidDates,
            'property' => $property,
        ];
    }
}
