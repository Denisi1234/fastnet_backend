<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Property;

class AlertController extends Controller
{
    /**
     * Get active price alerts for the authenticated user or guest session.
     */
    public function index(Request $request)
    {
        $userId = Auth::id() ?? ($request->input('user_id') ?? 'guest_user');
        $cacheKey = "user_price_alerts_{$userId}";

        $alerts = Cache::get($cacheKey, []);

        return response()->json([
            'status' => 'success',
            'count' => count($alerts),
            'alerts' => $alerts,
        ]);
    }

    /**
     * Store or toggle a price alert for a property/stay.
     */
    public function store(Request $request)
    {
        $request->validate([
            'property_id' => 'nullable|integer',
            'property_name' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'target_price' => 'nullable|numeric',
            'current_price' => 'nullable|numeric',
        ]);

        $userId = Auth::id() ?? ($request->input('user_id') ?? 'guest_user');
        $cacheKey = "user_price_alerts_{$userId}";
        $alerts = Cache::get($cacheKey, []);

        $propertyId = $request->input('property_id');
        $propertyName = $request->input('property_name');
        $propertyImage = $request->input('image_url');
        $currentPrice = $request->input('current_price', 0);
        $city = $request->input('city', 'Tanzania');

        if ($propertyId && Property::where('id', $propertyId)->exists()) {
            $prop = Property::find($propertyId);
            $propertyName = $prop->name;
            $city = $prop->city ?? $city;
            $currentPrice = $prop->price_per_night ?? $currentPrice;
            $propertyImage = $prop->featured_image ?? $propertyImage;
        }

        $newAlert = [
            'id' => uniqid('alert_'),
            'property_id' => $propertyId,
            'property_name' => $propertyName ?: 'Luxury Stay',
            'city' => $city,
            'image_url' => $propertyImage ?: 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=400&q=80',
            'current_price' => (float)$currentPrice,
            'target_price' => $request->input('target_price') ? (float)$request->input('target_price') : (float)$currentPrice * 0.9,
            'is_active' => true,
            'created_at' => now()->toIso8601String(),
        ];

        // Check if alert already exists for this property
        $existsIndex = -1;
        foreach ($alerts as $idx => $alert) {
            if (isset($alert['property_id']) && $alert['property_id'] == $propertyId && $propertyId) {
                $existsIndex = $idx;
                break;
            }
        }

        if ($existsIndex >= 0) {
            $alerts[$existsIndex] = array_merge($alerts[$existsIndex], $newAlert);
        } else {
            array_unshift($alerts, $newAlert);
        }

        Cache::put($cacheKey, $alerts, now()->addDays(365));

        return response()->json([
            'status' => 'success',
            'message' => 'Price alert created successfully.',
            'alert' => $newAlert,
            'alerts' => $alerts,
        ], 201);
    }

    /**
     * Delete / remove a price alert.
     */
    public function destroy(Request $request, $id)
    {
        $userId = Auth::id() ?? ($request->input('user_id') ?? 'guest_user');
        $cacheKey = "user_price_alerts_{$userId}";
        $alerts = Cache::get($cacheKey, []);

        $filtered = array_values(array_filter($alerts, function($a) use ($id) {
            return ($a['id'] ?? '') !== $id && ($a['property_id'] ?? '') != $id;
        }));

        Cache::put($cacheKey, $filtered, now()->addDays(365));

        return response()->json([
            'status' => 'success',
            'message' => 'Price alert removed successfully.',
            'count' => count($filtered),
            'alerts' => $filtered,
        ]);
    }
}
