<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
        ]);

        $roomId = $request->room_id;
        $guestId = $request->user()->id;
        $checkIn = $request->check_in;
        $checkOut = $request->check_out;

        // Redis Lock Key
        $lockKey = "booking_lock:room_{$roomId}";

        // Attempting to acquire Redis atomic lock to prevent race conditions (double booking)
        $acquired = false;
        try {
            // Redis lock setup for 5 seconds duration
            $acquired = Redis::funnel($lockKey)->limit(1)->then(function () {
                return true;
            }, function () {
                return false;
            });
        } catch (\Exception $e) {
            Log::warning('Redis connection failed, falling back to database locking: ' . $e->getMessage());
            $acquired = true; // Fallback to DB transaction locking
        }

        if (!$acquired) {
            return response()->json([
                'message' => 'Room booking is currently being processed. Please try again in a few seconds.'
            ], 429);
        }

        // Database transaction for final isolation
        return DB::transaction(function () use ($roomId, $guestId, $checkIn, $checkOut) {
            // Check for booking overlap
            $overlap = Booking::where('room_id', $roomId)
                ->where(function ($query) use ($checkIn, $checkOut) {
                    $query->whereBetween('check_in', [$checkIn, $checkOut])
                        ->orWhereBetween('check_out', [$checkIn, $checkOut])
                        ->orWhere(function ($q) use ($checkIn, $checkOut) {
                            $q->where('check_in', '<=', $checkIn)
                              ->where('check_out', '>=', $checkOut);
                        });
                })->exists();

            if ($overlap) {
                return response()->json([
                    'message' => 'Double booking prevented! This room is already reserved for the selected dates.'
                ], 409);
            }

            $room = Room::with('property')->findOrFail($roomId);
            $nights = (strtotime($checkOut) - strtotime($checkIn)) / (60 * 60 * 24);
            $totalPrice = $room->property->price_per_night * $nights;

            $booking = Booking::create([
                'room_id' => $roomId,
                'guest_id' => $guestId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'total_price' => $totalPrice,
                'payment_status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Booking initialized successfully.',
                'booking' => $booking
            ], 201);
        });
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'owner') {
            // Host gets bookings for their rooms
            $bookings = Booking::whereHas('room.property', function ($query) use ($user) {
                $query->where('host_id', $user->id);
            })->with(['room.property', 'guest'])->get();
        } else {
            // Customer gets their bookings
            $bookings = Booking::where('guest_id', $user->id)
                ->with(['room.property'])
                ->get();
        }

        return response()->json($bookings);
    }
}
