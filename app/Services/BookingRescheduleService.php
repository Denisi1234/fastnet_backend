<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Real date changes for existing bookings (quote → apply).
 *
 * Availability is checked excluding the booking itself so its own hold
 * never blocks the move; pricing comes from the same calculator checkout
 * uses, so the guest always sees live rates. Money movement stays honest:
 * unpaid bookings simply reprice; paid bookings record any extra as a
 * pending adjustment (settled at the property) and never auto-refund —
 * shortfalls are directed to support per the cancellation policy.
 */
class BookingRescheduleService
{
    public function __construct(
        protected BookingCalculationService $calculator,
        protected RoomAvailabilityService $availability,
        protected BookingNotificationService $notifications,
        protected BookingVerifyService $verifier,
    ) {}

    /**
     * @return array{status:int, data:array}
     */
    public function quote(Booking $booking, string $checkIn, string $checkOut): array
    {
        try {
            [$in, $out] = $this->cleanDates($checkIn, $checkOut);
        } catch (InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['valid' => false, 'message' => $e->getMessage()]];
        }

        $roomId = (int) $booking->room_id;
        try {
            $available = $this->availability->checkRoomAvailability(
                $roomId,
                $in->toDateString(),
                $out->toDateString(),
                1,
                null,
                (int) $booking->id
            );
        } catch (InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['valid' => false, 'message' => $e->getMessage()]];
        }

        if (empty($available['is_available'])) {
            return ['status' => 409, 'data' => [
                'valid' => false,
                'error_type' => 'dates_unavailable',
                'message' => $available['unavailability_reason'] ?? 'The room is not available for the requested dates.',
            ]];
        }

        try {
            $calc = $this->calculator->calculate(
                (int) $booking->room->property_id,
                $in->toDateString(),
                $out->toDateString(),
                [['room_id' => $roomId, 'quantity' => 1]],
                2
            );
        } catch (InvalidArgumentException $e) {
            return ['status' => 422, 'data' => ['valid' => false, 'message' => $e->getMessage()]];
        }

        $newTotal = round((float) ($calc['grand_total'] ?? 0), 2);
        $oldTotal = round((float) $booking->total_price, 2);
        $diff = round($newTotal - $oldTotal, 2);
        $paid = strtolower((string) $booking->payment_status) === 'paid';

        return ['status' => 200, 'data' => [
            'valid' => true,
            'check_in' => $in->toDateString(),
            'check_out' => $out->toDateString(),
            'nights' => (int) $in->diffInDays($out),
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'diff' => $diff,
            'balance_due' => ($paid && $diff > 0) ? $diff : 0,
            'message' => $this->quoteMessage($paid, $diff, $newTotal),
        ]];
    }

    /**
     * @return array{status:int, data:array}
     */
    public function apply(Booking $booking, string $checkIn, string $checkOut): array
    {
        $q = $this->quote($booking, $checkIn, $checkOut);
        if (($q['data']['valid'] ?? false) !== true) {
            return $q;
        }
        $data = $q['data'];
        $paid = strtolower((string) $booking->payment_status) === 'paid';

        return DB::transaction(function () use ($booking, $data, $paid) {
            $from = Carbon::parse($booking->check_in)->format('d M Y')
                . ' – ' . Carbon::parse($booking->check_out)->format('d M Y');
            $to = Carbon::parse($data['check_in'])->format('d M Y')
                . ' – ' . Carbon::parse($data['check_out'])->format('d M Y');

            $booking->check_in = $data['check_in'];
            $booking->check_out = $data['check_out'];
            $booking->total_price = $data['new_total'];
            $booking->save();

            $balanceDue = 0.0;
            if ($paid && $data['diff'] > 0) {
                $balanceDue = $data['diff'];
                Payment::create([
                    'booking_id' => $booking->id,
                    'gateway' => 'adjustment',
                    'transaction_id' => 'ADJ-' . $booking->booking_code . '-' . now()->format('YmdHis'),
                    'amount' => $balanceDue,
                    'status' => 'pending',
                ]);
            }

            app(RoomAvailabilityService::class)->syncRoomOccupancy((int) $booking->room_id);
            $this->notifications->notifyRescheduled($booking->fresh(), $from, $to);

            $fresh = $booking->fresh()->load(['room.property', 'guest']);
            $message = $data['message'];
            if ($balanceDue > 0) {
                $message .= ' Settle the TSh ' . number_format($balanceDue, 0)
                    . ' balance at the property or contact support.';
            }

            return ['status' => 200, 'data' => [
                'status' => 'success',
                'message' => $message,
                'balance_due' => $balanceDue,
                'booking' => $fresh,
                'verify_url' => $this->verifier->verifyUrl($fresh->booking_code),
            ]];
        });
    }

    private function quoteMessage(bool $paid, float $diff, float $newTotal): string
    {
        if (!$paid) {
            return 'New total TSh ' . number_format($newTotal, 0)
                . ' will apply to this unpaid booking.';
        }
        if ($diff > 0) {
            return 'Moving dates costs TSh ' . number_format($diff, 0)
                . ' extra, payable at the property.';
        }
        if ($diff < 0) {
            return 'Moving dates saves TSh ' . number_format(abs($diff), 0)
                . '. Contact support for refunds per the property policy.';
        }
        return 'Dates can be moved at no extra cost.';
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function cleanDates(string $checkIn, string $checkOut): array
    {
        try {
            $in = Carbon::parse($checkIn)->startOfDay();
            $out = Carbon::parse($checkOut)->startOfDay();
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Please choose valid dates.');
        }
        if ($out->lessThanOrEqualTo($in)) {
            throw new InvalidArgumentException('Check-out must be after check-in.');
        }
        if ($in->lessThan(Carbon::today())) {
            throw new InvalidArgumentException('New check-in cannot be in the past.');
        }
        return [$in, $out];
    }
}
