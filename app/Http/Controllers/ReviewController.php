<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Review;

class ReviewController extends Controller
{
    public function index($propertyId)
    {
        // Check if Review model/table exists, return empty if not
        try {
            $reviews = Review::with('user:id,name')
                ->where('property_id', $propertyId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'user_name' => $r->user->name ?? 'Guest',
                        'rating' => $r->rating,
                        'comment' => $r->comment,
                        'created_at' => $r->created_at,
                    ];
                });
            return response()->json($reviews);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }
    
    public function store(Request $request)
    {
        $request->validate([
            'property_id' => 'required|integer',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'booking_id' => 'nullable|integer',
        ]);
        
        try {
            $review = Review::create([
                'user_id' => Auth::id(),
                'property_id' => $request->property_id,
                'booking_id' => $request->booking_id,
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);
            return response()->json($review, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Could not save review: ' . $e->getMessage()], 500);
        }
    }
}
