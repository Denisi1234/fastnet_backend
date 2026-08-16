<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Room;

class BookingRevalidationService
{
    protected BookingCalculationService $calculator;

    public function __construct(BookingCalculationService $calculator)
    {
        $this->calculator = $calculator;
    }

    public function revalidate(int $propertyId, ?int $roomId, string $checkIn, string $checkOut, int $guests = 2, int $quantity = 1): array
    {
        $property = Property::with(['rooms'])->find($propertyId);

        $propStatus = strtolower($property->status ?? 'active');
        if (!$property || $propStatus !== 'active') {
            return [
                'status' => 404,
                'data' => [
                    'valid' => false,
                    'error_type' => 'property_unavailable',
                    'message' => 'The selected property is not currently active or available for booking.'
                ]
            ];
        }

        $room = null;
        if ($roomId) {
            $room = Room::where('id', $roomId)->where('property_id', $property->id)->first();
        }
        if (!$room) {
            $room = $property->rooms->first();
        }

        if (!$room) {
            return [
                'status' => 404,
                'data' => [
                    'valid' => false,
                    'error_type' => 'no_rooms',
                    'message' => 'No rooms listed for this property.'
                ]
            ];
        }

        try {
            $calculation = $this->calculator->calculate(
                (int)$property->id,
                $checkIn,
                $checkOut,
                [['room_id' => $room->id, 'quantity' => $quantity]],
                $guests
            );
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 422,
                'data' => [
                    'valid' => false,
                    'message' => $e->getMessage(),
                ]
            ];
        }

        $roomResult = $calculation['rooms'][0] ?? null;
        if (!$roomResult || !$roomResult['is_available']) {
            return [
                'status' => 409,
                'data' => [
                    'valid' => false,
                    'error_type' => 'dates_unavailable',
                    'message' => $roomResult['unavailability_reason'] ?? 'This room is no longer available for your selected stay dates.'
                ]
            ];
        }

        if (!$calculation['capacity_satisfied']) {
            return [
                'status' => 422,
                'data' => [
                    'valid' => false,
                    'error_type' => 'capacity_exceeded',
                    'message' => "Selected {$quantity} room(s) can accommodate a maximum of {$calculation['total_capacity']} guests.",
                    'max_capacity' => $calculation['total_capacity']
                ]
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'valid' => true,
                'property' => $calculation['property'],
                'room' => [
                    'id' => $room->id,
                    'title' => $roomResult['title'],
                    'bed_configuration' => $roomResult['bed_configuration'],
                    'capacity' => $roomResult['capacity_per_room'],
                    'max_adults' => $roomResult['max_adults'],
                ],
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nights' => $calculation['nights'],
                'guests' => $guests,
                'quantity' => $quantity,
                'nightly_rate' => $roomResult['nightly_rate'],
                'subtotal' => $calculation['pricing']['subtotal'],
                'taxes' => $calculation['pricing']['taxes'],
                'fees' => $calculation['pricing']['fees'],
                'discount' => $calculation['pricing']['discount'],
                'total_price' => $calculation['pricing']['total'],
                'pricing' => $calculation['pricing'],
                'cancellation_policy' => $calculation['cancellation_policy'],
            ]
        ];
    }
}
