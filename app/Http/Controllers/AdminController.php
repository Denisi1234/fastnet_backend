<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Property;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Update user status (Activate, Suspend, Deactivate, Restore) with audit logging.
     */
    public function updateUserStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Super Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Active,Suspended,Pending Verification,Deactivated',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = User::findOrFail($id);
        $oldStatus = $user->status ?? 'Active';
        $newStatus = $request->status;
        $reason = trim($request->input('reason', 'Status updated by Super Admin'));

        $user->status = $newStatus;
        $user->save();

        // Audit logging entry
        \Illuminate\Support\Facades\Log::info("SUPER ADMIN STATUS CHANGE", [
            'admin_id'      => $request->user()->id,
            'admin_email'   => $request->user()->email,
            'target_user_id'=> $user->id,
            'old_status'    => $oldStatus,
            'new_status'    => $newStatus,
            'reason'        => $reason,
            'timestamp'     => \Carbon\Carbon::now()->toDateTimeString(),
        ]);

        return response()->json([
            'message' => "User status updated from {$oldStatus} to {$newStatus}.",
            'user'    => $user,
            'audit'   => [
                'updated_by' => $request->user()->name,
                'updated_at' => \Carbon\Carbon::now()->toDateTimeString(),
                'reason'     => $reason,
            ]
        ]);
    }

    /**
     * Complete Super Admin Platform Overview Statistics & Analytics
     */
    public function dashboardStats(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $range = $request->input('date_range', '30_days');
        $dateFrom = null;
        $dateTo = null;

        if ($range === 'today') {
            $dateFrom = \Carbon\Carbon::today();
            $dateTo = \Carbon\Carbon::today()->endOfDay();
        } elseif ($range === 'yesterday') {
            $dateFrom = \Carbon\Carbon::yesterday()->startOfDay();
            $dateTo = \Carbon\Carbon::yesterday()->endOfDay();
        } elseif ($range === '7_days') {
            $dateFrom = \Carbon\Carbon::now()->subDays(6)->startOfDay();
            $dateTo = \Carbon\Carbon::now()->endOfDay();
        } elseif ($range === '30_days') {
            $dateFrom = \Carbon\Carbon::now()->subDays(29)->startOfDay();
            $dateTo = \Carbon\Carbon::now()->endOfDay();
        } elseif ($range === 'this_month') {
            $dateFrom = \Carbon\Carbon::now()->startOfMonth();
            $dateTo = \Carbon\Carbon::now()->endOfMonth();
        } elseif ($range === 'last_month') {
            $dateFrom = \Carbon\Carbon::now()->subMonth()->startOfMonth();
            $dateTo = \Carbon\Carbon::now()->subMonth()->endOfMonth();
        } elseif ($range === 'custom') {
            if ($request->filled('date_from')) $dateFrom = \Carbon\Carbon::parse($request->input('date_from'))->startOfDay();
            if ($request->filled('date_to')) $dateTo = \Carbon\Carbon::parse($request->input('date_to'))->endOfDay();
        }

        // Owners statistics
        $ownerQuery = User::where('role', 'owner');
        $totalOwners = (clone $ownerQuery)->count();
        $activeOwners = (clone $ownerQuery)->where(function($q){
            $q->where('status', 'Active')->orWhereNull('status');
        })->count();
        $suspendedOwners = (clone $ownerQuery)->where('status', 'Suspended')->count();

        // Lodges statistics
        $lodgeQuery = Property::query();
        $totalLodges = (clone $lodgeQuery)->count();
        $approvedLodges = (clone $lodgeQuery)->where('status', 'Active')->count();
        $pendingLodges = (clone $lodgeQuery)->whereIn('status', ['Pending', 'changes_requested'])->count();
        $suspendedLodges = (clone $lodgeQuery)->whereIn('status', ['Suspended', 'Removed', 'Inactive'])->count();

        // Rooms statistics
        $totalRooms = \Illuminate\Support\Facades\DB::table('rooms')->count();

        // Customers statistics
        $totalCustomers = User::where('role', 'customer')->count();

        // Bookings statistics (scoped by date range if provided)
        $bookingQuery = Booking::query();
        if ($dateFrom) $bookingQuery->where('created_at', '>=', $dateFrom);
        if ($dateTo) $bookingQuery->where('created_at', '<=', $dateTo);

        $totalBookings = (clone $bookingQuery)->count();
        $confirmedBookings = (clone $bookingQuery)->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])->count();
        $pendingBookings = (clone $bookingQuery)->where('status', 'Pending')->count();
        $cancelledBookings = (clone $bookingQuery)->where('status', 'Cancelled')->count();

        // Financial aggregations
        $eligibleBookings = (clone $bookingQuery)
            ->where(function($q) {
                $q->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])
                  ->orWhere('payment_status', 'paid');
            })
            ->get();

        $grossBookingValue = 0.0;
        $platformCommission = 0.0;
        $ownerEarnings = 0.0;

        foreach ($eligibleBookings as $b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            $grossBookingValue += $gross;
            $platformCommission += $fee;
            $ownerEarnings += $payout;
        }

        // Payouts aggregations
        $payoutQuery = \App\Models\Payout::query();
        if ($dateFrom) $payoutQuery->where('created_at', '>=', $dateFrom);
        if ($dateTo) $payoutQuery->where('created_at', '<=', $dateTo);

        $payouts = $payoutQuery->get();
        $pendingPayouts = (float) $payouts->whereIn('status', ['REQUESTED', 'PROCESSING'])->sum('amount');
        $completedPayouts = (float) $payouts->where('status', 'PAID')->sum('amount');

        // Refunds aggregations
        $refunds = (float) (clone $bookingQuery)->where('payment_status', 'refunded')->sum('total_price');

        // Time Series chart data (7 intervals)
        $chartSeries = [];
        $stepDays = 1;
        if ($dateFrom && $dateTo) {
            $diffDays = max(1, $dateFrom->diffInDays($dateTo));
            $stepDays = max(1, (int) ceil($diffDays / 7));
        }

        $cursor = $dateFrom ? clone $dateFrom : \Carbon\Carbon::now()->subDays(29)->startOfDay();
        $finalTo = $dateTo ? clone $dateTo : \Carbon\Carbon::now()->endOfDay();

        while ($cursor <= $finalTo) {
            $subEnd = (clone $cursor)->addDays($stepDays - 1)->endOfDay();
            if ($subEnd > $finalTo) $subEnd = clone $finalTo;

            $intervalBookings = (clone $bookingQuery)
                ->whereBetween('created_at', [$cursor, $subEnd])
                ->where(function($q) {
                    $q->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])
                      ->orWhere('payment_status', 'paid');
                })
                ->get();

            $iGross = 0.0;
            $iNet = 0.0;
            $iFee = 0.0;
            foreach ($intervalBookings as $ib) {
                $ig = (float) $ib->total_price;
                $ir = ($ib->commission_rate !== null) ? (float) $ib->commission_rate : 10.00;
                $ifee = ($ib->platform_fee !== null) ? (float) $ib->platform_fee : round($ig * ($ir / 100), 2);
                $inet = ($ib->owner_payout !== null) ? (float) $ib->owner_payout : round($ig - $ifee, 2);

                $iGross += $ig;
                $iFee += $ifee;
                $iNet += $inet;
            }

            $chartSeries[] = [
                'period' => $cursor->format('M d'),
                'gross' => round($iGross, 2),
                'owner_earnings' => round($iNet, 2),
                'platform_commission' => round($iFee, 2),
                'bookings_count' => (clone $bookingQuery)->whereBetween('created_at', [$cursor, $subEnd])->count(),
            ];

            $cursor->addDays($stepDays);
        }

        return response()->json([
            'owners' => [
                'total' => $totalOwners,
                'active' => $activeOwners,
                'suspended' => $suspendedOwners,
            ],
            'lodges' => [
                'total' => $totalLodges,
                'approved' => $approvedLodges,
                'pending' => $pendingLodges,
                'suspended' => $suspendedLodges,
            ],
            'rooms' => [
                'total' => $totalRooms,
            ],
            'customers' => [
                'total' => $totalCustomers,
            ],
            'bookings' => [
                'total' => $totalBookings,
                'confirmed' => $confirmedBookings,
                'pending' => $pendingBookings,
                'cancelled' => $cancelledBookings,
            ],
            'financials' => [
                'gross_booking_value' => round($grossBookingValue, 2),
                'platform_commission' => round($platformCommission, 2),
                'owner_earnings'      => round($ownerEarnings, 2),
                'pending_payouts'      => round($pendingPayouts, 2),
                'completed_payouts'    => round($completedPayouts, 2),
                'refunds'              => round($refunds, 2),
            ],
            'chart_series' => $chartSeries,
            'date_range' => [
                'selected' => $range,
                'from' => $dateFrom ? $dateFrom->format('Y-m-d') : null,
                'to' => $dateTo ? $dateTo->format('Y-m-d') : null,
            ]
        ]);
    }

    /**
     * Get owner management financial summaries with optimized SQL aggregation, search, filtering, sorting, and pagination.
     */
    public function ownerFinancialSummary(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $search = trim($request->input('search', ''));
        $status = trim($request->input('status', ''));
        $sortBy = $request->input('sort_by', 'created_at'); // created_at, name, gross_value, net_earnings, total_bookings
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));

        $query = User::where('role', 'owner');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%")
                  ->orWhere('phone_number', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($status)) {
            $query->where('status', $status);
        }

        $owners = $query->paginate($perPage);

        $items = collect($owners->items())->map(function ($owner) {
            return $this->buildOwnerFinancialProfile($owner);
        });

        if (in_array($sortBy, ['gross_value', 'net_earnings', 'total_bookings'])) {
            $items = ($sortOrder === 'desc') ? $items->sortByDesc($sortBy)->values() : $items->sortBy($sortBy)->values();
        }

        return response()->json([
            'current_page' => $owners->currentPage(),
            'per_page'     => $owners->perPage(),
            'total'        => $owners->total(),
            'last_page'    => $owners->lastPage(),
            'data'         => $items,
        ]);
    }

    /**
     * Get single owner detailed financial profile.
     */
    public function ownerFinancialProfile(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $owner = User::where('role', 'owner')->findOrFail($id);

        $properties = Property::where('host_id', $owner->id)->get();
        $propertyIds = $properties->pluck('id')->toArray();
        $activeLodgesCount = $properties->where('status', 'Active')->count();
        $inactiveLodgesCount = $properties->where('status', '!=', 'Active')->count();

        $roomIds = [];
        if (!empty($propertyIds)) {
            $roomIds = DB::table('rooms')->whereIn('property_id', $propertyIds)->pluck('id')->toArray();
        }

        $bookingQuery = Booking::query();
        if (empty($roomIds)) {
            $bookingQuery->whereRaw('1 = 0');
        } else {
            $bookingQuery->whereIn('room_id', $roomIds);
        }

        // Apply filters for transactions
        if ($request->filled('date_from')) {
            $bookingQuery->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $bookingQuery->whereDate('created_at', '<=', $request->input('date_to'));
        }
        if ($request->filled('property_id')) {
            $propRoomIds = DB::table('rooms')->where('property_id', $request->input('property_id'))->pluck('id')->toArray();
            $bookingQuery->whereIn('room_id', $propRoomIds);
        }
        if ($request->filled('booking_code')) {
            $code = trim($request->input('booking_code'));
            $bookingQuery->where(function($q) use ($code) {
                $q->where('booking_code', 'ILIKE', "%{$code}%")
                  ->orWhere('id', 'LIKE', "%{$code}%");
            });
        }
        if ($request->filled('payment_status')) {
            $bookingQuery->where('payment_status', $request->input('payment_status'));
        }

        $allBookings = (clone $bookingQuery)->with(['guest', 'room.property', 'payments'])->orderBy('created_at', 'desc')->get();

        $grossRevenue = 0.0;
        $platformCommission = 0.0;
        $ownerEarnings = 0.0;
        $pendingBalance = 0.0;
        $totalPaidOut = 0.0;
        $totalRefunded = 0.0;

        $transactions = [];

        foreach ($allBookings as $b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            $isEligible = in_array($b->status, ['Confirmed', 'Checked In', 'Completed']) || $b->payment_status === 'paid';
            $isRefund = ($b->payment_status === 'refunded');

            if ($isEligible) {
                $grossRevenue += $gross;
                $platformCommission += $fee;
                $ownerEarnings += $payout;

                if (in_array($b->status, ['Completed', 'Checked In']) && $b->payment_status === 'paid') {
                    $totalPaidOut += $payout;
                    $payoutStatus = 'Settled & Paid';
                } else {
                    $pendingBalance += $payout;
                    $payoutStatus = 'Pending Settlement';
                }
            } else if ($b->status === 'Pending') {
                $pendingBalance += $payout;
                $payoutStatus = 'Unconfirmed Reservation';
            } else {
                $payoutStatus = 'N/A';
            }

            if ($isRefund) {
                $totalRefunded += $gross;
                $payoutStatus = 'Refunded';
            }

            $txType = $isRefund ? 'Refund' : (($b->payment_status === 'paid') ? 'Booking Payout' : 'Reservation');

            // Apply Transaction Type & Payout Status client filters if requested
            if ($request->filled('transaction_type') && strtolower($request->input('transaction_type')) !== strtolower($txType)) {
                continue;
            }
            if ($request->filled('payout_status') && strtolower($request->input('payout_status')) !== strtolower($payoutStatus)) {
                continue;
            }

            $transactions[] = [
                'booking_id'        => $b->id,
                'booking_code'      => $b->booking_code ?? ('BK-' . $b->id),
                'transaction_date'  => $b->created_at ? $b->created_at->format('Y-m-d H:i:s') : null,
                'guest_id'          => $b->guest_id,
                'guest_name'        => optional($b->guest)->name ?? 'Guest #' . $b->guest_id,
                'guest_email'       => optional($b->guest)->email ?? 'N/A',
                'property_id'       => optional($b->room)->property_id,
                'property_name'     => optional(optional($b->room)->property)->name ?? 'Lodge Stay',
                'room_number'       => optional($b->room)->room_number ?? 'Room #' . $b->room_id,
                'check_in'          => $b->check_in ? $b->check_in->format('Y-m-d') : null,
                'check_out'         => $b->check_out ? $b->check_out->format('Y-m-d') : null,
                'gross_amount'      => $gross,
                'commission_rate'   => $rate,
                'platform_fee'      => $fee,
                'owner_net_payout'  => $payout,
                'booking_status'    => $b->status ?? 'Pending',
                'payment_status'    => $b->payment_status ?? 'pending',
                'payment_reference' => $b->payment_reference ?? 'N/A',
                'transaction_type'  => $txType,
                'payout_status'     => $payoutStatus,
            ];
        }

        $availableBalance = max(0.0, $ownerEarnings - $totalPaidOut);

        return response()->json([
            'owner_information' => [
                'id'              => $owner->id,
                'name'            => $owner->name,
                'email'           => $owner->email,
                'phone'           => $owner->phone_number ?? 'N/A',
                'account_status'  => $owner->status ?? 'Active',
                'registered_date' => $owner->created_at ? $owner->created_at->format('Y-m-d H:i:s') : null,
            ],
            'lodge_information' => [
                'total_lodges'    => count($properties),
                'active_lodges'   => $activeLodgesCount,
                'inactive_lodges' => $inactiveLodgesCount,
                'lodges_list'     => $properties->map(fn($p) => [
                    'id'     => $p->id,
                    'name'   => $p->name,
                    'city'   => $p->city,
                    'area'   => $p->area,
                    'status' => $p->status,
                ]),
            ],
            'financial_summary' => [
                'gross_revenue'       => round($grossRevenue, 2),
                'platform_commission' => round($platformCommission, 2),
                'owner_earnings'      => round($ownerEarnings, 2),
                'available_balance'   => round($availableBalance, 2),
                'pending_balance'     => round($pendingBalance, 2),
                'total_paid_out'      => round($totalPaidOut, 2),
                'total_refunded'      => round($totalRefunded, 2),
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * Helper to perform optimized SQL aggregations for owner finance.
     */
    protected function buildOwnerFinancialProfile(User $owner, bool $includeDetails = false): array
    {
        $properties = Property::where('host_id', $owner->id)->get();
        $propertyIds = $properties->pluck('id')->toArray();
        $roomIds = [];
        if (!empty($propertyIds)) {
            $roomIds = DB::table('rooms')->whereIn('property_id', $propertyIds)->pluck('id')->toArray();
        }

        $bookingQuery = Booking::query();
        if (empty($roomIds)) {
            $bookingQuery->whereRaw('1 = 0');
        } else {
            $bookingQuery->whereIn('room_id', $roomIds);
        }

        $totalBookings = (clone $bookingQuery)->count();
        $confirmedBookings = (clone $bookingQuery)->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])->count();
        $refundedCount = (clone $bookingQuery)->where('payment_status', 'refunded')->count();

        // Eligible bookings for gross/net calculations
        $eligibleBookings = (clone $bookingQuery)
            ->where(function ($q) {
                $q->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])
                  ->orWhere('payment_status', 'paid');
            })
            ->get();

        $grossValue = 0.0;
        $platformCommission = 0.0;
        $ownerEarnings = 0.0;

        foreach ($eligibleBookings as $b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            $grossValue += $gross;
            $platformCommission += $fee;
            $ownerEarnings += $payout;
        }

        $pendingEarnings = (float) (clone $bookingQuery)
            ->where('status', 'Pending')
            ->where('payment_status', '!=', 'paid')
            ->sum('total_price') * 0.90;

        $paidAmount = (float) (clone $bookingQuery)
            ->where('payment_status', 'paid')
            ->whereIn('status', ['Completed', 'Checked In'])
            ->get()
            ->sum(function ($b) {
                $gross = (float) $b->total_price;
                $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
                $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
                return round($gross - $fee, 2);
            });

        $outstandingPayout = max(0.0, $ownerEarnings - $paidAmount);
        $refundsTotal = (float) (clone $bookingQuery)->where('payment_status', 'refunded')->sum('total_price');

        $lastBooking = (clone $bookingQuery)->orderBy('created_at', 'desc')->first();
        $lastTransactionDate = $lastBooking ? ($lastBooking->created_at ? $lastBooking->created_at->format('Y-m-d H:i:s') : null) : null;

        $result = [
            'owner_id'             => $owner->id,
            'owner_name'           => $owner->name,
            'owner_email'          => $owner->email,
            'owner_phone'          => $owner->phone_number ?? 'N/A',
            'account_status'       => $owner->status ?? 'Active',
            'property_count'       => count($properties),
            'total_bookings'       => $totalBookings,
            'confirmed_bookings'   => $confirmedBookings,
            'refunded_count'       => $refundedCount,
            'gross_booking_value'  => round($grossValue, 2),
            'platform_commission'  => round($platformCommission, 2),
            'owner_earnings'       => round($ownerEarnings, 2),
            'pending_earnings'     => round($pendingEarnings, 2),
            'paid_amount'          => round($paidAmount, 2),
            'outstanding_payout'   => round($outstandingPayout, 2),
            'refunds_total'        => round($refundsTotal, 2),
            'last_transaction'     => $lastTransactionDate,
        ];

        if ($includeDetails) {
            $result['properties'] = $properties->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'city' => $p->city,
                    'area' => $p->area,
                    'status' => $p->status,
                ];
            });

            $result['recent_bookings'] = (clone $bookingQuery)
                ->with(['guest', 'room.property'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($b) {
                    $gross = (float) $b->total_price;
                    $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
                    $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
                    $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

                    return [
                        'id' => $b->id,
                        'booking_code' => $b->booking_code ?? ('BK-' . $b->id),
                        'guest_name' => optional($b->guest)->name ?? 'Guest #' . $b->guest_id,
                        'property_name' => optional(optional($b->room)->property)->name ?? 'Lodge Stay',
                        'gross_amount' => $gross,
                        'platform_fee' => $fee,
                        'net_payout' => $payout,
                        'status' => $b->status ?? 'Pending',
                        'payment_status' => $b->payment_status ?? 'pending',
                        'created_at' => $b->created_at ? $b->created_at->format('Y-m-d H:i:s') : null,
                    ];
                });
        }

        return $result;
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
     * Update property status (Pending, APPROVED, Active, Inactive, Rejected, Suspended).
     */
    public function updatePropertyStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Super Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Pending,APPROVED,Active,Inactive,Rejected,Suspended,changes_requested,Removed',
            'reason' => 'nullable|string|max:500',
        ]);

        $property = Property::findOrFail($id);
        $oldStatus = $property->status ?? 'Pending';
        $newStatus = $request->status;
        $reason = trim($request->input('reason', 'Status updated by Super Admin'));

        $property->status = $newStatus;
        $property->save();

        \Illuminate\Support\Facades\Log::info("SUPER ADMIN LODGE STATUS CHANGE", [
            'admin_id'    => $request->user()->id,
            'property_id' => $property->id,
            'old_status'  => $oldStatus,
            'new_status'  => $newStatus,
            'reason'      => $reason,
            'timestamp'   => \Carbon\Carbon::now()->toDateTimeString(),
        ]);

        return response()->json([
            'message' => "Lodge status updated from {$oldStatus} to {$newStatus}.",
            'property' => $property,
        ]);
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
