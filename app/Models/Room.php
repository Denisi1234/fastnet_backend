<?php

namespace App\Models;

use App\Services\PropertySearchService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
        'room_type',
    ];

    public function getRoomTypeAttribute(): ?string
    {
        return $this->attributes['room_type_id'] ?? null;
    }

    public function getNameAttribute($value)
    {
        return Property::formatTitle($value);
    }

    public function getRoomNumberAttribute($value)
    {
        return Property::formatTitle($value);
    }

    public function getBedConfigurationAttribute($value)
    {
        $v = Property::formatTitle($value);
        if ($v !== '' && !preg_match('/\b(bed|beds)\b/i', $v)) {
            $v .= ' Bed';
        }
        return $v;
    }

    public function getFloorAttribute($value)
    {
        $v = trim((string)$value);
        if (strtolower($v) === 'fround' || strtolower($v) === 'ground') {
            return 'Ground Floor';
        }
        return Property::formatTitle($value);
    }

    public function getAmenitiesAttribute($value)
    {
        if (empty($value)) return [];
        $items = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($items) && is_string($value)) {
            $items = array_filter(array_map('trim', explode(',', $value)));
        }
        if (!is_array($items)) return [];
        return array_values(array_map(fn($item) => is_string($item) ? Property::formatTitle($item) : $item, $items));
    }

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

    public function getPhotosAttribute($value)
    {
        $photos = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($photos)) {
            return [];
        }
        return array_values(array_map(fn($p) => is_string($p) ? $this->toPublicImageUrl($p) : $p, $photos));
    }

    private function toPublicImageUrl(string $path): string
    {
        $apiBase = rtrim((string) (config('app.url') ?: env('APP_URL', 'http://127.0.0.1:8000')), '/');
        $path = trim($path);

        if (str_contains($path, '/storage/')) {
            $storagePath = substr($path, strpos($path, '/storage/') + 9);
            return $apiBase . '/storage/' . ltrim($storagePath, '/');
        }

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

    /**
     * Room count drives public listing eligibility — bust caches on delete.
     */
    protected static function booted(): void
    {
        // Same gap as Property: only `deleting` invalidated, so adding or
        // editing a room left stale search results (including availability and
        // the lowest rate shown on cards) cached for the full TTL.
        $invalidate = function (Room $room): void {
            PropertySearchService::bumpSearchVersion();
            if ($room->property_id) {
                Cache::forget("property:detail:v2:{$room->property_id}");
                Cache::forget("property:detail:{$room->property_id}");
            }
        };

        static::created($invalidate);
        static::updated($invalidate);
        static::deleted($invalidate);

        static::deleting(function (Room $room): void {
            PropertySearchService::bumpSearchVersion();
            if ($room->property_id) {
                Cache::forget("property:detail:v2:{$room->property_id}");
            }
        });
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
