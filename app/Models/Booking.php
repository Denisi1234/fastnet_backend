<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'guest_id',
        'check_in',
        'check_out',
        'total_price',
        'payment_status',
        'payment_reference',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
    ];

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
