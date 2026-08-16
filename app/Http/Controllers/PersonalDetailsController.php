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
        $userId = $request->user() ? $request->user()->id : ($request->input('user_id') ?? 'guest');
        $cacheKey = "user_personal_details_{$userId}";

        $defaultDetails = [
            'full_name' => 'Maya Thompson',
            'email' => 'maya.thompson@email.com',
            'phone_number' => '+1 415 555 0184',
            'date_of_birth' => '12 May 1994',
            'identity_document' => 'Not added',
            'avatar_url' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-4.0.3&auto=format&fit=crop&w=200&q=80',
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
            'full_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|string|max:50',
            'identity_document' => 'nullable|string|max:100',
        ]);

        $userId = $request->user() ? $request->user()->id : ($request->input('user_id') ?? 'guest');
        $cacheKey = "user_personal_details_{$userId}";

        $existing = Cache::get($cacheKey, [
            'full_name' => 'Maya Thompson',
            'email' => 'maya.thompson@email.com',
            'phone_number' => '+1 415 555 0184',
            'date_of_birth' => '12 May 1994',
            'identity_document' => 'Not added',
            'avatar_url' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-4.0.3&auto=format&fit=crop&w=200&q=80',
        ]);

        $updated = [
            'full_name' => $request->input('full_name', $existing['full_name']),
            'email' => $request->input('email', $existing['email']),
            'phone_number' => $request->input('phone_number', $existing['phone_number']),
            'date_of_birth' => $request->input('date_of_birth', $existing['date_of_birth']),
            'identity_document' => $request->input('identity_document', $existing['identity_document']),
            'avatar_url' => $existing['avatar_url'],
        ];

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
