<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = Property::with('rooms');

        if ($request->has('city')) {
            $query->where('city', 'like', '%' . $request->city . '%');
        }

        if ($request->has('price_max')) {
            $query->where('price_per_night', '<=', $request->price_max);
        }

        $properties = $query->get();

        return response()->json($properties);
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

        // Simulated Meilisearch sync index trigger
        $this->syncWithMeilisearch($property);

        return response()->json($property->load('rooms'), 201);
    }

    public function show(Request $request, $id)
    {
        $property = Property::with(['rooms', 'host'])->find($id);

        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        $user = $request->user('sanctum');
        $userId = $user ? $user->id : null;

        // Append real-time temporary lock status to each room
        foreach ($property->rooms as $room) {
            $activeLock = \App\Models\RoomLock::where('room_id', $room->id)
                ->where('expires_at', '>', now())
                ->first();

            if ($activeLock) {
                if ($userId && $activeLock->guest_id == $userId) {
                    $room->is_locked = true;
                    $room->locked_by_me = true;
                } else {
                    $room->is_locked = true;
                    $room->locked_by_me = false;
                }
            } else {
                $room->is_locked = false;
                $room->locked_by_me = false;
            }
        }

        return response()->json($property);
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
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:4096',
        ]);

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('properties', 'public');
            return response()->json([
                'url' => url('storage/' . $path)
            ]);
        }

        return response()->json(['message' => 'No file uploaded'], 400);
    }
}
