<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'address',
        'city',
        'area',
        'price_per_night',
        'latitude',
        'longitude',
        'host_id',
        'image_url',
        'status',
    ];

    protected $appends = [
        'customer_price_per_night',
        'processing_fee_per_night',
        'customer_price_formatted',
        'fee_note',
    ];

    public function getCustomerPricePerNightAttribute()
    {
        $base = (float) $this->price_per_night;
        return round($base * 1.01, 2);
    }

    public function getProcessingFeePerNightAttribute()
    {
        $base = (float) $this->price_per_night;
        return round($base * 0.01, 2);
    }

    public function getCustomerPriceFormattedAttribute()
    {
        return 'TSh ' . number_format($this->customer_price_per_night);
    }

    public function getFeeNoteAttribute()
    {
        return 'Includes payment processing fee';
    }

    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function documents()
    {
        return $this->hasMany(LodgeDocument::class);
    }

    public function verificationRequests()
    {
        return $this->hasMany(VerificationRequest::class, 'entity_id')->where('entity_type', 'lodge');
    }

    /**
     * Scope for published lodges bookable by customers:
     * - Lodge must be Active/Approved
     * - Owner must be Active/Approved
     * - Must have at least 1 room available
     */
    public function scopePublishedForCustomers($query)
    {
        return $query->where('status', 'Active')
            ->whereHas('host', function ($q) {
                $q->where('status', 'Active');
            })
            ->has('rooms');
    }
}
