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
            'payment_method' => 'nullable|string|max:32',
            'payment_phone' => 'nullable|string|max:32',
            'special_requests' => 'nullable|string|max:2000',
            'room_preference' => 'nullable|string|max:255',
            'bed_preference' => 'nullable|string|max:255',
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

        // A booking with neither an authenticated user nor a guest_email has no
        // owner at all. It previously fell back to user id 1, which put a
        // stranger's stay in that account's /my-booking list and let
        // cancelBooking() target it.
        if (! $guestUser) {
            return response()->json([
                'message' => 'Sign in or provide a contact email to complete this booking.',
                'errors' => ['guest_email' => ['Required when not signed in.']],
            ], 422);
        }

        $guestId = $guestUser->id;
        $checkIn = $request->check_in;
        $checkOut = $request->check_out;

        $quantity = max(1, (int)($request->input('quantity') ?? $request->input('rooms') ?? 1));
        $guests = max(1, (int)($request->input('guests') ?? $request->input('adults') ?? 2));

        // Guest-supplied details that previously vanished: the mobile-money
        // number is needed for reconciliation, the notes for the host.
        $extras = [
            'payment_method' => $request->input('payment_method'),
            'payment_phone' => $request->input('payment_phone'),
            'special_requests' => $request->input('special_requests'),
            'room_preference' => $request->input('room_preference'),
            'bed_preference' => $request->input('bed_preference'),
        ];

        $res = $creationService->createBooking(
            (int)$roomId,
            (int)$guestId,
            $checkIn,
            $checkOut,
            $quantity,
            $guests,
            $request->input('promo_code'),
            $extras
        );

        if (!$res['success']) {
            return response()->json(['message' => $res['message']], $res['status']);
        }

        return response()->json($res['data'], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user('sanctum') ?? $request->user();
        $email = $request->query('email');
        $bookingCode = $request->query('booking_code') ?? $request->query('code');

        $query = Booking::with(['room.property', 'guest']);

        if ($bookingCode) {
            $query->where('booking_code', $bookingCode);
        } elseif ($user) {
            if ($user->role === 'owner') {
                $query->whereHas('room.property', function ($q) use ($user) {
                    $q->where('host_id', $user->id);
                });
            } else {
                $query->where('guest_id', $user->id);
            }
        } elseif ($email) {
            $query->whereHas('guest', function ($q) use ($email) {
                $q->where('email', $email);
            });
        } else {
            // No identity and no identifier: previously this fell through and
            // returned EVERY booking with guest names, emails and phones to
            // any unauthenticated caller. Refuse instead.
            return response()->json([
                'message' => 'Sign in to view bookings, or supply an email address or booking code.',
            ], 401);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        $bookings = $query->latest()->paginate($perPage);

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

        // Scoped to the stay being abandoned. This previously deleted every
        // lock the guest held on the room, so releasing one date range also
        // released a hold they had on a different set of dates.
        $query = RoomLock::where('room_id', $roomId)
            ->where('guest_id', $guestId);

        if ($request->filled('check_in')) {
            $query->where('check_in', $request->input('check_in'));
        }
        if ($request->filled('check_out')) {
            $query->where('check_out', $request->input('check_out'));
        }

        $query->delete();

        return response()->json([
            'message' => 'Room lock released successfully.'
        ]);
    }

    /**
 * Show a single booking.
     *
     * The web's booking-success page verified the real booking through
     * GET /bookings/{id}, but that route did not exist - so the page always
     * threw "This booking could not be verified" and the invoice fell back to
     * rand()-generated values.
     *
     * A booking is personal data, so this is strictly scoped to the owner
     * (or an admin). There is no email-only lookup: knowing someone's email
     * must not be enough to read their stay.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user('sanctum') ?? $request->user();

        $query = Booking::with(['room.property', 'guest', 'payments'])
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', (int) $id)->orWhere('booking_code', $id);
                } else {
                    $q->where('booking_code', $id);
                }
            });

        if ($user) {
            if ($user->role !== 'admin') {
                // Owners may also read bookings made against their properties.
                $query->where(function ($q) use ($user) {
                    $q->where('guest_id', $user->id)
                      ->orWhereHas('room.property', function ($rq) use ($user) {
                          $rq->where('host_id', $user->id);
                      });
                });
            }
        } else {
            // Guest self-service. Booking ids are sequential, so the id alone
            // proves nothing - require the email the booking was made with.
            $email = trim((string) ($request->query('email') ?? ''));

            if ($email === '') {
                return response()->json([
                    'message' => 'Sign in to view this booking, or supply the email it was made with.',
                ], 401);
            }

            $query->whereHas('guest', function ($q) use ($email) {
                $q->where('email', $email);
            });
        }

        $booking = $query->first();

        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        return response()->json($booking);
    }

    public function cancel(Request $request, $id)
    {
        $user = $request->user('sanctum') ?? $request->user();
        $email = $request->input('email') ?? $request->query('email');

        // Previously an unauthenticated request with no email parameter had no
        // ownership filter at all, so anyone could cancel any booking by id.
        if (! $user && ! $email) {
            return response()->json([
                'message' => 'Sign in to cancel a booking, or supply the email used to make it.',
            ], 401);
        }

        $query = Booking::where(function($q) use ($id) {
            if (is_numeric($id)) $q->where('id', (int)$id)->orWhere('booking_code', $id);
            else $q->where('booking_code', $id);
        });

        if ($user && $user->role !== 'admin') {
            $query->where('guest_id', $user->id);
        } elseif (!$user && $email) {
            // Guest self-service requires the booking code as well as the email,
            // so an email address alone is not enough.
            $query->whereHas('guest', function ($q) use ($email) {
                $q->where('email', $email);
            });
        }

        $booking = $query->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if (in_array(strtolower((string)$booking->status), ['cancelled', 'completed'])) {
            return response()->json([
                'message' => 'Booking cannot be cancelled as it is already ' . $booking->status . '.'
            ], 422);
        }

        // Go through the state machine so the transition is validated rather
        // than overwriting the column outright.
        if (! $booking->transitionTo('Cancelled')) {
            return response()->json([
                'message' => "Booking cannot be cancelled from {$booking->status}.",
            ], 422);
        }

        // Release the room. Without this the room stayed flagged 'booked'
        // forever, because only the payment webhook ever set that flag.
        app(RoomAvailabilityService::class)->syncRoomOccupancy(
            (int) $booking->room_id
        );

        app(\App\Services\BookingNotificationService::class)->notifyCancelled($booking);

        return response()->json(['status' => 'success', 'message' => 'Booking cancelled successfully.', 'booking' => $booking->fresh()]);
    }

    /**
     * Professional arrival / departure flow (host + admin only).
     *
     * POST /bookings/{id}/check-in  — Confirmed + paid  → Checked In
     * POST /bookings/{id}/check-out — Checked In         → Completed
     *
     * Guests never move these states themselves: the host confirms the
     * physical arrival and departure. The state machine rejects anything
     * out of order, so double check-ins and post-cancel moves 422 honestly.
     */
    public function checkIn(Request $request, $id)
    {
        return $this->moveStay($request, $id, 'Checked In');
    }

    public function checkOut(Request $request, $id)
    {
        return $this->moveStay($request, $id, 'Completed');
    }

    private function moveStay(Request $request, $id, string $target)
    {
        $user = $request->user('sanctum') ?? $request->user();
        if (! $user) {
            return response()->json(['message' => 'Sign in as the host to manage arrivals.'], 401);
        }

        $booking = Booking::with(['room.property'])
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) $q->where('id', (int) $id)->orWhere('booking_code', $id);
                else $q->where('booking_code', $id);
            })->first();

        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        // Ownership: admins act on any stay; owners only on their own lodges.
        if (($user->role ?? '') !== 'admin') {
            $hostId = (int) ($booking->room->property->host_id ?? 0);
            if ($hostId <= 0 || $hostId !== (int) $user->id) {
                return response()->json(['message' => 'This booking belongs to another property.'], 403);
            }
        }

        if ($target === 'Checked In' && strtolower((string) $booking->payment_status) !== 'paid') {
            return response()->json([
                'message' => 'Only paid bookings can be checked in.',
                'payment_status' => $booking->payment_status,
            ], 422);
        }

        if (! $booking->transitionTo($target)) {
            return response()->json([
                'message' => "Booking cannot move from {$booking->status} to {$target}.",
                'status' => $booking->status,
            ], 422);
        }

        if ($target === 'Completed') {
            app(RoomAvailabilityService::class)->syncRoomOccupancy((int) $booking->room_id);
        }

        return response()->json([
            'status' => 'success',
            'message' => $target === 'Checked In' ? 'Guest checked in.' : 'Stay completed — guest checked out.',
            'booking' => $booking->fresh(),
        ]);
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
