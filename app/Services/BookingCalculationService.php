<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use Carbon\Carbon;
use InvalidArgumentException;

class BookingCalculationService
{
    /**
     * Standard VAT rate (0% included in hospitality or separate tax if applicable)
     */
    public const TAX_RATE = '0.00';

    /**
     * AzamPay Payment Processing Fee rate (1% mandatory customer fee)
     */
    public const AZAMPAY_FEE_RATE = '0.01';

    /**
     * Service fee rate (0% default, or fixed fee per night/booking)
     */
    public const SERVICE_FEE_RATE = '0.00';

    /**
     * Calculate nights with strict check-in inclusive, check-out exclusive rule.
     * Example: 20 Aug to 23 Aug => 3 nights.
     *
     * @param string $checkIn
     * @param string $checkOut
     * @return int
     */
    public function calculateNights(string $checkIn, string $checkOut): int
    {
        $start = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Check-out date must be after check-in date.');
        }

        return (int) $start->diffInDays($end);
    }

    /**
     * Authoritative calculation engine for one or multiple rooms.
     *
     * @param int $propertyId
     * @param string $checkIn
     * @param string $checkOut
     * @param array $roomSelections Array of items with keys: ['room_id' => int, 'quantity' => int]
     * @param int $guests Total guest count across the booking
     * @param string|null $promoCode Optional discount/promo code
     * @return array
     */
    public function calculate(
        int $propertyId,
        string $checkIn,
        string $checkOut,
        array $roomSelections,
        int $guests = 1,
        ?string $promoCode = null
    ): array {
        $property = Property::with('rooms')->findOrFail($propertyId);
        $nights = $this->calculateNights($checkIn, $checkOut);

        $calculatedRooms = [];
        $totalSubtotal = '0.00';
        $totalCapacity = 0;
        $totalQuantity = 0;

        foreach ($roomSelections as $selection) {
            $roomId = (int) ($selection['room_id'] ?? 0);
            $quantity = max(1, (int) ($selection['quantity'] ?? 1));

            /** @var Room|null $room */
            $room = $property->rooms->firstWhere('id', $roomId);
            if (!$room) {
                throw new InvalidArgumentException("Room ID {$roomId} does not belong to Property ID {$propertyId}.");
            }

            // Authoritative per-night inventory availability check
            /** @var RoomAvailabilityService $availabilityService */
            $availabilityService = app(RoomAvailabilityService::class);
            $availabilityResult = $availabilityService->checkRoomAvailability(
                $room->id,
                $checkIn,
                $checkOut,
                $quantity
            );

            $isAvailable = $availabilityResult['is_available'];
            $unavailabilityReason = $availabilityResult['unavailability_reason'];

            // Authoritative DB rate (numeric precision).
            //
            // Previously fell back to a hardcoded '85000.00', so a room with
            // no configured price silently became an 85,000 TSh booking and
            // the guest was charged it. A missing price is a configuration
            // error, not a price.
            $configuredPrice = $room->price ?? $property->price_per_night;

            if ($configuredPrice === null || (float) $configuredPrice <= 0) {
                throw new \RuntimeException(sprintf(
                    'Room %s (property %s) has no nightly rate configured.',
                    $room->id,
                    $property->id
                ));
            }

            $nightlyRate = number_format((float)$configuredPrice, 2, '.', '');

            // room subtotal = nightly rate * nights * quantity
            $roomSubtotal = bcmul(bcmul($nightlyRate, (string)$nights, 4), (string)$quantity, 2);

            $totalSubtotal = bcadd($totalSubtotal, $roomSubtotal, 2);
            $roomCapacity = (int)($room->capacity ?? 2);
            $totalCapacity += ($roomCapacity * $quantity);
            $totalQuantity += $quantity;

            // Compute per-room AzamPay 1% processing fee and customer nightly price
            $roomProcessingFee = bcmul($roomSubtotal, self::AZAMPAY_FEE_RATE, 2);
            $roomCustomerSubtotal = bcadd($roomSubtotal, $roomProcessingFee, 2);
            $nightlyFee = bcmul($nightlyRate, self::AZAMPAY_FEE_RATE, 2);
            $customerNightlyRate = bcadd($nightlyRate, $nightlyFee, 2);

            $calculatedRooms[] = [
                'room_id'                     => $room->id,
                'room_number'                 => $room->room_number,
                'title'                       => $room->title ?: ($room->room_number ? "Room {$room->room_number}" : null),
                'owner_nightly_rate'          => (float) $nightlyRate,
                'owner_nightly_rate_formatted'=> 'TSh ' . number_format((float)$nightlyRate),
                'processing_fee_per_night'    => (float) $nightlyFee,
                'nightly_rate'                => (float) $customerNightlyRate,
                'nightly_rate_formatted'      => 'TSh ' . number_format((float)$customerNightlyRate),
                'quantity'                    => $quantity,
                'nights'                      => $nights,
                'capacity_per_room'           => $roomCapacity,
                'max_adults'                  => $room->max_adults ?? $roomCapacity,
                'bed_configuration'           => $room->bed_configuration,
                'owner_subtotal'              => (float) $roomSubtotal,
                'processing_fee'              => (float) $roomProcessingFee,
                'subtotal'                    => (float) $roomCustomerSubtotal,
                'subtotal_formatted'          => 'TSh ' . number_format((float)$roomCustomerSubtotal),
                'fee_note'                    => 'Includes payment processing fee',
                'is_available'                => $isAvailable,
                'unavailability_reason'       => $unavailabilityReason,
            ];
        }

        // Processing Fee: AzamPay 1% processing fee computed authoritatively across total room subtotal
        $azampayFee = bcmul($totalSubtotal, self::AZAMPAY_FEE_RATE, 2);

        // Taxes & additional service fees
        $taxes = bcmul($totalSubtotal, self::TAX_RATE, 2);
        $fees = bcmul($totalSubtotal, self::SERVICE_FEE_RATE, 2);

        // Discounts: Promocode or special stay discounts
        $discountAmount = '0.00';
        $discountDescription = null;
        if ($promoCode) {
            $codeUpper = strtoupper(trim($promoCode));
            if ($codeUpper === 'FASTNET10') {
                $discountAmount = bcmul($totalSubtotal, '0.10', 2);
                $discountDescription = '10% Member Welcome Discount';
            } elseif ($codeUpper === 'SAFARI5') {
                $discountAmount = bcmul($totalSubtotal, '0.05', 2);
                $discountDescription = '5% Safari Season Promo';
            }
        }

        // Total = (Owner Subtotal - Discount) + AzamPay Fee + Taxes + Fees
        $discountedSubtotal = bcsub($totalSubtotal, $discountAmount, 2);
        if (bccomp($discountedSubtotal, '0.00', 2) < 0) {
            $discountedSubtotal = '0.00';
        }
        $totalAmount = bcadd(bcadd(bcadd($discountedSubtotal, $azampayFee, 2), $taxes, 2), $fees, 2);

        return [
            'valid'                => true,
            'property'             => [
                'id'          => $property->id,
                'name'        => $property->name,
                'city'        => $property->city,
                'address'     => trim("{$property->area}, {$property->city}", ', '),
                'main_image'  => $property->main_image ?? 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=600&q=80',
            ],
            'check_in'             => $checkIn,
            'check_out'            => $checkOut,
            'nights'               => $nights,
            'guests'               => $guests,
            'total_capacity'       => $totalCapacity,
            'capacity_satisfied'   => ($totalCapacity >= $guests),
            'currency'             => 'TZS',
            'grand_total'          => (float) $totalAmount,
            'rooms_count'          => $totalQuantity,
            'rooms'                => $calculatedRooms,
            'pricing'              => [
                'currency'                  => 'TZS',
                'owner_base_subtotal'       => (float) $totalSubtotal,
                'owner_base_subtotal_formatted' => 'TSh ' . number_format((float)$totalSubtotal),
                'azampay_fee_rate'          => (float) self::AZAMPAY_FEE_RATE,
                'azampay_fee'               => (float) $azampayFee,
                'azampay_fee_formatted'     => 'TSh ' . number_format((float)$azampayFee),
                'fee_note'                  => 'Includes payment processing fee',
                'subtotal'                  => (float) bcadd($totalSubtotal, $azampayFee, 2),
                'subtotal_formatted'        => 'TSh ' . number_format((float)bcadd($totalSubtotal, $azampayFee, 2)),
                'tax_rate'                  => (float) self::TAX_RATE,
                'taxes'                     => (float) $taxes,
                'taxes_formatted'           => 'TSh ' . number_format((float)$taxes),
                'fees'                      => (float) $fees,
                'fees_formatted'            => 'TSh ' . number_format((float)$fees),
                'discount'                  => (float) $discountAmount,
                'discount_formatted'        => 'TSh ' . number_format((float)$discountAmount),
                'discount_label'            => $discountDescription,
                'total'                     => (float) $totalAmount,
                'grand_total'               => (float) $totalAmount,
                'total_formatted'           => 'TSh ' . number_format((float)$totalAmount),
            ],
            // Report the property's actual policy. This previously asserted
            // "Free cancellation before {date}" on every quote regardless of
            // what the lodge had agreed, and that text reached the guest
            // through BookingQuoteService and the checkout page.
            'cancellation_policy' => $property->cancellation_policy ?: null,
        ];
    }
}
