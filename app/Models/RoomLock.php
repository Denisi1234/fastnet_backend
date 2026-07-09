<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'guest_id',
        'check_in',
        'check_out',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
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
}
