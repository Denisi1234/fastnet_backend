<?php

namespace App\Http\Controllers;

use App\Jobs\InvalidatePropertyCache;
use App\Jobs\SendBookingConfirmationEmail;
use App\Jobs\SendBookingConfirmationSms;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use App\Services\BookingCalculationService;
use App\Services\BookingCreationService;
use App\Services\BookingRevalidationService;
use App\Services\ReceiptGenerationService;
use App\Services\RoomAvailabilityService;

class BookingController extends Controller
{
    protected BookingCalculationService $calculator;

    public function __construct(BookingCalculationService $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * Authoritative calculation breakdown for single or multiple rooms.
     */
    public function calculate(Request $request)
    {
        $request->validate([
            'property_id' => 'required|exists:properties,id',
            'check_in'    => 'required|date',
            'check_out'   => 'required|date|after:check_in',
            'guests'      => 'nullable|integer|min:1',
            'rooms'       => 'nullable|array',
            'rooms.*.room_id'  => 'required_with:rooms|exists:rooms,id',
            'rooms.*.quantity' => 'nullable|integer|min:1',
            'room_id'     => 'nullable|exists:rooms,id',
            'quantity'    => 'nullable|integer|min:1',
            'promo_code'  => 'nullable|string',
        ]);

        $propertyId = (int) $request->property_id;
        $checkIn = $request->check_in;
        $checkOut = $request->check_out;
        $guests = (int) ($request->input('guests', 1));

        // Format room selections
        $roomSelections = [];
        if ($request->has('rooms') && is_array($request->rooms)) {
            $roomSelections = $request->rooms;
        } elseif ($request->has('room_id')) {
            $roomSelections[] = [
                'room_id'  => (int) $request->room_id,
                'quantity' => (int) ($request->input('quantity', $request->input('rooms_count', 1))),
            ];
        } else {
            $property = \App\Models\Property::with('rooms')->find($propertyId);
            $firstRoom = $property->rooms->first();
            if ($firstRoom) {
                $roomSelections[] = [
                    'room_id'  => $firstRoom->id,
                    'quantity' => 1,
                ];
            }
        }

        try {
            $result = $this->calculator->calculate(
                $propertyId,
                $checkIn,
                $checkOut,
                $roomSelections,
                $guests,
                $request->input('promo_code')
            );
            return response()->json($result, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'valid' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function revalidate(Request $request, BookingRevalidationService $revalidationService)
    {
        $request->validate([
            'property_id' => 'required|exists:properties,id',
            'room_id'     => 'nullable|exists:rooms,id',
            'check_in'    => 'required|date',
            'check_out'   => 'required|date|after:check_in',
            'guests'      => 'nullable|integer|min:1',
            'rooms'       => 'nullable|integer|min:1',
        ]);

        $res = $revalidationService->revalidate(
            (int)$request->property_id,
            $request->room_id ? (int)$request->room_id : null,
            $request->check_in,
            $request->check_out,
            (int)$request->input('guests', 2),
            (int)$request->input('rooms', 1)
        );

        return response()->json($res['data'], $res['status']);
    }

    public function store(Request $request, BookingCreationService $creationService)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'guest_name' => 'nullable|string|min:2',
            'guest_email' => 'nullable|email',
            'guest_phone' => 'nullable|string|min:8',
        ]);

        $roomId = $request->room_id;
        $guestUser = $request->user();

        // If guest is not logged in, find or create customer record using the provided booking contact details
        if (!$guestUser && $request->filled('guest_email')) {
            $guestEmail = trim($request->input('guest_email'));
            $guestName = trim($request->input('guest_name') ?? 'FastNet Guest');
            $guestPhone = trim($request->input('guest_phone') ?? '');

            $guestUser = \App\Models\User::firstOrCreate(
                ['email' => $guestEmail],
                [
                    'name' => $guestName,
                    'phone_number' => $guestPhone,
                    'role' => 'customer',
                    'password' => bcrypt(\Illuminate\Support\Str::random(16)),
                ]
            );

            // Update phone or name if missing
            if ($guestPhone && empty($guestUser->phone_number)) {
                $guestUser->update(['phone_number' => $guestPhone]);
            }
            if ($guestName && ($guestUser->name === 'FastNet Guest' || empty($guestUser->name))) {
                $guestUser->update(['name' => $guestName]);
            }
        }

        $guestId = $guestUser ? $guestUser->id : 1;
        $checkIn = $request->check_in;
        $checkOut = $request->check_out;

        $quantity = max(1, (int)($request->input('quantity') ?? $request->input('rooms') ?? 1));
        $guests = max(1, (int)($request->input('guests') ?? $request->input('adults') ?? 2));

        $res = $creationService->createBooking(
            (int)$roomId,
            (int)$guestId,
            $checkIn,
            $checkOut,
            $quantity,
            $guests,
            $request->input('promo_code')
        );

        if (!$res['success']) {
            return response()->json(['message' => $res['message']], $res['status']);
        }

        return response()->json($res['data'], 201);
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
            // Clear any expired locks first
            RoomLock::where('room_id', $roomId)
                ->where('expires_at', '<=', now())
                ->delete();

            // Authoritative per-night room inventory check
            /** @var RoomAvailabilityService $availabilityService */
            $availabilityService = app(RoomAvailabilityService::class);
            $availability = $availabilityService->checkRoomAvailability(
                $roomId,
                $checkIn,
                $checkOut,
                1,
                $guestId // exclude existing locks by this guest
            );

            if (!$availability['is_available']) {
                return response()->json([
                    'message' => $availability['unavailability_reason'] ?? 'Room is not available for the selected dates.'
                ], 409);
            }

            // Create or update user's hold lock (10 minutes duration)
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

    /**
     * Generate, GZip-compress (compressing ~80KB down to ~4KB), and upload booking e-receipt PDF to Supabase Storage 'receipts' bucket.
     * Shared endpoint called by both Mobile app and Web desktop/mobile frontends.
     */
    public function generateReceipt(Request $request, ReceiptGenerationService $receiptService)
    {
        $request->validate([
            'booking_code' => 'required|string',
            'guest_name' => 'nullable|string',
            'property_name' => 'nullable|string',
            'property_address' => 'nullable|string',
            'check_in' => 'nullable|string',
            'check_out' => 'nullable|string',
            'total_price' => 'nullable',
            'pdf_base64' => 'nullable|string',
        ]);

        $result = $receiptService->generate($request->all());
        return response()->json($result);
    }
}
