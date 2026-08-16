<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationPreferenceController extends Controller
{
    /**
     * Get user notification preferences.
     */
    public function getPreferences(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : ($request->input('user_id') ?? 'guest');
        $cacheKey = "user_notification_prefs_{$userId}";

        $defaultPrefs = [
            'booking_updates' => true,
            'property_messages' => true,
            'checkin_reminders' => true,
            'price_alerts' => false,
            'offers_inspiration' => false,
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
     * Update user notification preferences.
     */
    public function updatePreferences(Request $request)
    {
        $request->validate([
            'booking_updates' => 'nullable|boolean',
            'property_messages' => 'nullable|boolean',
            'checkin_reminders' => 'nullable|boolean',
            'price_alerts' => 'nullable|boolean',
            'offers_inspiration' => 'nullable|boolean',
        ]);

        $userId = $request->user() ? $request->user()->id : ($request->input('user_id') ?? 'guest');
        $cacheKey = "user_notification_prefs_{$userId}";

        $existing = Cache::get($cacheKey, [
            'booking_updates' => true,
            'property_messages' => true,
            'checkin_reminders' => true,
            'price_alerts' => false,
            'offers_inspiration' => false,
        ]);

        $updated = [
            'booking_updates' => $request->has('booking_updates') ? filter_var($request->input('booking_updates'), FILTER_VALIDATE_BOOLEAN) : $existing['booking_updates'],
            'property_messages' => $request->has('property_messages') ? filter_var($request->input('property_messages'), FILTER_VALIDATE_BOOLEAN) : $existing['property_messages'],
            'checkin_reminders' => $request->has('checkin_reminders') ? filter_var($request->input('checkin_reminders'), FILTER_VALIDATE_BOOLEAN) : $existing['checkin_reminders'],
            'price_alerts' => $request->has('price_alerts') ? filter_var($request->input('price_alerts'), FILTER_VALIDATE_BOOLEAN) : $existing['price_alerts'],
            'offers_inspiration' => $request->has('offers_inspiration') ? filter_var($request->input('offers_inspiration'), FILTER_VALIDATE_BOOLEAN) : $existing['offers_inspiration'],
        ];

        Cache::put($cacheKey, $updated, now()->addDays(365));

        Log::info("Notification preferences updated for user {$userId}:", $updated);

        return response()->json([
            'status' => 'success',
            'message' => 'Notification preferences updated successfully.',
            'preferences' => $updated,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
