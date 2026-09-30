<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
        'amenities',
        'status',
    ];

    /**
     * Clean and format user-entered titles, lodge names, and locations.
     */
    public static function formatTitle(?string $text): string
    {
        if ($text === null) return '';
        $text = trim($text);
        if ($text === '') return '';

        // Fix missing spaces after commas: "45 sekei road,arusha" -> "45 sekei road, arusha"
        $text = preg_replace('/,(\S)/u', ', $1', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        $minorWords = ['es', 'la', 'de', 'and', 'the', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by'];
        $words = explode(' ', $text);
        $formatted = [];
        foreach ($words as $idx => $w) {
            $w = trim($w);
            if ($w === '') continue;
            $lower = mb_strtolower($w, 'UTF-8');
            if (str_contains($w, '-')) {
                $subParts = explode('-', $w);
                $subFormatted = array_map(function($sub, $subIdx) use ($minorWords) {
                    $subLower = mb_strtolower($sub, 'UTF-8');
                    return ($subIdx > 0 && in_array($subLower, $minorWords, true)) ? $subLower : mb_convert_case($subLower, MB_CASE_TITLE, 'UTF-8');
                }, $subParts, array_keys($subParts));
                $formatted[] = implode('-', $subFormatted);
            } elseif ($idx > 0 && in_array($lower, $minorWords, true)) {
                $formatted[] = $lower;
            } else {
                $formatted[] = mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
            }
        }
        $result = implode(' ', $formatted);

        $dictionary = [
            '/\bdar\s+es\s+salam\b/i' => 'Dar es Salaam',
            '/\bdar\s+es\s+salaam\b/i' => 'Dar es Salaam',
            '/\bstone\s+town\b/i' => 'Stone Town',
            '/\boyster\s*bay\b/i' => 'Oyster Bay',
            '/\bmbezi\s+beach\b/i' => 'Mbezi Beach',
            '/\bsinza\s+makabulini\b/i' => 'Sinza Makabulini',
            '/\bwi\s*[- ]?\s*fi\b/i' => 'Wi-Fi',
            '/\b(tv|led|ac|wc)\b/i' => fn($m) => strtoupper($m[0]),
        ];

        foreach ($dictionary as $pattern => $replace) {
            $result = is_callable($replace) ? preg_replace_callback($pattern, $replace, $result) : preg_replace($pattern, $replace, $result);
        }

        return $result;
    }

    public function getNameAttribute($value)
    {
        return self::formatTitle($value);
    }

    public function getCityAttribute($value)
    {
        return self::formatTitle($value);
    }

    public function getAreaAttribute($value)
    {
        return self::formatTitle($value);
    }

    public function getAddressAttribute($value)
    {
        return self::formatTitle($value);
    }

    public function getAmenitiesAttribute($value)
    {
        if (empty($value)) return [];
        $items = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($items) && is_string($value)) {
            $items = array_filter(array_map('trim', explode(',', $value)));
        }
        if (!is_array($items)) return [];
        return array_values(array_map(fn($item) => is_string($item) ? self::formatTitle($item) : $item, $items));
    }

    /**
     * Resolve image_url dynamically to current APP_URL for production & local parity.
     */
    public function getImageUrlAttribute($value)
    {
        if (empty($value)) {
            return null;
        }
        $value = trim((string) $value);
        $apiBase = rtrim((string) (config('app.url') ?: env('APP_URL', 'http://127.0.0.1:8000')), '/');

        if (str_contains($value, '/storage/')) {
            $storagePath = substr($value, strpos($value, '/storage/') + 9);
            return $apiBase . '/storage/' . ltrim($storagePath, '/');
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return $apiBase . '/storage/' . ltrim($value, '/');
    }

    /**
     * Compute the customer-facing price (base + 1% processing fee).
     * Called explicitly where needed instead of auto-appending to every query result.
     */
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

    /**
     * Keep public search truthful: any deletion busts search + detail caches
     * immediately (otherwise deleted lodges keep appearing until TTL).
     */
    protected static function booted(): void
    {
        static::deleting(function (Property $property): void {
            Cache::increment('properties:search-version');
            Cache::forget("property:detail:v2:{$property->id}");
            Cache::forget("property:detail:{$property->id}");
        });
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
