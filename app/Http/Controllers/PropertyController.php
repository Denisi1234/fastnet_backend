<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Room;
use App\Services\RoomAvailabilityService;
use App\Jobs\InvalidatePropertyCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PropertyController extends Controller
{
    public function index(Request $request)
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

        // Validate date integrity: strictly positive stay duration
        $isValidDateRange = false;
        if (!empty($checkIn) && !empty($checkOut)) {
            $cIn = strtotime($checkIn);
            $cOut = strtotime($checkOut);

            if ($cIn && $cOut && $cOut > $cIn) {
                $isValidDateRange = true;
            }
        }

        $propertyType     = $request->input('property_type') ?? $request->input('type');

        // ─── Redis Cache-First Strategy ────────────────────────────────────────
        $isCacheable = empty($priceMin) && empty($priceMax) && empty($minRating) && empty($freeCancellation) && empty($propertyType) && empty($sortBy) && !$isValidDateRange && empty($lat) && $totalGuests <= 2 && $requiredRooms <= 1;

        if ($isCacheable) {
            $cacheKey = $this->buildCacheKey($search, $city);
            $ttl      = empty($search) && empty($city) ? 1800 : 600;

            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached)->header('X-Cache', 'HIT');
            }
        }
        // ───────────────────────────────────────────────────────────────────────

        // Query properties with rooms relationship
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

        // Initial SQL filter for active rooms presence
        $query->whereHas('rooms');

        // 2. Destination / Location Full-Text & Geographic Match
        if (!empty($search)) {
            $terms = array_filter(preg_split('/[,\s]+/', trim($search)));
            $query->where(function ($q) use ($terms, $search) {
                // Exact full string match
                $q->where('name', 'ilike', '%' . $search . '%')
                  ->orWhere('city', 'ilike', '%' . $search . '%')
                  ->orWhere('area', 'ilike', '%' . $search . '%')
                  ->orWhere('address', 'ilike', '%' . $search . '%')
                  ->orWhere('description', 'ilike', '%' . $search . '%');

                // Tokenized match for terms like "Arusha" from "Arusha, Tanzania"
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

        // 3. Geographic Coordinates Proximity (Haversine formula approximation)
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

        // 2. Real Database Price Range Filtering
        if (!empty($priceMin)) {
            $query->where('price_per_night', '>=', $priceMin);
        }

        if (!empty($priceMax)) {
            $query->where('price_per_night', '<=', $priceMax);
        }

        // 3. Database Cancellation Policy Filter
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

        $amenities        = $request->input('amenities');

        // 4. Property Type Filter (Supports multiple comma-separated types)
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

        // 5. Amenities Filter (Property or Room satisfies ANY selected amenity)
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

        // 6. Database-Level Rating Filter (Aggregated from real reviews)
        if (!empty($minRating)) {
            $query->whereHas('reviews', function($rq) use ($minRating) {
                $rq->selectRaw('AVG(rating) as avg_rating')
                   ->havingRaw('AVG(rating) >= ?', [$minRating]);
            })->orWhereDoesntHave('reviews');
        }

        // 5. Database-Level Sorting across complete result set
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
            // Default recommended sort (verified high rating & active rooms prioritized)
            $query->orderBy('reviews_avg_rating', 'desc')->orderBy('id', 'asc');
        }

        // 6. Database Pagination (Global backend slicing)
        $perPage = (int)($request->input('per_page') ?? $request->input('limit') ?? 20);
        $perPage = min(max(1, $perPage), 100); // safety bounds

        if ($request->has('page') || $request->has('per_page')) {
            $paginator = $query->paginate($perPage);
            return response()->json($paginator)->header('X-Cache', 'MISS');
        }

        $properties = $query->get();

        // 7. Master OTA Availability Engine Filter: Evaluate night-by-night inventory & guest capacity for every property
        /** @var RoomAvailabilityService $availabilityService */
        $availabilityService = app(RoomAvailabilityService::class);

        $properties = $properties->filter(function ($property) use ($checkIn, $checkOut, $isValidDateRange, $requiredRooms, $totalGuests, $adults, $children, $availabilityService) {
            $hasQualifiedRoom = false;
            $propertyAvailableInventorySum = 0;
            $propertySatisfyingCapacitySum = 0;

            foreach ($property->rooms as $room) {
                $roomCap = (int) ($room->capacity ?? 2);
                $maxAdults = (int) ($room->max_adults ?? $roomCap);
                $roomInv = max(1, (int) ($room->total_inventory ?? 1));

                // 1. Check if room capacity fits total guests
                $singleRoomCapacityOk = ($roomCap >= $totalGuests) || ($maxAdults >= $adults);

                // 2. Inventory & Date Range validation (if stay dates provided)
                $availableInventoryForRoom = $roomInv;
                if ($isValidDateRange) {
                    $availResult = $availabilityService->checkRoomAvailability(
                        $room->id,
                        $checkIn,
                        $checkOut,
                        1 // Check single unit availability per room instance
                    );

                    if (!$availResult['is_available']) {
                        continue;
                    }
                    $availableInventoryForRoom = $availResult['min_available_inventory'];
                }

                if ($availableInventoryForRoom < 1) {
                    continue;
                }

                // If single room satisfies capacity and has enough inventory for requested rooms
                if ($singleRoomCapacityOk && $availableInventoryForRoom >= $requiredRooms) {
                    $hasQualifiedRoom = true;
                    break;
                }

                // Accumulate capacity ONLY for rooms that are available
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

        // Store result in cache as array
        if ($isCacheable) {
            Cache::put($cacheKey, $propertiesArray, $ttl);
        }

        return response()->json($propertiesArray)->header('X-Cache', 'MISS');
    }

    /**
     * Build a deterministic Redis cache key for a property search query.
     */
    private function buildCacheKey(?string $search, ?string $city): string
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

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'required|string',
            'area' => 'required|string',
            'price_per_night' => 'required|numeric|min:0',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'image_url' => 'nullable|string',
        ]);

        // Restrict property creation to lodge owners or admins
        $user = $request->user();
        if ($user->role !== 'owner' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. Only hosts or admins can list properties.'
            ], 403);
        }

        $property = Property::create([
            'name' => $request->name,
            'description' => $request->description,
            'address' => $request->address,
            'city' => $request->city,
            'area' => $request->area,
            'price_per_night' => $request->price_per_night,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'host_id' => $user->id,
            'image_url' => $request->image_url,
        ]);

        // Bust Redis cache for this city and all-properties list
        InvalidatePropertyCache::dispatch($property->id, $property->city);

        // Sync to Meilisearch search index
        $this->syncWithMeilisearch($property);

        return response()->json($property->load('rooms'), 201);
    }

    public function show(Request $request, $id)
    {
        $property = Property::with(['rooms', 'host', 'reviews.user'])->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        // If the property has an explicit inactive status, restrict to host or admin
        $propStatus = strtolower($property->status ?? 'active');
        if ($propStatus !== 'active') {
            $user = $request->user('sanctum');
            if (!$user || ($user->role !== 'admin' && $property->host_id !== $user->id)) {
                return response()->json(['message' => 'Property is currently inactive or private.'], 404);
            }
        }

        $user = $request->user('sanctum');
        $userId = $user ? $user->id : null;

        $checkIn = $request->input('check_in');
        $checkOut = $request->input('check_out');
        $guests = (int) $request->input('guests', 1);
        $requiredRooms = (int) $request->input('rooms', 1);

        $hasValidDates = false;
        if (!empty($checkIn) && !empty($checkOut)) {
            $cIn = strtotime($checkIn);
            $cOut = strtotime($checkOut);
            if ($cIn && $cOut && $cOut > $cIn) {
                $hasValidDates = true;
            }
        }

        // Cache base property data when generic view is requested
        $cacheKey = "property:detail:{$id}";
        if (!$hasValidDates && !$userId && $guests === 1 && $requiredRooms === 1) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached)->header('X-Cache', 'HIT');
            }
        }

        // ─── Batch Load Bookings and Room Locks to eliminate N+1 latency ──────
        $roomIds = $property->rooms->pluck('id')->toArray();
        $bookedRoomIds = [];
        $activeLocksMap = [];

        if (!empty($roomIds)) {
            if ($hasValidDates) {
                // Correct overlap formula: two date ranges [A,B) and [C,D) overlap iff A < D && B > C
                // A booking ending exactly on our check-in date does NOT conflict.
                $bookedRoomIds = \App\Models\Booking::whereIn('room_id', $roomIds)
                    ->whereNotIn('status', ['Cancelled', 'cancelled'])
                    ->where('check_in', '<', $checkOut)
                    ->where('check_out', '>', $checkIn)
                    ->pluck('room_id')
                    ->flip()
                    ->toArray();
            }

            $activeLocks = \App\Models\RoomLock::whereIn('room_id', $roomIds)
                ->where('expires_at', '>', now())
                ->get();
            foreach ($activeLocks as $lock) {
                $activeLocksMap[$lock->room_id] = $lock->guest_id;
            }
        }

        // Evaluate availability for each room belonging to property
        foreach ($property->rooms as $room) {
            // Check guest capacity
            $capacityOk = true;
            if ($guests > 0) {
                $capacityOk = ($room->capacity >= $guests) ||
                             ($room->max_adults && $room->max_adults >= $guests);
            }
            $room->meets_capacity = $capacityOk;

            // Availability is driven by actual booking records + date overlap, NOT the status field.
            // The status field ('booked', 'available', etc.) is a cached/administrative label that
            // can be stale. Real availability = no overlapping confirmed booking + not under maintenance.
            $isBooked = isset($bookedRoomIds[$room->id]);

            // Check temporary lock status
            $lockedByGuestId = $activeLocksMap[$room->id] ?? null;
            if ($lockedByGuestId !== null) {
                $room->is_locked = true;
                $room->locked_by_me = ($userId && $lockedByGuestId == $userId);
            } else {
                $room->is_locked = false;
                $room->locked_by_me = false;
            }

            // Only permanently out-of-service status values block availability.
            // 'booked' / 'available' status field is ignored — booking records are the truth.
            $isLockedByOther = $room->is_locked && !$room->locked_by_me;
            $statusLower = strtolower($room->status ?? '');
            $permanentlyUnavailable = in_array($statusLower, ['maintenance', 'out_of_service', 'inactive', 'disabled']);

            $room->is_available = $capacityOk && !$isBooked && !$isLockedByOther && !$permanentlyUnavailable;
            $room->unavailability_reason = !$capacityOk
                ? "Exceeds max room capacity ({$room->capacity} guests max)"
                : ($permanentlyUnavailable
                    ? "Room is currently under maintenance"
                    : ($isBooked
                        ? "Reserved for selected dates"
                        : ($isLockedByOther ? "Temporarily held by another guest" : null)));
        }

        if (!$hasValidDates && !$userId && $guests === 1 && $requiredRooms === 1) {
            Cache::put($cacheKey, $property->toArray(), 600);
        }

        return response()->json($property)->header('X-Cache', 'MISS');
    }

    protected function syncWithMeilisearch(Property $property)
    {
        // Try posting to self-hosted Meilisearch instance configured in environment
        try {
            $meiliHost = env('MEILISEARCH_HOST', 'http://127.0.0.1:7700');
            $meiliKey = env('MEILISEARCH_KEY');

            $document = [
                'id' => $property->id,
                'name' => $property->name,
                'description' => $property->description,
                'city' => $property->city,
                'area' => $property->area,
                'price' => $property->price_per_night,
            ];

            if ($property->latitude !== null && $property->longitude !== null) {
                $document['_geo'] = [
                    'lat' => (double) $property->latitude,
                    'lng' => (double) $property->longitude,
                ];
            }

            Http::withHeaders([
                'Authorization' => "Bearer {$meiliKey}"
            ])->post("{$meiliHost}/indexes/properties/documents", [$document]);
        } catch (\Exception $e) {
            Log::warning('Meilisearch not reachable. Synced skipped: ' . $e->getMessage());
        }
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|image|max:10240', // 10MB max
        ]);

        if ($request->hasFile('file')) {
            try {
                $file = $request->file('file');
                $filename = uniqid('img_') . '.webp';
                $path = 'properties/' . $filename;

                // Compress and convert to WebP using Intervention Image
                $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
                $image = $manager->read($file->getRealPath());
                $image->scaleDown(width: 1600); // Scale down large images
                $encodedImage = $image->toWebp(80); // Compress to 80% quality WebP

                // Upload to Supabase Storage via S3 driver
                \Illuminate\Support\Facades\Storage::disk('s3')->put($path, (string) $encodedImage, 'public');

                // Return public URL from Supabase
                $supabaseUrl = rtrim(env('AWS_ENDPOINT'), '/s3') . '/object/public/' . env('AWS_BUCKET') . '/' . $path;

                return response()->json([
                    'url' => $supabaseUrl
                ]);
            } catch (\Exception $e) {
                Log::error('Image upload failed: ' . $e->getMessage());
                return response()->json(['message' => 'Image processing failed.'], 500);
            }
        }

        return response()->json(['message' => 'No file uploaded'], 400);
    }

    public function getRooms(Request $request, $propertyId)
    {
        $property = Property::find($propertyId);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        // Authenticated user permission validation
        $user = $request->user();
        if ($user->role !== 'admin' && $property->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to view these rooms.'], 403);
        }

        return response()->json($property->rooms);
    }

    public function storeRoom(Request $request, $propertyId)
    {
        $property = Property::find($propertyId);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        // Authenticated user permission validation
        $user = $request->user();
        if ($user->role !== 'admin' && $property->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to add rooms to this property.'], 403);
        }

        $request->validate([
            'room_number' => 'required|string|max:255',
            'room_type_id' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1',
            'amenities' => 'nullable|array',
            'photos' => 'nullable|array',
            'description' => 'nullable|string',
            'floor' => 'nullable|string|max:255',
            'max_adults' => 'nullable|integer|min:1',
            'max_children' => 'nullable|integer|min:0',
            'bed_configuration' => 'nullable|string|max:255',
            'number_of_beds' => 'nullable|integer|min:1',
            'room_size' => 'nullable|string|max:255',
        ]);

        // Duplicate room check (case-insensitive check for same property)
        $duplicate = Room::where('property_id', $propertyId)
            ->whereRaw('LOWER(room_number) = ?', [strtolower(trim($request->room_number))])
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => "Room {$request->room_number} already exists in {$property->name}."
            ], 422);
        }

        $room = Room::create([
            'property_id' => $propertyId,
            'room_number' => trim($request->room_number),
            'room_type_id' => trim($request->room_type_id),
            'price' => $request->price,
            'capacity' => $request->capacity,
            'status' => $request->status ?? 'available',
            'amenities' => $request->amenities ?? [],
            'photos' => $request->photos ?? [],
            'description' => $request->description,
            'floor' => $request->floor,
            'max_adults' => $request->max_adults ?? 1,
            'max_children' => $request->max_children ?? 0,
            'bed_configuration' => $request->bed_configuration,
            'number_of_beds' => $request->number_of_beds ?? 1,
            'room_size' => $request->room_size,
        ]);

        return response()->json($room, 201);
    }

    public function updateRoom(Request $request, $id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $property = Property::find($room->property_id);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        // Authenticated user permission validation
        $user = $request->user();
        if ($user->role !== 'admin' && $property->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to update this room.'], 403);
        }

        $request->validate([
            'room_number' => 'required|string|max:255',
            'room_type_id' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1',
            'amenities' => 'nullable|array',
            'photos' => 'nullable|array',
            'description' => 'nullable|string',
            'floor' => 'nullable|string|max:255',
            'max_adults' => 'nullable|integer|min:1',
            'max_children' => 'nullable|integer|min:0',
            'bed_configuration' => 'nullable|string|max:255',
            'number_of_beds' => 'nullable|integer|min:1',
            'room_size' => 'nullable|string|max:255',
        ]);

        // Duplicate room check (excluding current room ID)
        $duplicate = Room::where('property_id', $room->property_id)
            ->where('id', '!=', $id)
            ->whereRaw('LOWER(room_number) = ?', [strtolower(trim($request->room_number))])
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => "Room {$request->room_number} already exists in this property."
            ], 422);
        }

        $room->update([
            'room_number' => trim($request->room_number),
            'room_type_id' => trim($request->room_type_id),
            'price' => $request->price,
            'capacity' => $request->capacity,
            'status' => $request->status ?? $room->status,
            'amenities' => $request->amenities ?? [],
            'photos' => $request->photos ?? [],
            'description' => $request->description,
            'floor' => $request->floor,
            'max_adults' => $request->max_adults ?? 1,
            'max_children' => $request->max_children ?? 0,
            'bed_configuration' => $request->bed_configuration,
            'number_of_beds' => $request->number_of_beds ?? 1,
            'room_size' => $request->room_size,
        ]);

        return response()->json($room);
    }

    public function destroyRoom(Request $request, $id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $property = Property::find($room->property_id);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        // Authenticated user permission validation
        $user = $request->user();
        if ($user->role !== 'admin' && $property->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to delete this room.'], 403);
        }

        $room->delete();

        return response()->json(['message' => 'Room deleted successfully']);
    }
}
