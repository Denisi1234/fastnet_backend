<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Wishlist;
use App\Models\Property;

class WishlistController extends Controller
{
    public function index()
    {
        try {
            $wishlist = Wishlist::where('user_id', Auth::id())
                ->with('property')
                ->get()
                ->map(fn($w) => $w->property)
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
            $item = Wishlist::firstOrCreate([
                'user_id' => Auth::id(),
                'property_id' => $request->property_id,
            ]);
            return response()->json($item, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
    public function destroy($propertyId)
    {
        try {
            Wishlist::where('user_id', Auth::id())
                ->where('property_id', $propertyId)
                ->delete();
            return response()->json(['message' => 'Removed from wishlist.']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
