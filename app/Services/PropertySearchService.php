<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PropertySearchService
{
    protected RoomAvailabilityService $availabilityService;

    public function __construct(RoomAvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

    public function search(Request $request)
    {
        $search   = $request->input('q') ?? $request->input('search') ?? $request->input('name') ?? $request->input('location');
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
        $priceMin         = $request->input('price_min');
        $priceMax         = $request->input('price_max');
        $minRating        = $request->input('min_rating');
        $freeCancellation = $request->input('free_cancellation');
        $sortBy           = $request->input('sort') ?? $request->input('sort_by');

        $isValidDateRange = false;
        if (!empty($checkIn) && !empty($checkOut)) {
            $cIn = strtotime($checkIn);
            $cOut = strtotime($checkOut);
            if ($cIn && $cOut && $cOut > $cIn) {
                $isValidDateRange = true;
            }
        }

        $propertyType = $request->input('property_type') ?? $request->input('type');

        $isCacheable = empty($priceMin) && empty($priceMax) && empty($minRating) && empty($freeCancellation) && empty($propertyType) && empty($sortBy) && !$isValidDateRange && empty($lat) && $totalGuests <= 2 && $requiredRooms <= 1;

        if ($isCacheable) {
            $cacheKey = $this->buildCacheKey($search, $city);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return [
                    'data' => $cached,
                    'cache_status' => 'HIT'
                ];
            }
        }

        $query = Property::select([
            'id', 'name', 'city', 'area', 'address', 'price_per_night', 
            'latitude', 'longitude', 'image_url', 'created_at', 'status'
        ])
        ->with([
            'rooms' => function($q) {
                $q->select('id', 'property_id', 'room_number', 'capacity', 'max_adults', 'max_children', 'price', 'status', 'bed_configuration', 'total_inventory');
            }
        ])
        ->withAvg('reviews', 'rating')
        ->withCount('reviews');

        $query->whereHas('rooms');

        if (!empty($search)) {
            $terms = array_filter(preg_split('/[,\s]+/', trim($search)));
            $query->where(function ($q) use ($terms, $search) {
                $q->where('name', 'ilike', '%' . $search . '%')
                  ->orWhere('city', 'ilike', '%' . $search . '%')
                  ->orWhere('area', 'ilike', '%' . $search . '%')
                  ->orWhere('address', 'ilike', '%' . $search . '%')
                  ->orWhere('description', 'ilike', '%' . $search . '%');

                foreach ($terms as $term) {
                    $term = trim($term);
                    if (strlen($term) > 2 && strtolower($term) !== 'tanzania') {
                        $q->orWhere('city', 'ilike', '%' . $term . '%')
                          ->orWhere('area', 'ilike', '%' . $term . '%')
                          ->orWhere('name', 'ilike', '%' . $term . '%')
                          ->orWhere('address', 'ilike', '%' . $term . '%');
                    }
                }
            });
        }

        if (!empty($city)) {
            $query->where('city', 'ilike', '%' . $city . '%');
        }

        if (!empty($area)) {
            $query->where('area', 'like', '%' . $area . '%');
        }

        if (!empty($lat) && !empty($lng)) {
            $query->whereNotNull('latitude')
                  ->whereNotNull('longitude')
                  ->whereRaw("
                      (6371 * acos(
                          cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + 
                          sin(radians(?)) * sin(radians(latitude))
                      )) <= ?
                  ", [$lat, $lng, $lat, $radiusKm]);
        }

        if (!empty($priceMin)) {
            $query->where('price_per_night', '>=', $priceMin);
        }

        if (!empty($priceMax)) {
            $query->where('price_per_night', '<=', $priceMax);
        }

        if (!empty($freeCancellation) && ($freeCancellation === '1' || $freeCancellation === 'true')) {
            $query->where(function($q) {
                $q->where('description', 'like', '%free cancellation%')
                  ->orWhere('description', 'like', '%flexible cancellation%')
                  ->orWhereHas('rooms', function($rq) {
                      $rq->where('description', 'like', '%free cancellation%')
                         ->orWhere('amenities', 'like', '%free cancellation%');
                  });
            });
        }

        $amenities = $request->input('amenities');
        if (!empty($propertyType)) {
            $types = is_array($propertyType) ? $propertyType : explode(',', $propertyType);
            $query->where(function($q) use ($types) {
                foreach ($types as $type) {
                    $trimmed = trim($type);
                    $q->orWhere('name', 'like', '%' . $trimmed . '%')
                      ->orWhere('description', 'like', '%' . $trimmed . '%');
                }
            });
        }

        if (!empty($amenities)) {
            $amenityList = is_array($amenities) ? $amenities : explode(',', $amenities);
            $query->where(function($groupQuery) use ($amenityList) {
                foreach ($amenityList as $amenity) {
                    $trimmedAmenity = trim($amenity);
                    if (empty($trimmedAmenity)) continue;

                    $groupQuery->orWhere('description', 'like', '%' . $trimmedAmenity . '%')
                               ->orWhereHas('rooms', function($rq) use ($trimmedAmenity) {
                                   $rq->where('amenities', 'like', '%' . $trimmedAmenity . '%')
                                      ->orWhere('description', 'like', '%' . $trimmedAmenity . '%');
                               });
                }
            });
        }

        if (!empty($minRating)) {
            $query->whereHas('reviews', function($rq) use ($minRating) {
                $rq->selectRaw('AVG(rating) as avg_rating')
                   ->havingRaw('AVG(rating) >= ?', [$minRating]);
            })->orWhereDoesntHave('reviews');
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
            $query->orderByRaw("
                (6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + 
                    sin(radians(?)) * sin(radians(latitude))
                )) ASC
            ", [$lat, $lng, $lat]);
        } else {
            $query->orderBy('reviews_avg_rating', 'desc')->orderBy('id', 'asc');
        }

        $perPage = (int)($request->input('per_page') ?? $request->input('limit') ?? 20);
        $perPage = min(max(1, $perPage), 100);

        if ($request->has('page') || $request->has('per_page')) {
            $paginator = $query->paginate($perPage);
            return [
                'paginator' => $paginator,
                'cache_status' => 'MISS'
            ];
        }

        $properties = $query->get();

        $properties = $properties->filter(function ($property) use ($checkIn, $checkOut, $isValidDateRange, $requiredRooms, $totalGuests, $adults, $children) {
            $hasQualifiedRoom = false;
            $propertyAvailableInventorySum = 0;
            $propertySatisfyingCapacitySum = 0;

            foreach ($property->rooms as $room) {
                $roomCap = (int) ($room->capacity ?? 2);
                $maxAdults = (int) ($room->max_adults ?? $roomCap);
                $roomInv = max(1, (int) ($room->total_inventory ?? 1));

                $singleRoomCapacityOk = ($roomCap >= $totalGuests) || ($maxAdults >= $adults);

                $availableInventoryForRoom = $roomInv;
                if ($isValidDateRange) {
                    $availResult = $this->availabilityService->checkRoomAvailability(
                        $room->id,
                        $checkIn,
                        $checkOut,
                        1
                    );

                    if (!$availResult['is_available']) {
                        continue;
                    }
                    $availableInventoryForRoom = $availResult['min_available_inventory'];
                }

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

        $propertiesArray = $properties->toArray();

        if ($isCacheable) {
            $ttl = empty($search) && empty($city) ? 1800 : 600;
            $cacheKey = $this->buildCacheKey($search, $city);
            Cache::put($cacheKey, $propertiesArray, $ttl);
        }

        return [
            'data' => $propertiesArray,
            'cache_status' => 'MISS'
        ];
    }

    protected function buildCacheKey(?string $search, ?string $city): string
    {
        if (!empty($search)) {
            $normalized = strtolower(trim($search));
            $normalized = str_replace(['tanzania', ','], '', $normalized);
            $normalized = trim($normalized);
            return 'search:' . ($normalized ?: 'all');
        }
        if (!empty($city)) {
            return 'properties:city:' . strtolower(trim($city));
        }
        return 'properties:all';
    }
}
