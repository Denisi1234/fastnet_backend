<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        'images',
        'primary_image_url',
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

    /**
     * Expose room photos as browser-accessible URLs. The first configured
     * photo is the deterministic primary image; no image is invented here.
     * `photos` is the source of truth: rooms without photos do not borrow a
     * property or demo image.
     */
    public function getImagesAttribute(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->values()
            ->map(function (string $path, int $position) {
                return [
                    'url' => $this->toPublicImageUrl($path),
                    'is_primary' => $position === 0,
                    'position' => $position,
                ];
            })
            ->all();
    }

    public function getPrimaryImageUrlAttribute(): ?string
    {
        return $this->images[0]['url'] ?? null;
    }

    private function toPublicImageUrl(string $path): string
    {
        $apiBase = rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/');
        $path = trim($path);
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#^\.?/?storage/#i', $path)) {
            $path = preg_replace('#^\.?/?storage/#i', '', $path);
            return $apiBase . '/storage/' . ltrim($path, '/');
        }

        if (preg_match('#^properties/#i', $path) || preg_match('#^img_[^/]+\.(?:jpe?g|png|webp|gif|bmp)$#i', $path)) {
            return $apiBase . '/storage/' . ltrim($path, '/');
        }

        // Support legacy Laravel storage values without returning an internal
        // filesystem path to the browser.
        foreach (['storage/app/public/', 'app/public/', 'public/', 'storage/'] as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                return $apiBase . '/storage/' . ltrim(Str::after($path, $prefix), '/');
            }
        }

        // Uploads normally store a complete public URL. This handles only
        // legacy public-relative values through the configured app URL.
        return $apiBase . '/storage/' . ltrim($path, '/');
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
