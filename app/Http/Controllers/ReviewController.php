<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Review;

class ReviewController extends Controller
{
    public function index($propertyId)
    {
        try {
            $reviews = Review::with('user:id,name')
                ->where('property_id', $propertyId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($r) {
                    $name = !empty($r->guest_name) ? $r->guest_name : ($r->user->name ?? 'Verified Guest');
                    return [
                        'id' => $r->id,
                        'user_name' => $name,
                        'guest_name' => $name,
                        'rating' => (float)$r->rating,
                        'comment' => $r->comment,
                        'created_at' => $r->created_at ? $r->created_at->toIso8601String() : null,
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
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            'user_name' => 'nullable|string|max:100',
            'guest_name' => 'nullable|string|max:100',
            'booking_id' => 'nullable|integer',
        ]);
        
        try {
            $userId = Auth::id() ?? $request->user('sanctum')?->id;
            $guestName = trim((string)($request->input('guest_name') ?? $request->input('user_name') ?? ''));
            if (empty($guestName) && $userId) {
                $u = \App\Models\User::find($userId);
                $guestName = $u?->name;
            }
            if (empty($guestName)) {
                $guestName = 'Verified Guest';
            }

            $review = Review::create([
                'user_id' => $userId,
                'guest_name' => $guestName,
                'property_id' => (int)$request->property_id,
                'booking_id' => $request->booking_id,
                'rating' => (int)round($request->rating),
                'comment' => $request->comment,
            ]);

            // Clear property detail cache so new rating and review counts reflect immediately
            \Illuminate\Support\Facades\Cache::forget("property:detail:v2:{$request->property_id}");
            \Illuminate\Support\Facades\Cache::forget("property:{$request->property_id}");

            return response()->json([
                'id' => $review->id,
                'user_name' => $review->guest_name,
                'guest_name' => $review->guest_name,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at ? $review->created_at->toIso8601String() : null,
                'message' => 'Review submitted successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Could not save review: ' . $e->getMessage()], 500);
        }
    }

    public function adminIndex(Request $request)
    {
        $user = $request->user();
        $query = Review::with(['user:id,name', 'property:id,name'])->orderBy('created_at', 'desc');

        if ($user->role !== 'admin') {
            $ownerPropertyIds = \App\Models\Property::where('host_id', $user->id)->pluck('id')->toArray();
            if (empty($ownerPropertyIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('property_id', $ownerPropertyIds);
            }
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $paginator = $query->paginate($perPage);

        $transformed = collect($paginator->items())->map(function ($r) {
            return [
                'id' => $r->id,
                'user_name' => $r->user->name ?? 'Guest',
                'property_name' => $r->property->name ?? 'Lodge Stay',
                'rating' => $r->rating,
                'comment' => $r->comment,
                'created_at' => $r->created_at ? $r->created_at->format('Y-m-d H:i:s') : null,
            ];
        });

        return response()->json([
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'data'         => $transformed,
        ]);
    }
}
