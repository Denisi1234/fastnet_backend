<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SupportController extends Controller
{
    /**
     * Get help centre data including upcoming stays and popular topics.
     */
    public function getHelpCentreData(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'upcoming_stay' => [
                'id' => 'BS-90821',
                'hotel_name' => 'Harbor View Hotel',
                'location' => 'Lisbon',
                'dates' => '14–17 Sep',
                'image' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?ixlib=rb-4.0.3&auto=format&fit=crop&w=300&q=80',
            ],
            'popular_topics' => [
                [
                    'id' => 'manage_booking',
                    'title' => 'Manage my booking',
                    'icon' => 'calendar',
                    'action_url' => '/bookings/',
                ],
                [
                    'id' => 'contact_property',
                    'title' => 'Contact the property',
                    'icon' => 'phone',
                    'action_url' => '/message.html',
                ],
                [
                    'id' => 'payments_refunds',
                    'title' => 'Payments and refunds',
                    'icon' => 'card',
                    'action_url' => '/support/',
                ],
                [
                    'id' => 'checkin_checkout',
                    'title' => 'Check-in and check-out',
                    'icon' => 'luggage',
                    'action_url' => '/support/',
                ],
            ],
            'support_contact' => [
                'phone' => '+255 700 000 000',
                'email' => 'support@fastnetstays.com',
                'available_247' => true,
            ],
        ]);
    }
}
