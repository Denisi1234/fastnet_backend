<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PropertySearchService
{
    protected RoomAvailabilityService $availabilityService;

    public function __construct(RoomAvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

    public function search(Request $request)
    {
        $t0 = microtime(true);

        $search           = $request->input('q') ?? $request->input('search') ?? $request->input('destination') ?? $request->input('dest') ?? $request->input('name') ?? $request->input('location');
        if (!is_null($search)) {
            $search = preg_replace('/\s+/', ' ', trim($search));
        }
        $city             = $request->input('city');
        $area             = $request->input('area');
        $checkIn          = $request->input('check_in') ?? $request->input('checkIn');
        $checkOut         = $request->input('check_out') ?? $request->input('checkOut');
        $adults           = (int)($request->input('adults') ?? $request->input('guests') ?? 1);
        $children         = (int)($request->input('children') ?? 0);
        $requiredRooms    = (int)($request->input('rooms') ?? 1);
        $totalGuests      = max(1, (int)($request->input('guests') ?? ($adults + $children)));

        $lat              = $request->input('lat') ?? $request->input('latitude');
        $lng              = $request->input('lng') ?? $request->input('longitude');
        $radiusKm         = $request->input('radius_km', 25);
        // Map viewport bounds "ne_lat,ne_lng,sw_lat,sw_lng" — exact-area filter.
        // Never publicly cacheable: each viewport is unique.
        $bounds = $request->input('bounds') ?? $request->input('bbox');
        $bbox = null;
        if (is_string($bounds) && $bounds !== '') {
            $parts = array_map('trim', explode(',', $bounds));
            if (count($parts) === 4 && count(array_filter($parts, 'is_numeric')) === 4) {
                $bbox = array_map('floatval', $parts);
            }
        }
        $priceMin         = $request->input('price_min') ?? $request->input('min_price');
        $priceMax         = $request->input('price_max') ?? $request->input('max_price');
        $minRating        = $request->input('min_rating');
        $freeCancellation = $request->input('free_cancellation');
        $sortBy           = $request->input('sort') ?? $request->input('sort_by');

        // Validate date range
        $isValidDateRange = false;
        if (!empty($checkIn) && !empty($checkOut)) {
            $cIn  = strtotime($checkIn);
            $cOut = strtotime($checkOut);
            if ($cIn && $cOut && $cOut > $cIn) {
                $isValidDateRange = true;
            }
        }

        $propertyType = $request->input('property_type') ?? $request->input('type');

        // Owner scope (?mine=1 or ?host_id=N): "My Properties" for the host portal.
        // Never publicly cacheable; non-admins are forced to their own id.
        // Bypasses the public-listing constraints (active status + rooms) so
        // owners see pending/roomless properties too.
        $mineParam = $request->input('mine') ?? $request->input('host_id');
        $mineScope = false;
        $mineHostId = 0;
        if ($mineParam !== null && $mineParam !== '' && $mineParam !== '0' && strtolower((string)$mineParam) !== 'false') {
            $authUser = $request->user('sanctum') ?? $request->user();
            if ($mineParam === '1' || $mineParam === 1 || strtolower((string)$mineParam) === 'true' || strtolower((string)$mineParam) === 'mine') {
                $mineHostId = (int)optional($authUser)->id;
            } else {
                $mineHostId = (int)$mineParam;
            }
            if ($authUser && $authUser->role !== 'admin') {
                $mineHostId = (int)$authUser->id;
            }
            $mineScope = $mineHostId > 0;
        }

        // Cache is applicable only for simple, no-date searches with default params.
        // area/amenities are NOT part of the cache key and must never be served
        // a cached unfiltered list.
        $hasAreaOrAmen = !empty($request->input('area')) || !empty($request->input('amenities'));
        $isCacheable = empty($priceMin) && empty($priceMax) && empty($minRating) && empty($freeCancellation)
            && empty($propertyType) && empty($sortBy) && !$isValidDateRange
            && empty($lat) && $totalGuests <= 2 && $requiredRooms <= 1
            && !$mineScope && $bbox === null && !$hasAreaOrAmen;

        if ($isCacheable) {
            $cacheKey = $this->buildCacheKey($search, $city);
            $cached   = Cache::get($cacheKey);
            if (is_array($cached)) {
                Log::info('PropertySearch: cache HIT', ['key' => $cacheKey]);
                return [
                    'paginator'    => null,
                    'data'         => $cached,
                    'cache_status' => 'HIT',
                ];
            }
            // Discard legacy cached paginator objects; only API payload arrays are cache-safe.
            if ($cached !== null) {
                Cache::forget($cacheKey);
            }
        }

        // ─── Build the base query ────────────────────────────────────────────────────
        //
        // Select only the fields needed for search result cards.
        // Full details (description, host, all room fields) are loaded by show() separately.
        $query = Property::select([
            'id', 'name', 'city', 'area', 'address', 'price_per_night',
            'latitude', 'longitude', 'image_url', 'created_at', 'status',
            'host_id',
        ])
        ->with([
            'rooms' => function ($q) {
                // Load fields needed for availability, capacity checks, and room photos.
                $q->select('id', 'property_id', 'room_number', 'capacity', 'max_adults', 'max_children', 'price', 'status', 'bed_configuration', 'total_inventory', 'photos');
            },
        ])
        ->withAvg('reviews', 'rating')
        ->withCount('reviews');

        // Always filter to active properties with at least one room,
        // except in owner scope (hosts must see all their own properties)
        if ($mineScope) {
            $query->where('host_id', $mineHostId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']);
            });
            $query->whereHas('rooms');
        }

        // ─── Location / text search ──────────────────────────────────────────────────
        if (!empty($search)) {
            $terms = array_filter(preg_split('/[,\s]+/', trim($search)));
            $query->where(function ($q) use ($terms, $search) {
                // Search city, area, name, address — NOT description (avoids full table scan)
                $q->where('name',    'ilike', '%' . $search . '%')
                  ->orWhere('city',    'ilike', '%' . $search . '%')
                  ->orWhere('area',    'ilike', '%' . $search . '%')
                  ->orWhere('address', 'ilike', '%' . $search . '%');

                foreach ($terms as $term) {
                    $term = trim($term);
                    if (strlen($term) > 2 && strtolower($term) !== 'tanzania') {
                        $q->orWhere('city',    'ilike', '%' . $term . '%')
                          ->orWhere('area',    'ilike', '%' . $term . '%')
                          ->orWhere('name',    'ilike', '%' . $term . '%')
                          ->orWhere('address', 'ilike', '%' . $term . '%');
                    }
                }
            });
        }

        if (!empty($city)) {
            $query->where('city', 'ilike', '%' . $city . '%');
        }

        if (!empty($area)) {
            $query->where('area', 'ilike', '%' . $area . '%');
        }

        // ─── Geo-radius filter ───────────────────────────────────────────────────────
        // acos() raises a domain error if its argument drifts outside [-1, 1]
        // (routine for near-coincident points), which drops the whole query.
        // Clamping also makes distance 0 for a property at the search origin.
        if (!empty($lat) && !empty($lng)) {
            $query->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereRaw('
                    (6371 * acos(
                        LEAST(1, GREATEST(-1,
                            cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) +
                            sin(radians(?)) * sin(radians(latitude))
                        ))
                    )) <= ?
                ', [$lat, $lng, $lat, $radiusKm]);
        }

        // ─── Viewport bounds filter (exact area): ne_lat,ne_lng,sw_lat,sw_lng ──
        // Applied together with (intersecting) any radius filter so the map
        // viewport always wins — Arusha never leaks into a Dar view.
        if (is_array($bbox)) {
            [$neLat, $neLng, $swLat, $swLng] = $bbox;
            $query->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereBetween('latitude', [min($swLat, $neLat), max($swLat, $neLat)])
                ->whereBetween('longitude', [min($swLng, $neLng), max($swLng, $neLng)]);
        }

        // ─── Price filters ───────────────────────────────────────────────────────────
        if (!empty($priceMin)) {
            $query->where('price_per_night', '>=', $priceMin);
        }
        if (!empty($priceMax)) {
            $query->where('price_per_night', '<=', $priceMax);
        }

        // ─── Free cancellation filter ────────────────────────────────────────────────
        if (!empty($freeCancellation) && ($freeCancellation === '1' || $freeCancellation === 'true')) {
            $query->where(function ($q) {
                $q->where('description', 'like', '%free cancellation%')
                  ->orWhere('description', 'like', '%flexible cancellation%')
                  ->orWhereHas('rooms', function ($rq) {
                      $rq->where('description', 'like', '%free cancellation%')
                         ->orWhere('amenities',  'like', '%free cancellation%');
                  });
            });
        }

        // ─── Property type / amenity filters ────────────────────────────────────────
        $amenities = $request->input('amenities');

        if (!empty($propertyType)) {
            $types = is_array($propertyType) ? $propertyType : explode(',', $propertyType);
            $query->where(function ($q) use ($types) {
                foreach ($types as $type) {
                    $trimmed = trim($type);
                    $q->orWhere('name',        'like', '%' . $trimmed . '%')
                      ->orWhere('description', 'like', '%' . $trimmed . '%');
                }
            });
        }

        if (!empty($amenities)) {
            $amenityList = is_array($amenities) ? $amenities : explode(',', $amenities);
            foreach ($amenityList as $amenity) {
                $trimmedAmenity = trim($amenity);
                if (empty($trimmedAmenity)) continue;
                $query->where(function ($groupQuery) use ($trimmedAmenity) {
                    $groupQuery->where('description', 'like', '%' . $trimmedAmenity . '%')
                               ->orWhereHas('rooms', function ($rq) use ($trimmedAmenity) {
                                   $rq->where('amenities',   'like', '%' . $trimmedAmenity . '%')
                                      ->orWhere('description', 'like', '%' . $trimmedAmenity . '%');
                               });
                });
            }
        }

        // ─── Rating filter ───────────────────────────────────────────────────────────
        // Review-less lodges pass (COALESCE), so new properties aren't zeroed out.
        if (!empty($minRating)) {
            $minRating = (float)$minRating;
            $query->whereRaw('(SELECT COALESCE(AVG(rating), ?) FROM reviews WHERE reviews.property_id = properties.id) >= ?', [$minRating, $minRating]);
        }

        // ─── Sorting ─────────────────────────────────────────────────────────────────
        if (!empty($search) && empty($sortBy)) {
            $query->orderByRaw("
                CASE 
                    WHEN name ILIKE ? THEN 1
                    WHEN name ILIKE ? THEN 2
                    WHEN name ILIKE ? THEN 3
                    WHEN city ILIKE ? OR area ILIKE ? THEN 4
                    ELSE 5
                END ASC
            ", [
                $search,
                $search . '%',
                '%' . $search . '%',
                $search,
                $search
            ]);
        }

        if ($sortBy === 'price_asc' || $sortBy === 'price-low') {
            $query->orderBy('price_per_night', 'asc');
        } elseif ($sortBy === 'price_desc' || $sortBy === 'price-high') {
            $query->orderBy('price_per_night', 'desc');
        } elseif ($sortBy === 'rating') {
            $query->orderBy('reviews_avg_rating', 'desc');
        } elseif ($sortBy === 'most_reviewed' || $sortBy === 'reviews') {
            $query->orderBy('reviews_count', 'desc');
        } elseif ($sortBy === 'newest') {
            $query->orderBy('created_at', 'desc');
        } elseif ($sortBy === 'distance' && !empty($lat) && !empty($lng)) {
            $query->orderByRaw('
                (6371 * acos(
                    LEAST(1, GREATEST(-1,
                        cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    ))
                )) ASC
            ', [$lat, $lng, $lat]);
        } else {
            $query->orderBy('reviews_avg_rating', 'desc')->orderBy('id', 'asc');
        }

        // ─── Pagination — ALWAYS paginate; never load all records into memory ────────
        $perPage = (int)($request->input('per_page') ?? $request->input('limit') ?? 20);
        $perPage = min(max(1, $perPage), 100);
        $page    = (int)($request->input('page') ?? 1);

        Log::info('PropertySearch: executing paginated query', [
            'search'   => $search,
            'city'     => $city,
            'page'     => $page,
            'per_page' => $perPage,
            'dates'    => $isValidDateRange ? "{$checkIn}→{$checkOut}" : 'none',
            'build_ms' => round((microtime(true) - $t0) * 1000),
        ]);

        $t1        = microtime(true);
        // simplePaginate: no COUNT(*) over the filtered set (killer at millions
        // of rows). Clients use null-safe totals.
        $paginator = $query->simplePaginate($perPage, ['*'], 'page', $page);

        Log::info('PropertySearch: DB paginate done', [
            'page'   => $paginator->currentPage(),
            'count'  => $paginator->count(),
            'db_ms'  => round((microtime(true) - $t1) * 1000),
        ]);

        // ─── Post-paginate availability filter (date-based, current page only) ───────
        if ($isValidDateRange) {
            $t2         = microtime(true);
            $collection = $paginator->getCollection();

            $filtered = $collection->filter(function ($property) use (
                $checkIn, $checkOut, $requiredRooms, $totalGuests, $adults, $children
            ) {
                $hasQualifiedRoom              = false;
                $propertyAvailableInventorySum = 0;
                $propertySatisfyingCapacitySum = 0;

                foreach ($property->rooms as $room) {
                    $roomCap    = (int) ($room->capacity ?? 2);
                    $maxAdults  = (int) ($room->max_adults ?? $roomCap);
                    $roomInv    = $room->total_inventory === null ? 1 : max(0, (int) $room->total_inventory);

                    $singleRoomCapacityOk = ($roomCap >= $totalGuests) || ($maxAdults >= $adults);

                    $availableInventoryForRoom = $roomInv;

                    // Pass the already-loaded room model to avoid an extra Room::find() query
                    $availResult = $this->availabilityService->checkRoomAvailability(
                        $room->id,
                        $checkIn,
                        $checkOut,
                        1,
                        null,
                        null,
                        $room  // <-- pre-loaded model, skips Room::find()
                    );

                    if (!$availResult['is_available']) {
                        continue;
                    }

                    $availableInventoryForRoom = $availResult['min_available_inventory'];

                    if ($availableInventoryForRoom < 1) {
                        continue;
                    }

                    if ($singleRoomCapacityOk && $availableInventoryForRoom >= $requiredRooms) {
                        $hasQualifiedRoom = true;
                        break;
                    }

                    $propertyAvailableInventorySum += $availableInventoryForRoom;
                    $propertySatisfyingCapacitySum += ($roomCap * $availableInventoryForRoom);
                }

                if (!$hasQualifiedRoom && $requiredRooms > 1) {
                    if ($propertyAvailableInventorySum >= $requiredRooms && $propertySatisfyingCapacitySum >= $totalGuests) {
                        $hasQualifiedRoom = true;
                    }
                }

                return $hasQualifiedRoom;
            })->values();

            $paginator->setCollection($filtered);

            Log::info('PropertySearch: availability filter done', [
                'before' => $collection->count(),
                'after'  => $filtered->count(),
                'avail_ms' => round((microtime(true) - $t2) * 1000),
            ]);
        }

        // ─── Add computed price fields to each result card item ──────────────────────
        // (These were previously auto-appended by $appends on the model — now done here
        //  so the model stays lean and the full detail page can handle its own formatting.)
        $paginator->getCollection()->transform(function ($property) {
            $base = (float) $property->price_per_night;
            $property->customer_price_per_night = round($base * 1.01, 2);
            $property->processing_fee_per_night = round($base * 0.01, 2);
            $property->customer_price_formatted = 'TSh ' . number_format(round($base * 1.01));
            $property->fee_note                 = 'Includes payment processing fee';
            // Search cards need only one image per room. Do not send gallery
            // payloads or make the frontend infer a cover photo.
            $property->rooms->each(function ($room) {
                $room->append('primary_image_url')->makeHidden(['photos', 'images']);
            });
            return $property;
        });

        Log::info('PropertySearch: request done', [
            'total_ms' => round((microtime(true) - $t0) * 1000),
        ]);

        // ─── Cache simple (no-date, no-filter) searches ──────────────────────────────
        if ($isCacheable && $page === 1) {
            $ttl      = empty($search) && empty($city) ? 1800 : 600;
            $cacheKey = $this->buildCacheKey($search, $city);
            Cache::put($cacheKey, $paginator->toArray(), $ttl);
        }

        return [
            'paginator'    => $paginator,
            'data'         => null,
            'cache_status' => 'MISS',
        ];
    }

    public const SEARCH_VERSION_KEY = 'properties:search-version';

    /**
     * Bump the search cache generation.
     *
     * Cache::increment() is a silent no-op when the key does not yet exist, so
     * the key has to be seeded first - otherwise the version stays pinned at 1
     * forever and stale search cards survive the full TTL.
     */
    public static function bumpSearchVersion(): int
    {
        Cache::add(self::SEARCH_VERSION_KEY, 1, now()->addYear());

        return (int) Cache::increment(self::SEARCH_VERSION_KEY);
    }

    protected function buildCacheKey(?string $search, ?string $city): string
    {
        // Room-media updates bump this version, preventing stale serialized
        // search cards from serving a previous primary image.
        $version = (int) Cache::get(self::SEARCH_VERSION_KEY, 1);
        if (!empty($search)) {
            $normalized = strtolower(trim($search));
            $normalized = str_replace(['tanzania', ','], '', $normalized);
            $normalized = trim($normalized);
            return 'search:' . ($normalized ?: 'all') . ':v' . $version;
        }
        if (!empty($city)) {
            return 'properties:city:' . strtolower(trim($city)) . ':v' . $version;
        }
        return 'properties:all:v' . $version;
    }
}
