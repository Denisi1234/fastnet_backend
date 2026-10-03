<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    /**
     * Booking lifecycle. Terminal states deliberately have no outgoing edges so
     * a late/duplicate payment webhook can never resurrect a cancelled booking.
     */
    public const STATUS_TRANSITIONS = [
        'Pending'              => ['Confirmed', 'Pending Verification', 'Cancelled'],
        'Pending Verification' => ['Confirmed', 'Cancelled'],
        'Confirmed'            => ['Checked In', 'Completed', 'Cancelled'],
        'Checked In'           => ['Completed', 'Cancelled'],
        'Completed'            => [],
        'Cancelled'            => [],
    ];

    public const TERMINAL_STATUSES = ['Completed', 'Cancelled'];

    protected $fillable = [
        'booking_code',
        'room_id',
        'guest_id',
        'check_in',
        'check_out',
        'total_price',
        'commission_rate',
        'platform_fee',
        'owner_payout',
        'status',
        'payment_status',
        'payment_reference',
        'payment_method',
        'payment_phone',
        'special_requests',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'total_price' => 'float',
        'commission_rate' => 'float',
        'platform_fee' => 'float',
        'owner_payout' => 'float',
    ];

    /**
     * Whether this booking is allowed to move to $status.
     * Unknown current states are permissive so legacy rows are not wedged.
     */
    public function canTransitionTo(?string $status): bool
    {
        if ($status === null || $status === '') {
            return false;
        }

        if ($status === $this->status) {
            return true;
        }

        $allowed = self::STATUS_TRANSITIONS[$this->status] ?? null;

        if ($allowed === null) {
            return true;
        }

        return in_array($status, $allowed, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Move the booking forward, refusing any transition that would move it
     * backwards or out of a terminal state.
     *
     * @param  array<string, mixed>  $extra  Additional attributes to persist with the new status.
     * @return bool  False when the transition was rejected.
     */
    public function transitionTo(string $status, array $extra = []): bool
    {
        if (!$this->canTransitionTo($status)) {
            return false;
        }

        $this->fill($extra);
        $this->status = $status;
        $this->save();

        return true;
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(User::class, 'guest_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
