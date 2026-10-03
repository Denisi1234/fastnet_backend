<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedPaymentMethod extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'phone_number',
        'label',
        'name',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected $appends = ['type', 'title', 'phone', 'expiry'];

    /**
     * The payment-detail page reads pm.type for the logo, pm.title as a label
     * fallback, pm.phone for display and pm.expiry as a status line. Expose
     * those aliases so the stored shape and the read shape are the same row.
     */
    public function getTypeAttribute(): string
    {
        return $this->attributes['provider'] ?? '';
    }

    public function getTitleAttribute(): ?string
    {
        return $this->attributes['label'] ?? null;
    }

    public function getPhoneAttribute(): string
    {
        return $this->attributes['phone_number'] ?? '';
    }

    public function getExpiryAttribute(): string
    {
        return 'Active';
    }
}