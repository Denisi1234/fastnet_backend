<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LodgeServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'room_number',
        'type',
        'details',
        'price',
        'status',
    ];

    protected $casts = [
        'details' => 'array',
        'price' => 'float',
    ];

    public function guest()
    {
        return $this->belongsTo(User::class, 'guest_id');
    }
}
