<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OwnerController extends Controller
{
    /**
     * Auto-authenticate the default host user if not logged in.
     */
    private function getOwnerUser()
    {
        if (Auth::check()) {
            return Auth::user();
        }

        // If no user is logged in, find the seeded host and log them in
        $host = User::where('email', 'host@fastnet.com')->first();
        if ($host) {
            Auth::login($host);
            return $host;
        }

        // Fallback fallback guest / user if seeder hasn't run
        return null;
    }

    /**
     * Ensure database has realistic bookings to show off the dashboard features.
     */
    private function seedMockBookingsIfNeeded($ownerId)
    {
        $properties = Property::where('host_id', $ownerId)->get();
        if ($properties->isEmpty()) {
            return;
        }

        // Check if there are bookings. If so, don't seed.
        $hasBookings = Booking::whereIn('room_id', function($query) use ($ownerId) {
            $query->select('id')
                  ->from('rooms')
                  ->whereIn('property_id', function($q) use ($ownerId) {
                      $q->select('id')->from('properties')->where('host_id', $ownerId);
                  });
        })->exists();

        if ($hasBookings) {
            return;
        }

        // Create some guest users if none exist besides Alice
        $guests = [
            User::firstOrCreate(['email' => 'traveler@fastnet.com'], [
                'name' => 'Alice Traveler',
                'password' => bcrypt('password'),
                'role' => 'customer',
                'phone_number' => '+255 789 999 888',
            ]),
            User::firstOrCreate(['email' => 'mwajuma@fastnet.com'], [
                'name' => 'Mwajuma Kassim',
                'password' => bcrypt('password'),
                'role' => 'customer',
                'phone_number' => '+255 712 111 222',
            ]),
            User::firstOrCreate(['email' => 'peter@fastnet.com'], [
                'name' => 'Peter Kamau',
                'password' => bcrypt('password'),
                'role' => 'customer',
                'phone_number' => '+254 722 000 111',
            ]),
            User::firstOrCreate(['email' => 'sara@fastnet.com'], [
                'name' => 'Sara Bernstein',
                'password' => bcrypt('password'),
                'role' => 'customer',
                'phone_number' => '+1 555 019 2834',
            ]),
        ];

        // Seed 12 bookings across properties
        $bookingData = [
            // Past completed bookings
            ['check_in' => -10, 'duration' => 3, 'status' => 'paid', 'payment' => 'successful'],
            ['check_in' => -7, 'duration' => 2, 'status' => 'paid', 'payment' => 'successful'],
            ['check_in' => -5, 'duration' => 4, 'status' => 'paid', 'payment' => 'successful'],
            // Active stay (checking in today or currently checked in)
            ['check_in' => 0, 'duration' => 3, 'status' => 'paid', 'payment' => 'successful'],
            ['check_in' => -1, 'duration' => 5, 'status' => 'paid', 'payment' => 'successful'],
            // Upcoming bookings
            ['check_in' => 2, 'duration' => 2, 'status' => 'paid', 'payment' => 'successful'],
            ['check_in' => 5, 'duration' => 3, 'status' => 'pending', 'payment' => 'pending'],
            ['check_in' => 8, 'duration' => 7, 'status' => 'paid', 'payment' => 'successful'],
            // Cancelled booking
            ['check_in' => 4, 'duration' => 1, 'status' => 'refunded', 'payment' => 'refunded'],
        ];

        $rooms = Room::whereIn('property_id', $properties->pluck('id'))->get();
        if ($rooms->isEmpty()) {
            return;
        }

        $i = 0;
        foreach ($bookingData as $b) {
            $room = $rooms[$i % $rooms->count()];
            $guest = $guests[$i % count($guests)];
            $prop = $room->property;

            $checkInDate = Carbon::today()->addDays($b['check_in']);
            $checkOutDate = (clone $checkInDate)->addDays($b['duration']);
            $totalPrice = $prop->price_per_night * $b['duration'];

            $booking = Booking::create([
                'room_id' => $room->id,
                'guest_id' => $guest->id,
                'check_in' => $checkInDate,
                'check_out' => $checkOutDate,
                'total_price' => $totalPrice,
                'payment_status' => $b['status'],
                'payment_reference' => 'PAY-MOCK-' . rand(100000, 999999),
                'created_at' => Carbon::now()->subDays(15),
            ]);

            // Add payment transaction
            Payment::create([
                'booking_id' => $booking->id,
                'gateway' => ['mpesa', 'stripe', 'flutterwave'][rand(0, 2)],
                'transaction_id' => 'TXN-' . rand(1000000, 9999999),
                'amount' => $totalPrice,
                'status' => $b['payment'],
                'created_at' => Carbon::now()->subDays(15),
            ]);

            // Mark room status if booking is active
            if ($checkInDate <= Carbon::today() && $checkOutDate >= Carbon::today() && $b['status'] != 'refunded') {
                $room->update(['status' => 'booked']);
            }

            $i++;
        }
    }

    /**
     * Dashboard / Overview tab
     */
    public function dashboard(Request $request)
    {
        $owner = $this->getOwnerUser();
        if (!$owner) {
            return redirect('/login');
        }

        $this->seedMockBookingsIfNeeded($owner->id);

        $properties = Property::where('host_id', $owner->id)->get();
        $propertyIds = $properties->pluck('id');
        $rooms = Room::whereIn('property_id', $propertyIds)->get();
        $roomIds = $rooms->pluck('id');

        // Fetch bookings relating to owner properties
        $bookings = Booking::whereIn('room_id', $roomIds)
            ->with(['room.property', 'guest'])
            ->orderBy('check_in', 'desc')
            ->get();

        // Calculate metrics
        $totalRevenue = Booking::whereIn('room_id', $roomIds)
            ->where('payment_status', 'paid')
            ->sum('total_price');

        $activeBookingsCount = Booking::whereIn('room_id', $roomIds)
            ->where('check_in', '<=', Carbon::today())
            ->where('check_out', '>=', Carbon::today())
            ->where('payment_status', '!=', 'refunded')
            ->count();

        $upcomingCheckIns = Booking::whereIn('room_id', $roomIds)
            ->where('check_in', '>', Carbon::today())
            ->where('check_in', '<=', Carbon::today()->addDays(7))
            ->where('payment_status', '!=', 'refunded')
            ->count();

        $cancellationsCount = Booking::whereIn('room_id', $roomIds)
            ->where('payment_status', 'refunded')
            ->count();

        // Occupancy calculation
        $totalRoomsCount = $rooms->count();
        $occupiedRoomsCount = $rooms->where('status', 'booked')->count();
        $occupancyRate = $totalRoomsCount > 0 ? round(($occupiedRoomsCount / $totalRoomsCount) * 100) : 0;

        // Daily Check-ins/outs lists
        $checkInsToday = Booking::whereIn('room_id', $roomIds)
            ->whereDate('check_in', Carbon::today())
            ->where('payment_status', '!=', 'refunded')
            ->with(['guest', 'room.property'])
            ->get();

        $checkOutsToday = Booking::whereIn('room_id', $roomIds)
            ->whereDate('check_out', Carbon::today())
            ->where('payment_status', '!=', 'refunded')
            ->with(['guest', 'room.property'])
            ->get();

        return view('owner.dashboard', compact(
            'properties',
            'bookings',
            'totalRevenue',
            'activeBookingsCount',
            'upcomingCheckIns',
            'cancellationsCount',
            'occupancyRate',
            'checkInsToday',
            'checkOutsToday'
        ));
    }

    /**
     * Properties listing and creation Page
     */
    public function properties(Request $request)
    {
        $owner = $this->getOwnerUser();
        $properties = Property::where('host_id', $owner->id)->with('rooms')->get();
        return view('owner.properties', compact('properties'));
    }

    /**
     * Store new property
     */
    public function storeProperty(Request $request)
    {
        $owner = $this->getOwnerUser();
        
        $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'area' => 'required|string|max:255',
            'price_per_night' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'rooms_count' => 'required|integer|min:1|max:50',
        ]);

        $property = Property::create([
            'name' => $request->name,
            'city' => $request->city,
            'area' => $request->area,
            'price_per_night' => $request->price_per_night,
            'description' => $request->description,
            'host_id' => $owner->id,
            'address' => $request->area . ', ' . $request->city,
            'image_url' => 'assets/images/home.webp',
        ]);

        // Auto-create room profiles
        for ($r = 1; $r <= $request->rooms_count; $r++) {
            Room::create([
                'property_id' => $property->id,
                'room_number' => 'Room ' . (100 + $r),
                'status' => 'available',
            ]);
        }

        return redirect()->route('owner.properties')->with('success', 'Property successfully listed with ' . $request->rooms_count . ' rooms.');
    }

    /**
     * Bookings list
     */
    public function bookings()
    {
        $owner = $this->getOwnerUser();
        $propertyIds = Property::where('host_id', $owner->id)->pluck('id');
        $roomIds = Room::whereIn('property_id', $propertyIds)->pluck('id');
        
        $bookings = Booking::whereIn('room_id', $roomIds)
            ->with(['room.property', 'guest'])
            ->orderBy('check_in', 'desc')
            ->get();

        return view('owner.bookings', compact('bookings'));
    }

    /**
     * Update payment/booking status
     */
    public function updateBookingStatus(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);
        $request->validate([
            'status' => 'required|string|in:pending,paid,refunded',
        ]);

        $booking->update([
            'payment_status' => $request->status,
        ]);

        // Release room if refunded
        if ($request->status == 'refunded') {
            $booking->room->update(['status' => 'available']);
        }

        return back()->with('success', 'Booking status updated successfully.');
    }

    /**
     * Calendar Availability
     */
    public function calendar(Request $request)
    {
        $owner = $this->getOwnerUser();
        $properties = Property::where('host_id', $owner->id)->get();
        $selectedPropertyId = $request->get('property_id', $properties->first()->id ?? null);

        $rooms = [];
        $bookings = [];

        if ($selectedPropertyId) {
            $rooms = Room::where('property_id', $selectedPropertyId)->get();
            $bookings = Booking::whereIn('room_id', $rooms->pluck('id'))
                ->where('payment_status', '!=', 'refunded')
                ->with(['guest', 'room'])
                ->get();
        }

        // Build month dates matrix for visual calendar
        $month = $request->get('month', date('m'));
        $year = $request->get('year', date('Y'));
        
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = (clone $startDate)->endOfMonth();
        
        $daysInMonth = [];
        for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
            $daysInMonth[] = clone $date;
        }

        return view('owner.calendar', compact(
            'properties',
            'selectedPropertyId',
            'rooms',
            'bookings',
            'daysInMonth',
            'month',
            'year'
        ));
    }

    /**
     * Toggle room status directly from calendar (block/unblock dates)
     */
    public function toggleRoomStatus(Request $request, $id)
    {
        $room = Room::findOrFail($id);
        $newStatus = $room->status === 'available' ? 'maintenance' : 'available';
        $room->update(['status' => $newStatus]);

        return back()->with('success', "Room {$room->room_number} status updated to {$newStatus}.");
    }

    /**
     * Payments / Payouts summary
     */
    public function payments()
    {
        $owner = $this->getOwnerUser();
        $propertyIds = Property::where('host_id', $owner->id)->pluck('id');
        $roomIds = Room::whereIn('property_id', $propertyIds)->pluck('id');
        
        $transactions = Payment::whereIn('booking_id', function($q) use ($roomIds) {
                $q->select('id')->from('bookings')->whereIn('room_id', $roomIds);
            })
            ->with('booking.room.property')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalEarnings = $transactions->where('status', 'successful')->sum('amount');
        $payoutPending = $transactions->where('status', 'pending')->sum('amount');
        
        return view('owner.payments', compact('transactions', 'totalEarnings', 'payoutPending'));
    }
}
