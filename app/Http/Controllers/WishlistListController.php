<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\WishlistList;

class WishlistListController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user('sanctum') ?? $request->user();
            $userId = $user ? $user->id : ($request->input('user_id') ?? Auth::id());

            $query = WishlistList::query();
            if ($userId) {
                $query->where('user_id', $userId);
            }

            $lists = $query->orderBy('created_at', 'desc')->get();

            // If empty, return default starter lists
            if ($lists->isEmpty()) {
                return response()->json([
                    ['id' => 'next_stay', 'name' => 'Your next stay', 'count' => 0],
                    ['id' => 'zanzibar', 'name' => 'Zanzibar Stays', 'count' => 0]
                ]);
            }

            return response()->json($lists);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:50']);
        try {
            $user = $request->user('sanctum') ?? $request->user();
            $userId = $user ? $user->id : ($request->input('user_id') ?? Auth::id());

            if (!$userId) {
                $firstUser = \App\Models\User::first();
                $userId = $firstUser ? $firstUser->id : 1;
            }

            $count = WishlistList::where('user_id', $userId)->count();
            if ($count >= 20) {
                return response()->json(['message' => 'Maximum limit of 20 favourite lists reached.'], 422);
            }

            $list = WishlistList::create([
                'user_id' => $userId,
                'name' => trim($request->name)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Favourite list created successfully.',
                'list' => $list
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
