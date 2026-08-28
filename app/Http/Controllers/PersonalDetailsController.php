<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PersonalDetailsController extends Controller
{
    /**
     * Get user personal details.
     */
    public function getDetails(Request $request)
    {
        $user = $request->user();
        $userId = $user ? $user->id : ($request->input('user_id') ?? 'guest');
        $cacheKey = "user_personal_details_{$userId}";

        $defaultDetails = [
            'first_name' => $user ? ($user->first_name ?? explode(' ', $user->name)[0]) : 'Deni_s',
            'last_name' => $user ? ($user->last_name ?? (explode(' ', $user->name)[1] ?? 'Mahenge')) : 'Mahenge',
            'full_name' => $user ? $user->name : 'Deni_s Mahenge',
            'email' => $user ? $user->email : 'dm328432@gmail.com',
            'phone_number' => $user ? ($user->phone_number ?? '+255 712 345 678') : '+255 712 345 678',
            'is_verified' => true,
            'avatar_url' => 'https://images.unsplash.com/photo-1531427186611-ecfd6d936c79?auto=format&fit=crop&w=200&q=80',
        ];

        $details = Cache::get($cacheKey, $defaultDetails);

        return response()->json([
            'status' => 'success',
            'user_id' => $userId,
            'details' => $details,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Update user personal details.
     */
    public function updateDetails(Request $request)
    {
        $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'full_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $userId = $user ? $user->id : ($request->input('user_id') ?? 'guest');
        $cacheKey = "user_personal_details_{$userId}";

        $firstName = $request->input('first_name', 'Deni_s');
        $lastName = $request->input('last_name', 'Mahenge');
        $fullName = $request->input('full_name', "{$firstName} {$lastName}");
        $email = $request->input('email', 'dm328432@gmail.com');

        $updated = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'email' => $email,
            'phone_number' => $request->input('phone_number', '+255 712 345 678'),
            'is_verified' => true,
            'avatar_url' => 'https://images.unsplash.com/photo-1531427186611-ecfd6d936c79?auto=format&fit=crop&w=200&q=80',
        ];

        if ($user) {
            $user->name = $fullName;
            $user->email = $email;
            if (isset($user->first_name)) $user->first_name = $firstName;
            if (isset($user->last_name)) $user->last_name = $lastName;
            $user->save();
        }

        Cache::put($cacheKey, $updated, now()->addDays(365));

        Log::info("Personal details updated for user {$userId}:", $updated);

        return response()->json([
            'status' => 'success',
            'message' => 'Personal details updated successfully.',
            'details' => $updated,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
