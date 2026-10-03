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

            // An unauthenticated read used to skip the where clause entirely,
            // returning every user's lists. Require a session.
            if (! $user) {
                return response()->json([]);
            }

            $lists = WishlistList::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Return an empty collection rather than inventing starter lists.
            // The previous placeholder rows carried string ids ("next_stay")
            // against a numeric primary key and made the UI report "2/20 lists"
            // for a brand-new account.
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

            // Previously fell back to User::first() when nobody was
            // authenticated, creating lists on an arbitrary real account.
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Sign in to create a favourites list.',
                ], 401);
            }

            $userId = $user->id;

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
