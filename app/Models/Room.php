<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'room_number',
        'status',
        'room_type_id',
        'price',
        'capacity',
        'amenities',
        'photos',
        'description',
        'floor',
        'max_adults',
        'max_children',
        'bed_configuration',
        'number_of_beds',
        'room_size',
        'total_inventory',
    ];

    protected $casts = [
        'amenities' => 'array',
        'photos' => 'array',
        'price' => 'decimal:2',
        'capacity' => 'integer',
        'max_adults' => 'integer',
        'max_children' => 'integer',
        'number_of_beds' => 'integer',
    ];

    protected $appends = [
        'customer_price',
        'processing_fee_per_night',
        'customer_price_formatted',
        'fee_note',
    ];

    public function getCustomerPriceAttribute()
    {
        $base = (float) ($this->price ?? 0);
        return round($base * 1.01, 2);
    }

    public function getProcessingFeePerNightAttribute()
    {
        $base = (float) ($this->price ?? 0);
        return round($base * 0.01, 2);
    }

    public function getCustomerPriceFormattedAttribute()
    {
        return 'TSh ' . number_format($this->customer_price);
    }

    public function getFeeNoteAttribute()
    {
        return 'Includes payment processing fee';
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
