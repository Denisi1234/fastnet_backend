<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    /**
     * Help centre data.
     *
     * The "upcoming stay" card used to be a hardcoded fixture - reference
     * BS-90821, "Harbor View Hotel", Lisbon - shown to every visitor as their
     * own next booking. It is now read from the authenticated user's real
     * upcoming bookings, and is simply null when they have none.
     *
     * The topic links are navigation, not user data, so they stay as literals.
     * Support contact details come from config rather than being hardcoded.
     */
    public function getHelpCentreData(Request $request)
    {
        $user = $request->user('sanctum') ?? $request->user();

        $upcomingStay = null;

        if ($user) {
            $booking = Booking::with(['room.property'])
                ->where('guest_id', $user->id)
                ->whereIn('status', ['Pending', 'Pending Verification', 'Confirmed', 'Checked In'])
                ->whereDate('check_out', '>=', now()->toDateString())
                ->orderBy('check_in')
                ->first();

            if ($booking) {
                $property = $booking->room?->property;

                $upcomingStay = [
                    'id' => $booking->booking_code ?: ('BK-' . $booking->id),
                    'property_name' => $property?->name,
                    'location' => $property?->city,
                    'room' => $booking->room?->title ?: $booking->room?->room_number,
                    'check_in' => $booking->check_in?->toDateString(),
                    'check_out' => $booking->check_out?->toDateString(),
                    'status' => $booking->status,
                    'total_price' => (float) $booking->total_price,
                    'currency' => 'TZS',
                    'image' => $property?->primary_image_url ?: $property?->image_url,
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'upcoming_stay' => $upcomingStay,
            'popular_topics' => [
                [
                    'id' => 'manage_booking',
                    'title' => 'Manage my booking',
                    'icon' => 'calendar',
                    'action_url' => '/my-booking',
                ],
                [
                    'id' => 'contact_property',
                    'title' => 'Contact the property',
                    'icon' => 'phone',
                    'action_url' => '/my-booking',
                ],
                [
                    'id' => 'payments_refunds',
                    'title' => 'Payments and refunds',
                    'icon' => 'card',
                    'action_url' => '/payment-detail',
                ],
                [
                    'id' => 'checkin_checkout',
                    'title' => 'Check-in and check-out',
                    'icon' => 'luggage',
                    'action_url' => '/help-center',
                ],
            ],
            'support_contact' => [
                'phone' => config('support.phone'),
                'email' => config('support.email'),
                'available_247' => (bool) config('support.available_247', false),
            ],
        ]);
    }
}