<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Property;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    /**
     * Get all users.
     */
    public function users(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $users = User::orderBy('created_at', 'desc')->get();
        return response()->json($users);
    }

    /**
     * Update user status.
     */
    public function updateUserStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Active,Suspended,Pending Verification',
        ]);

        $user = User::findOrFail($id);
        $user->update(['status' => $request->status]);

        return response()->json($user);
    }

    /**
     * Add a user.
     */
    public function addUser(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone_number' => 'nullable|string',
            'role' => 'required|string|in:customer,owner,admin',
            'status' => 'nullable|string|in:Active,Suspended,Pending Verification',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone_number' => $request->phone_number,
            'role' => $request->role,
            'status' => $request->status ?? 'Active',
        ]);

        return response()->json($user, 201);
    }

    /**
     * Get all properties (listings).
     */
    public function properties(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $properties = Property::with(['host', 'rooms'])->orderBy('created_at', 'desc')->get();
        return response()->json($properties);
    }

    /**
     * Update property status.
     */
    public function updatePropertyStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Active,Pending,Removed',
        ]);

        $property = Property::findOrFail($id);
        $property->update(['status' => $request->status]);

        return response()->json($property);
    }

    /**
     * Get all bookings (admin view) with optional status filter.
     */
    public function bookings(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $query = Booking::with(['guest', 'room.property']);

        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        $bookings = $query->orderBy('created_at', 'desc')->get()->map(function ($booking) {
            $nights = 0;
            if ($booking->check_in && $booking->check_out) {
                $nights = $booking->check_in->diffInDays($booking->check_out);
            }

            return [
                'id'            => $booking->id,
                'user_name'     => $booking->guest->name ?? 'Unknown',
                'property_name' => optional($booking->room->property)->name ?? 'Unknown',
                'room_number'   => $booking->room->room_number ?? $booking->room_id,
                'check_in'      => $booking->check_in,
                'check_out'     => $booking->check_out,
                'nights'        => $nights,
                'total_price'   => $booking->total_price,
                'status'        => $booking->status ?? $booking->payment_status,
            ];
        });

        return response()->json($bookings);
    }

    /**
     * Update a booking's status (admin only).
     */
    public function updateBookingStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Pending,Confirmed,Checked In,Completed,Cancelled',
        ]);

        $booking = Booking::findOrFail($id);
        $booking->update(['status' => $request->status]);

        return response()->json($booking);
    }

    /**
     * Get all payments (admin view).
     */
    public function payments(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $payments = Payment::with(['booking.guest', 'booking.room.property'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($payment) {
                return [
                    'id'            => $payment->id,
                    'booking_id'    => $payment->booking_id,
                    'guest_name'    => optional($payment->booking->guest)->name ?? 'Unknown',
                    'property_name' => optional($payment->booking->room->property)->name ?? 'Unknown',
                    'amount'        => $payment->amount,
                    'gateway'       => $payment->gateway,
                    'status'        => $payment->status,
                    'created_at'    => $payment->created_at,
                ];
            });

        return response()->json($payments);
    }
}
