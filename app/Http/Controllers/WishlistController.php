<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Wishlist;
use App\Models\Property;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user('sanctum') ?? $request->user();
            $userId = $user ? $user->id : ($request->input('user_id') ?? Auth::id());

            $query = Wishlist::with('property');
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $wishlist = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($w) {
                    $prop = $w->property;
                    if (!$prop) return null;
                    return [
                        'id' => $prop->id,
                        'name' => $prop->name,
                        'city' => $prop->city ?? 'Tanzania',
                        'star_rating' => $prop->star_rating ?? 4,
                        'rating' => $prop->rating ?? 4.8,
                        'image_url' => $prop->image_url ?? $prop->primary_image_url ?? '',
                        'primary_image_url' => $prop->primary_image_url ?? $prop->image_url ?? '',
                        'price' => $prop->price_per_night ?? $prop->price ?? 0,
                        'price_per_night' => $prop->price_per_night ?? $prop->price ?? 0,
                        'wishlist_id' => $w->id,
                        'created_at' => $w->created_at ? $w->created_at->toIso8601String() : null,
                    ];
                })
                ->filter()
                ->values();

            return response()->json($wishlist);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }
    
    public function store(Request $request)
    {
        $request->validate(['property_id' => 'required|integer']);
        try {
            $user = $request->user('sanctum') ?? $request->user();
            $userId = $user ? $user->id : ($request->input('user_id') ?? Auth::id());

            if (!$userId) {
                $firstUser = \App\Models\User::first();
                $userId = $firstUser ? $firstUser->id : 1;
            }

            $item = Wishlist::firstOrCreate([
                'user_id' => $userId,
                'property_id' => (int)$request->property_id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Added to favourites',
                'item' => $item
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
    public function destroy(Request $request, $propertyId)
    {
        try {
            $user = $request->user('sanctum') ?? $request->user();
            $userId = $user ? $user->id : ($request->input('user_id') ?? Auth::id());

            $query = Wishlist::where('property_id', (int)$propertyId);
            if ($userId) {
                $query->where('user_id', $userId);
            }
            $query->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Removed from favourites',
                'ok' => true
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
