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

class PropertyController extends Controller
{
    public function index(Request $request, PropertySearchService $searchService)
    {
        $result = $searchService->search($request);
        if (isset($result['paginator'])) {
            return response()->json($result['paginator'])->header('X-Cache', $result['cache_status']);
        }
        return response()->json($result['data'])->header('X-Cache', $result['cache_status']);
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

    public function show(Request $request, $id, PropertyDetailService $detailService)
    {
        $property = Property::with(['rooms', 'host', 'reviews.user'])->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

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

        $cacheKey = "property:detail:{$id}";
        if (empty($checkIn) && empty($checkOut) && !$userId && $guests === 1 && $requiredRooms === 1) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached)->header('X-Cache', 'HIT');
            }
        }

        $result = $detailService->getPropertyDetail($property, $checkIn, $checkOut, $guests, $requiredRooms, $userId);

        if (!$result['has_valid_dates'] && !$userId && $guests === 1 && $requiredRooms === 1) {
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
