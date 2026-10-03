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
        // Preferences are per-person. The bucket used to fall back to the
        // literal 'guest', so every anonymous visitor shared one record and
        // saw each other's choices.
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }
        $userId = $user->id;
        $cacheKey = "user_email_preferences_{$userId}";

        $defaultPrefs = [
            'feedback_research' => false,
            'price_alerts' => false,
            'travel_tips_deals' => false,
            'unsubscribe_all' => true,
            'booking_updates' => true,
            'account_legal_notices' => true,
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
     * Update user notification / email preferences.
     */
    public function updatePreferences(Request $request)
    {
        $request->validate([
            'feedback_research' => 'nullable|boolean',
            'price_alerts' => 'nullable|boolean',
            'travel_tips_deals' => 'nullable|boolean',
            'unsubscribe_all' => 'nullable|boolean',
            'booking_updates' => 'nullable|boolean',
            'account_legal_notices' => 'nullable|boolean',
        ]);

        // Preferences are per-person. The bucket used to fall back to the
        // literal 'guest', so every anonymous visitor shared one record and
        // saw each other's choices.
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }
        $userId = $user->id;
        $cacheKey = "user_email_preferences_{$userId}";

        $existing = Cache::get($cacheKey, [
            'feedback_research' => false,
            'price_alerts' => false,
            'travel_tips_deals' => false,
            'unsubscribe_all' => true,
            'booking_updates' => true,
            'account_legal_notices' => true,
        ]);

        $feedback = $request->has('feedback_research') ? filter_var($request->input('feedback_research'), FILTER_VALIDATE_BOOLEAN) : $existing['feedback_research'];
        $price = $request->has('price_alerts') ? filter_var($request->input('price_alerts'), FILTER_VALIDATE_BOOLEAN) : $existing['price_alerts'];
        $travel = $request->has('travel_tips_deals') ? filter_var($request->input('travel_tips_deals'), FILTER_VALIDATE_BOOLEAN) : $existing['travel_tips_deals'];
        
        $unsub = $request->has('unsubscribe_all') 
            ? filter_var($request->input('unsubscribe_all'), FILTER_VALIDATE_BOOLEAN) 
            : (!$feedback && !$price && !$travel);

        if ($unsub && $request->has('unsubscribe_all') && filter_var($request->input('unsubscribe_all'), FILTER_VALIDATE_BOOLEAN)) {
            $feedback = false;
            $price = false;
            $travel = false;
        }

        // These two were hardcoded to true, which silently discarded whatever
        // the user had chosen on every save.
        $bookingUpdates = $request->has('booking_updates')
            ? filter_var($request->input('booking_updates'), FILTER_VALIDATE_BOOLEAN)
            : (bool)($existing['booking_updates'] ?? true);
        $legalNotices = $request->has('account_legal_notices')
            ? filter_var($request->input('account_legal_notices'), FILTER_VALIDATE_BOOLEAN)
            : (bool)($existing['account_legal_notices'] ?? true);

        $updated = [
            'feedback_research' => $feedback,
            'price_alerts' => $price,
            'travel_tips_deals' => $travel,
            'unsubscribe_all' => $unsub,
            'booking_updates' => $bookingUpdates,
            'account_legal_notices' => $legalNotices,
        ];

        Cache::put($cacheKey, $updated, now()->addDays(365));

        Log::info("Email preferences updated for user {$userId}:", $updated);

        return response()->json([
            'status' => 'success',
            'message' => 'Email preferences updated successfully.',
            'preferences' => $updated,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
