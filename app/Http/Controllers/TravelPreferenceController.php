<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TravelPreferenceController extends Controller
{
    /**
     * Get user travel preferences.
     */
    public function getPreferences(Request $request)
    {
        // Per-person: the 'guest' bucket was shared by every anonymous
        // visitor, leaking one person's travel preferences to the next.
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }
        $userId = $user->id;
        $cacheKey = "user_travel_prefs_{$userId}";

        $defaultPrefs = [
            'bed_preference' => 'No preference',
            'room_type' => 'No preference',
            'smoking_preference' => 'Non-smoking',
            'step_free_access' => 'No preference',
            'accessible_bathroom' => 'No preference',
            'dietary_requirements' => 'None',
        ];

        $preferences = Cache::get($cacheKey, $defaultPrefs);

        return response()->json([
            'status' => 'success',
            'user_id' => $userId,
            'preferences' => $preferences,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Update user travel preferences.
     */
    public function updatePreferences(Request $request)
    {
        $request->validate([
            'bed_preference' => 'nullable|string',
            'room_type' => 'nullable|string',
            'smoking_preference' => 'nullable|string',
            'step_free_access' => 'nullable|string',
            'accessible_bathroom' => 'nullable|string',
            'dietary_requirements' => 'nullable|string',
        ]);

        // Per-person: the 'guest' bucket was shared by every anonymous
        // visitor, leaking one person's travel preferences to the next.
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }
        $userId = $user->id;
        $cacheKey = "user_travel_prefs_{$userId}";

        $existing = Cache::get($cacheKey, [
            'bed_preference' => 'No preference',
            'room_type' => 'No preference',
            'smoking_preference' => 'Non-smoking',
            'step_free_access' => 'No preference',
            'accessible_bathroom' => 'No preference',
            'dietary_requirements' => 'None',
        ]);

        $updated = [
            'bed_preference' => $request->input('bed_preference', $existing['bed_preference']),
            'room_type' => $request->input('room_type', $existing['room_type']),
            'smoking_preference' => $request->input('smoking_preference', $existing['smoking_preference']),
            'step_free_access' => $request->input('step_free_access', $existing['step_free_access']),
            'accessible_bathroom' => $request->input('accessible_bathroom', $existing['accessible_bathroom']),
            'dietary_requirements' => $request->input('dietary_requirements', $existing['dietary_requirements']),
        ];

        Cache::put($cacheKey, $updated, now()->addDays(365));

        Log::info("Travel preferences updated for user {$userId}:", $updated);

        return response()->json([
            'status' => 'success',
            'message' => 'Travel preferences updated successfully.',
            'preferences' => $updated,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
