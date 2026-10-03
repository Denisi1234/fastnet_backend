<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Room;
use App\Services\PropertyDetailService;
use App\Services\PropertySearchService;
use App\Services\RoomAvailabilityService;
use App\Jobs\InvalidatePropertyCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    public function index(Request $request, PropertySearchService $searchService)
    {
        $result = $searchService->search($request);
        // Always returns a paginator now; fall back to data array for cache HITs
        if (!empty($result['paginator'])) {
            return response()->json($result['paginator'])->header('X-Cache', $result['cache_status']);
        }
        return response()->json($result['data'])->header('X-Cache', $result['cache_status']);
    }

    public function suggestions(Request $request)
    {
        $q = trim($request->input('q') ?? '');
        if (strlen($q) < 2) {
            return response()->json([
                'destinations' => [],
                'properties' => []
            ]);
        }

        // 1. Fetch matching properties
        $properties = Property::select(['id', 'name', 'city', 'area', 'image_url'])
            ->withAvg('reviews', 'rating')
            ->where(function ($query) {
                $query->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']);
            })
            ->where(function ($query) use ($q) {
                $query->where('name', 'ilike', '%' . $q . '%')
                      ->orWhere('city', 'ilike', '%' . $q . '%')
                      ->orWhere('area', 'ilike', '%' . $q . '%');
            })
            ->orderByRaw("
                CASE 
                    WHEN name ILIKE ? THEN 1
                    WHEN name ILIKE ? THEN 2
                    ELSE 3
                END ASC
            ", [$q, $q . '%'])
            ->limit(5)
            ->get();

        $mappedProperties = $properties->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'city' => $p->city,
                'district' => $p->area,
                // A property with no reviews has no score. It used to default
                // to 9.0/10, so unreviewed lodges sorted to the top of search
                // with a score nobody had given them.
                'score' => $p->reviews_avg_rating !== null
                    ? round((float) $p->reviews_avg_rating, 1)
                    : null,
                'image' => $p->image_url
            ];
        });

        // 2. Fetch matching destinations/cities
        $destinations = Property::select('city')
            ->where(function ($query) {
                $query->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']);
            })
            ->where('city', 'ilike', '%' . $q . '%')
            ->groupBy('city')
            ->limit(3)
            ->pluck('city');

        // One grouped aggregate for all suggestion cities instead of loading
        // every matching property row just to derive MIN() and COUNT().
        $cityStats = $destinations->isEmpty()
            ? collect()
            : Property::whereIn('city', $destinations)
                ->where(function ($query) {
                    $query->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']);
                })
                ->groupBy('city')
                ->selectRaw('city, MIN(price_per_night) as min_price, COUNT(*) as properties_count')
                ->get()
                ->keyBy('city');

        $mappedDestinations = $destinations->map(function ($city) use ($cityStats) {
            $stat = $cityStats->get($city);

            return [
                'city' => $city,
                'country' => 'Tanzania',
                'propertiesCount' => $stat ? (int) $stat->properties_count : 0,
                // TZS, not USD - the key name mislabelled the currency, and the
                // $50 default invented a starting price for cities with no
                // priced properties.
                'starting_price' => $stat && $stat->min_price !== null
                    ? round((float) $stat->min_price)
                    : null,
                'currency' => 'TZS',
            ];
        });

        return response()->json([
            'destinations' => $mappedDestinations,
            'properties' => $mappedProperties
        ]);
    }

    /**
     * Destination listing for the /destination-detail pages.
     *
     * The web app calls GET /destinations but no such route existed, so the
     * page always rendered empty. There is no destinations table, so this
     * aggregates real active properties by city and derives a starting price
     * from actual room rates - no invented places or prices.
     */
    public function destinations(Request $request)
    {
        $limit = max(1, min(50, (int) $request->input('limit', 24)));

        $rows = Property::query()
            ->select(['city', 'area', 'name'])
            ->where(function ($q) {
                $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']);
            })
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->groupBy('city', 'area', 'name')
            ->orderBy('city')
            ->limit($limit * 4)
            ->get();

        $destinations = [];

        foreach ($rows as $row) {
            $city = trim((string) $row->city);
            $key  = strtolower($city);

            if (isset($destinations[$key])) {
                $destinations[$key]['property_count']++;
                continue;
            }

            $property = Property::with('rooms')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']);
                })
                ->where('city', $city)
                ->orderByDesc('reviews_avg_rating')
                ->first();

            if (! $property) {
                continue;
            }

            // Lowest genuine room rate, falling back to the property's own rate.
            $prices = $property->rooms->pluck('price')->filter()->map(fn ($p) => (float) $p);
            $min = $prices->isNotEmpty() ? $prices->min() : (float) ($property->price_per_night ?? 0);

            $destinations[$key] = [
                'id'              => $property->id,
                'name'            => $property->area ? $city . ' - ' . $property->area : $city,
                'title'           => $property->name,
                'city'            => $city,
                'area'            => $property->area,
                'image_url'       => $property->primary_image_url ?: $property->image_url,
                'price'           => $min > 0 ? $min : null,
                'price_per_night' => $min > 0 ? $min : null,
                'currency'        => 'TZS',
                'rating'          => $property->reviews_avg_rating !== null
                                        ? (float) $property->reviews_avg_rating
                                        : null,
                'property_count'  => 1,
                'url'             => '/hotel-detail/' . $property->id,
            ];
        }

        $list = array_values($destinations);
        $list = array_slice($list, 0, $limit);

        return response()->json([
            'status' => 'success',
            'data'   => $list,
            'count'  => count($list),
        ]);
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
            'amenities' => 'nullable',
        ]);

        // Restrict property creation to lodge owners or admins
        $user = $request->user();
        if ($user->role !== 'owner' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized. Only hosts or admins can list properties.'
            ], 403);
        }

        $amenitiesVal = $request->amenities;
        if (is_array($amenitiesVal)) {
            $amenitiesVal = json_encode(array_values(array_unique(array_filter(array_map('trim', $amenitiesVal)))));
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
            'amenities' => $amenitiesVal,
        ]);

        // Bust Redis cache for this city and all-properties list
        PropertySearchService::bumpSearchVersion();
        InvalidatePropertyCache::dispatch($property->id, $property->city);

        // Sync to Meilisearch search index
        $this->syncWithMeilisearch($property);

        return response()->json($property->load('rooms'), 201);
    }

    public function show(Request $request, $id, PropertyDetailService $detailService)
    {
        $cacheKey = "property:detail:v2:{$id}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached)->header('X-Cache', 'HIT');
        }

        $property = Property::with(['rooms:id,property_id,room_number,room_type_id,price,capacity,status,amenities,photos,floor,max_adults,max_children,bed_configuration,number_of_beds,room_size', 'host:id,name,email', 'reviews:id,property_id,user_id,rating,comment,created_at'])->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        $propStatus = strtolower($property->status ?? 'active');
        if ($propStatus !== 'active') {
            $user = $request->user('sanctum');
            if (!$user || ($user->role !== 'admin' && (int)$property->host_id !== (int)$user->id)) {
                return response()->json(['message' => 'Property is currently inactive or private.'], 404);
            }
        }

        $user = $request->user('sanctum');
        $userId = $user ? $user->id : null;

        $checkIn = $request->input('check_in');
        $checkOut = $request->input('check_out');
        $guests = (int) $request->input('guests', 1);
        $requiredRooms = (int) $request->input('rooms', 1);

        $result = $detailService->getPropertyDetail($property, $checkIn, $checkOut, $guests, $requiredRooms, $userId);

        // Only cache when rooms loaded successfully.
        // If rooms are empty due to a slow/flaky DB connection we skip caching
        // so the next request retries a fresh DB fetch and gets the real rooms.
        if ($property->rooms->isNotEmpty()) {
            Cache::put($cacheKey, $property->toArray(), 600);
        }

        return response()->json($property)->header('X-Cache', 'MISS');
    }

    public function getImages(Request $request, $id)
    {
        $property = Property::with('rooms:id,property_id,photos')->find($id);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        $images = [];
        if (!empty($property->image_url)) {
            $images[] = [
                'url' => $property->image_url,
                'is_hero' => true,
                'caption' => $property->name
            ];
        }

        foreach ($property->rooms as $room) {
            $photos = is_string($room->photos) ? json_decode($room->photos, true) : $room->photos;
            if (is_array($photos)) {
                foreach ($photos as $photo) {
                    if (is_string($photo) && !empty($photo)) {
                        $images[] = [
                            'url' => $photo,
                            'is_hero' => false,
                            'room_id' => $room->id
                        ];
                    }
                }
            }
        }

        return response()->json([
            'property_id' => (int)$id,
            'total_count' => count($images),
            'images' => $images
        ]);
    }

    public function update(Request $request, $id)
    {
        $property = Property::find($id);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        $user = $request->user();
        if ($user->role !== 'admin' && (int) $property->host_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Forbidden. You do not own this property.'
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'sometimes|required|string|max:255',
            'area' => 'sometimes|required|string|max:255',
            'price_per_night' => 'sometimes|required|numeric|min:0',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'image_url' => 'nullable|string',
            'amenities' => 'nullable',
        ]);

        $amenitiesVal = $request->has('amenities') ? $request->amenities : null;
        if (is_array($amenitiesVal)) {
            $amenitiesVal = json_encode(array_values(array_unique(array_filter(array_map('trim', $amenitiesVal)))));
        }

        $updateData = array_filter([
            'name' => $request->name ?? $property->name,
            'description' => $request->description ?? $property->description,
            'address' => $request->address ?? $property->address,
            'city' => $request->city ?? $property->city,
            'area' => $request->area ?? $property->area,
            'price_per_night' => $request->price_per_night ?? $property->price_per_night,
            'latitude' => $request->latitude ?? $property->latitude,
            'longitude' => $request->longitude ?? $property->longitude,
            'image_url' => $request->image_url ?? $property->image_url,
            'amenities' => $amenitiesVal !== null ? $amenitiesVal : $property->amenities,
        ], function($val) { return $val !== null; });

        $property->update($updateData);

        // Bust Redis cache for this city and all-properties list
        PropertySearchService::bumpSearchVersion();
        InvalidatePropertyCache::dispatch($property->id, $property->city);

        // Sync to Meilisearch search index
        $this->syncWithMeilisearch($property);

        return response()->json($property->fresh()->load('rooms'));
    }

    public function generateDescription(Request $request, $id)
    {
        $property = Property::with('rooms')->find($id);
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        $user = $request->user();
        if ($user->role !== 'admin' && (int) $property->host_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. You do not own this property.'], 403);
        }

        $roomTypes = [];
        $allAmenities = [];
        foreach ($property->rooms as $rm) {
            if (!empty($rm->room_type_id)) $roomTypes[] = trim($rm->room_type_id);
            if (!empty($rm->amenities)) {
                $decoded = is_string($rm->amenities) ? json_decode($rm->amenities, true) : $rm->amenities;
                if (!is_array($decoded)) {
                    $decoded = explode(',', $rm->amenities);
                }
                foreach ($decoded as $am) {
                    $trimmed = trim($am);
                    if (!empty($trimmed) && !in_array($trimmed, $allAmenities)) {
                        $allAmenities[] = $trimmed;
                    }
                }
            }
        }
        $uniqueRoomTypes = array_unique($roomTypes);

        // Only state facts the property record actually contains. The previous
        // copy called every lodge "premier", promised "premium service" and
        // "an exceptional stay", and claimed it sat "conveniently near local
        // attractions and transit" - none of which comes from any stored data,
        // and none of which the platform can stand behind.
        $where = trim(implode(', ', array_filter([$property->area, $property->city])));

        $desc = $property->name
            . ($where !== '' ? " is located in {$where}." : '.');
        $desc .= ' ';

        if (!empty($uniqueRoomTypes)) {
            $roomCount = $property->rooms->count();
            $desc .= sprintf(
                'It offers %d %s: %s.',
                $roomCount,
                $roomCount === 1 ? 'room' : 'rooms',
                implode(', ', $uniqueRoomTypes)
            );
        }

        if (!empty($allAmenities)) {
            $desc .= ' Listed amenities: ' . implode(', ', array_slice($allAmenities, 0, 8)) . '.';
        }

        if ($property->reviews_count ?? 0) {
            $desc .= sprintf(' It has %d guest review(s).', (int) $property->reviews_count);
        }

        if ($property->reviews_avg_rating !== null) {
            $desc .= sprintf(' Average rating %.1f out of 5.', (float) $property->reviews_avg_rating);
        }

        return response()->json([
            'description' => $desc,
            'property_id' => $property->id,
        ]);
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
        // This route is public, so without a session anyone could write files
        // to the public disk. Uploads are only ever done by signed-in hosts.
        if (! $request->user('sanctum') && ! $request->user()) {
            return response()->json(['message' => 'Authentication required to upload files.'], 401);
        }

        $request->validate([
            'file' => 'required|image|max:10240', // 10MB max
        ]);

        if ($request->hasFile('file')) {
            try {
                $file = $request->file('file');
                $disk = 'public';
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
                $originalExtension = strtolower((string) $file->getClientOriginalExtension());
                if (!in_array($originalExtension, $allowedExtensions, true)) {
                    $originalExtension = 'jpg';
                }
                $filename = uniqid('img_') . '.' . $originalExtension;
                $path = 'properties/' . $filename;

                Log::info('Room photo upload started', [
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'disk' => $disk,
                    'path' => $path,
                ]);

                $storedPath = $file->storePubliclyAs('properties', $filename, $disk);
                if (!$storedPath) {
                    throw new \RuntimeException('Upload could not be stored on the public disk.');
                }

                if (!Storage::disk($disk)->exists($storedPath)) {
                    throw new \RuntimeException('Upload completed but the stored file could not be verified.');
                }

                // Return a stable public URL that the browser can reload later.
                $publicUrl = Storage::disk($disk)->url($storedPath);
                if (!$publicUrl || !is_string($publicUrl)) {
                    Storage::disk($disk)->delete($storedPath);
                    throw new \RuntimeException('Could not resolve public image URL after upload.');
                }

                Log::info('Room photo upload completed', [
                    'disk' => $disk,
                    'path' => $storedPath,
                    'url' => $publicUrl,
                ]);

                return response()->json([
                    'path' => $storedPath,
                    'disk' => $disk,
                    'url' => $publicUrl
                ]);
            } catch (\Exception $e) {
                Log::error('Image upload failed: ' . $e->getMessage());
                return response()->json(['message' => 'Image upload failed.'], 500);
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

        // Return property rooms (publicly accessible or for host/admin portal view)
        return response()->json($property->rooms);
    }

    /**
     * List rooms scoped to authenticated user (owner sees own, admin sees all).
     * Real working endpoint for web host portal /host/rooms.
     */
    public function indexRooms(Request $request)
    {
        $user = $request->user();
        $query = Room::with(['property'])->orderBy('created_at', 'desc');
        if ($user && $user->role !== 'admin') {
            $propertyIds = Property::where('host_id', $user->id)->pluck('id')->toArray();
            if (empty($propertyIds)) {
                return response()->json([]);
            }
            $query->whereIn('property_id', $propertyIds);
        }
        if ($request->filled('property_id')) {
            $query->where('property_id', $request->input('property_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('room_number', 'ILIKE', "%{$s}%")
                  ->orWhere('room_type_id', 'ILIKE', "%{$s}%")
                  ->orWhere('status', 'ILIKE', "%{$s}%");
            });
        }
        return response()->json($query->get());
    }

    /**
     * Show single room (owner/admin scoped).
     */
    public function showRoom(Request $request, $id)
    {
        $room = Room::with(['property'])->find($id);
        if (!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }
        $user = $request->user();
        if ($user && $user->role !== 'admin' && (int)optional($room->property)->host_id !== (int)$user->id) {
            return response()->json(['message' => 'Unauthorized to view this room.'], 403);
        }
        return response()->json($room);
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

        // Web portal sends room_type/type alias + customer_price; normalise to room_type_id/price
        if (!$request->filled('room_type_id')) {
            $alias = $request->input('room_type', $request->input('type', 'Standard'));
            $request->merge(['room_type_id' => is_string($alias) ? $alias : 'Standard']);
        }
        if (!$request->filled('price') && $request->filled('customer_price')) {
            $request->merge(['price' => $request->input('customer_price')]);
        }
        if (!$request->filled('capacity')) {
            $cap = (int)$request->input('max_adults', 2);
            $request->merge(['capacity' => max(1, $cap)]);
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

        if ($this->containsLegacyRoomAssetPath($request->input('photos') ?? [])) {
            return response()->json([
                'message' => 'Room photos must be public storage URLs or public storage paths. Legacy assets/images paths are not room media.'
            ], 422);
        }

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

        PropertySearchService::bumpSearchVersion();
        Cache::forget("property:detail:v2:{$property->id}");
        InvalidatePropertyCache::dispatch($property->id, $property->city);

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

        // Ownership check.
        // The guard used to be `if ($user && ...)`, so on this public route an
        // entirely unauthenticated request skipped the check altogether and
        // could rewrite any room in the system.
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($user->role !== 'admin' && (int) $property->host_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized to update this room.'], 403);
        }

        // Normalise web portal aliases: room_type/type -> room_type_id, customer_price -> price
        if ($request->filled('room_type') && !$request->filled('room_type_id')) {
            $request->merge(['room_type_id' => $request->input('room_type')]);
        }
        if ($request->filled('type') && !$request->filled('room_type_id')) {
            $request->merge(['room_type_id' => $request->input('type')]);
        }
        if ($request->filled('customer_price') && !$request->filled('price')) {
            $request->merge(['price' => $request->input('customer_price')]);
        }

        $request->validate([
            'room_number' => 'sometimes|string|max:255',
            'room_type_id' => 'sometimes|string|max:255',
            'room_type' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'customer_price' => 'sometimes|numeric|min:0',
            'capacity' => 'sometimes|integer|min:1',
            'status' => 'sometimes|string|max:50',
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

        if ($request->filled('photos') && $this->containsLegacyRoomAssetPath((array)$request->input('photos'))) {
            return response()->json([
                'message' => 'Room photos must be public storage URLs or public storage paths. Legacy assets/images paths are not room media.'
            ], 422);
        }

        // Duplicate room check (excluding current room ID) — only when room_number changes
        if ($request->filled('room_number')) {
            $duplicate = Room::where('property_id', $room->property_id)
                ->where('id', '!=', $id)
                ->whereRaw('LOWER(room_number) = ?', [strtolower(trim((string)$request->room_number))])
                ->first();

            if ($duplicate) {
                return response()->json([
                    'message' => "Room {$request->room_number} already exists in this property."
                ], 422);
            }
        }

        $updatable = ['room_number', 'room_type_id', 'price', 'capacity', 'status', 'amenities', 'photos', 'description', 'floor', 'max_adults', 'max_children', 'bed_configuration', 'number_of_beds', 'room_size'];
        $payload = [];
        foreach ($updatable as $field) {
            if ($request->exists($field) && $request->input($field) !== null) {
                $val = $request->input($field);
                if (in_array($field, ['room_number', 'room_type_id'], true) && is_string($val)) {
                    $val = trim($val);
                }
                $payload[$field] = $val;
            }
        }
        if (empty($payload)) {
            return response()->json($room->fresh());
        }
        $room->update($payload);

        PropertySearchService::bumpSearchVersion();
        Cache::forget("property:detail:v2:{$property->id}");
        InvalidatePropertyCache::dispatch($property->id, $property->city);

        return response()->json($room);
    }

    /**
     * Legacy portal placeholders used assets/images/room/*. They are neither
     * uploaded room media nor browser-served backend files, so never persist
     * them as a room photo source.
     */
    private function containsLegacyRoomAssetPath(array $photos): bool
    {
        return collect($photos)->contains(function ($photo) {
            return is_string($photo) && preg_match('#(?:^|/)assets/images/room/#i', str_replace('\\', '/', $photo));
        });
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
        $user = $request->user('sanctum') ?? $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($user->role !== 'admin' && (int)$property->host_id !== (int)$user->id) {
            return response()->json(['message' => 'Unauthorized to delete this room.'], 403);
        }

        $room->delete();

        return response()->json(['message' => 'Room deleted successfully']);
    }
}
