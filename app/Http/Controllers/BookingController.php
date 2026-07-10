<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

            // Clear temporary locks held by this user for this room
            RoomLock::where('room_id', $roomId)->where('guest_id', $guestId)->delete();

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

    public function lockRoom(Request $request)
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

        return DB::transaction(function () use ($roomId, $guestId, $checkIn, $checkOut) {
            // 1. Check for overlapping permanent bookings
            $overlapBooking = Booking::where('room_id', $roomId)
                ->where(function ($query) use ($checkIn, $checkOut) {
                    $query->whereBetween('check_in', [$checkIn, $checkOut])
                        ->orWhereBetween('check_out', [$checkIn, $checkOut])
                        ->orWhere(function ($q) use ($checkIn, $checkOut) {
                            $q->where('check_in', '<=', $checkIn)
                              ->where('check_out', '>=', $checkOut);
                        });
                })->exists();

            if ($overlapBooking) {
                return response()->json([
                    'message' => 'This room is already reserved for the selected dates.'
                ], 409);
            }

            // 2. Check for overlapping temporary locks by other users
            $overlapLock = RoomLock::where('room_id', $roomId)
                ->where('guest_id', '!=', $guestId)
                ->where('expires_at', '>', now())
                ->where(function ($query) use ($checkIn, $checkOut) {
                    $query->whereBetween('check_in', [$checkIn, $checkOut])
                        ->orWhereBetween('check_out', [$checkIn, $checkOut])
                        ->orWhere(function ($q) use ($checkIn, $checkOut) {
                            $q->where('check_in', '<=', $checkIn)
                              ->where('check_out', '>=', $checkOut);
                        });
                })->exists();

            if ($overlapLock) {
                return response()->json([
                    'message' => 'Room is temporarily held by another customer. Please wait or select a different room.'
                ], 409);
            }

            // 3. Clear any expired/stray locks for this room
            RoomLock::where('room_id', $roomId)
                ->where('expires_at', '<=', now())
                ->delete();

            // 4. Create or update user's hold lock (10 minutes duration)
            $lock = RoomLock::updateOrCreate(
                ['room_id' => $roomId, 'guest_id' => $guestId, 'check_in' => $checkIn, 'check_out' => $checkOut],
                ['expires_at' => now()->addMinutes(10)]
            );

            return response()->json([
                'message' => 'Room temporarily locked for 10 minutes.',
                'lock' => $lock
            ]);
        });
    }

    public function unlockRoom(Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
        ]);

        $roomId = $request->room_id;
        $guestId = $request->user()->id;

        // Delete active locks held by this user for the room
        RoomLock::where('room_id', $roomId)
            ->where('guest_id', $guestId)
            ->delete();

        return response()->json([
            'message' => 'Room lock released successfully.'
        ]);
    }

    public function cancel($id)
    {
        $user = Auth::user();

        // Allow the booking owner or an admin to cancel
        $query = Booking::where('id', $id);
        if ($user->role !== 'admin') {
            $query->where('guest_id', $user->id);
        }

        $booking = $query->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if (in_array($booking->status, ['Cancelled', 'Completed'])) {
            return response()->json([
                'message' => 'Booking cannot be cancelled as it is already ' . $booking->status . '.'
            ], 422);
        }

        $booking->update(['status' => 'Cancelled']);

        return response()->json($booking);
    }
}
