<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FinanceController extends Controller
{
    /**
     * Get financial metrics, monthly performance breakdown, and recent transactions for authenticated user.
     * Admins receive system-wide financial statistics.
     * Lodge Owners receive metrics scoped strictly to their owned lodges/rooms.
     */
    public function overview(Request $request)
    {
        $user = $request->user();
        $isAdmin = ($user->role === 'admin');

        // Identify property IDs and room IDs owned by the user
        $propertyIds = [];
        $roomIds = [];

        if (!$isAdmin) {
            $properties = Property::where('host_id', $user->id)->get();
            $propertyIds = $properties->pluck('id')->toArray();
            
            if (!empty($propertyIds)) {
                $roomIds = DB::table('rooms')->whereIn('property_id', $propertyIds)->pluck('id')->toArray();
            }
        }

        // Build base booking query
        $bookingQuery = Booking::query();
        if (!$isAdmin) {
            if (empty($roomIds)) {
                $bookingQuery->whereRaw('1 = 0'); // No rooms owned -> no bookings
            } else {
                $bookingQuery->whereIn('room_id', $roomIds);
            }
        }

        // Date Range Filtering (today, 7_days, 30_days, this_month, last_month, custom)
        $range = $request->input('date_range', '30_days');
        $dateFrom = null;
        $dateTo = null;

        if ($range === 'today') {
            $dateFrom = Carbon::today();
            $dateTo = Carbon::today()->endOfDay();
        } elseif ($range === '7_days') {
            $dateFrom = Carbon::now()->subDays(6)->startOfDay();
            $dateTo = Carbon::now()->endOfDay();
        } elseif ($range === '30_days') {
            $dateFrom = Carbon::now()->subDays(29)->startOfDay();
            $dateTo = Carbon::now()->endOfDay();
        } elseif ($range === 'this_month') {
            $dateFrom = Carbon::now()->startOfMonth();
            $dateTo = Carbon::now()->endOfMonth();
        } elseif ($range === 'last_month') {
            $dateFrom = Carbon::now()->subMonth()->startOfMonth();
            $dateTo = Carbon::now()->subMonth()->endOfMonth();
        } elseif ($range === 'custom') {
            if ($request->filled('date_from')) $dateFrom = Carbon::parse($request->input('date_from'))->startOfDay();
            if ($request->filled('date_to')) $dateTo = Carbon::parse($request->input('date_to'))->endOfDay();
        }

        if ($dateFrom) $bookingQuery->where('created_at', '>=', $dateFrom);
        if ($dateTo) $bookingQuery->where('created_at', '<=', $dateTo);

        // Core Financial Metrics Calculation
        $totalBookings = (clone $bookingQuery)->count();

        $eligibleBookings = (clone $bookingQuery)
            ->where(function($q) {
                $q->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])
                  ->orWhere('payment_status', 'paid');
            })
            ->get();

        $grossBookingValue = 0.0;
        $platformCommission = 0.0;
        $totalOwnerEarnings = 0.0;

        foreach ($eligibleBookings as $b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            $grossBookingValue += $gross;
            $platformCommission += $fee;
            $totalOwnerEarnings += $payout;
        }

        // Payout table aggregations
        $payoutQuery = \App\Models\Payout::query();
        if (!$isAdmin) {
            $payoutQuery->where('owner_id', $user->id);
        }
        if ($dateFrom) $payoutQuery->where('created_at', '>=', $dateFrom);
        if ($dateTo) $payoutQuery->where('created_at', '<=', $dateTo);

        $payouts = $payoutQuery->get();
        $pendingPayouts = (float) $payouts->whereIn('status', ['REQUESTED', 'PROCESSING'])->sum('amount');
        $completedPayouts = (float) $payouts->where('status', 'PAID')->sum('amount');

        // Refund aggregations
        $refundsQuery = (clone $bookingQuery)->where('payment_status', 'refunded');
        $refunds = (float) $refundsQuery->sum('total_price');

        // Time Series Chart Data (Divided into 7 intervals across selected range)
        $chartSeries = [];
        $stepDays = 1;
        if ($dateFrom && $dateTo) {
            $diffDays = max(1, $dateFrom->diffInDays($dateTo));
            $stepDays = max(1, (int) ceil($diffDays / 7));
        }

        $cursor = $dateFrom ? clone $dateFrom : Carbon::now()->subDays(29)->startOfDay();
        $finalTo = $dateTo ? clone $dateTo : Carbon::now()->endOfDay();

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
            $iFee = 0.0;
            $iNet = 0.0;
            foreach ($intervalBookings as $ib) {
                $ig = (float) $ib->total_price;
                $ir = ($ib->commission_rate !== null) ? (float) $ib->commission_rate : 10.00;
                $ifee = ($ib->platform_fee !== null) ? (float) $ib->platform_fee : round($ig * ($ir / 100), 2);
                $inet = ($ib->owner_payout !== null) ? (float) $ib->owner_payout : round($ig - $ifee, 2);

                $iGross += $ig;
                $iFee += $ifee;
                $iNet += $inet;
            }

            $iPayouts = (float) (clone $payoutQuery)->whereBetween('created_at', [$cursor, $subEnd])->where('status', 'PAID')->sum('amount');
            $iRefunds = (float) (clone $bookingQuery)->whereBetween('created_at', [$cursor, $subEnd])->where('payment_status', 'refunded')->sum('total_price');

            $chartSeries[] = [
                'period' => $cursor->format('M d'),
                'revenue' => round($iGross, 2),
                'owner_earnings' => round($iNet, 2),
                'platform_commission' => round($iFee, 2),
                'payouts' => round($iPayouts, 2),
                'refunds' => round($iRefunds, 2),
            ];

            $cursor->addDays($stepDays);
        }

        // Recent Invoices / Transactions list
        $recentBookingsQuery = (clone $bookingQuery)
            ->with(['guest', 'room.property'])
            ->orderBy('created_at', 'desc')
            ->limit(15);

        $recentBookings = $recentBookingsQuery->get()->map(function ($b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            return [
                'id' => $b->id,
                'booking_code' => $b->booking_code ?? ('BK-' . str_pad($b->id, 5, '0', STR_PAD_LEFT)),
                'guest_name' => optional($b->guest)->name ?? 'Guest #' . $b->guest_id,
                'guest_email' => optional($b->guest)->email ?? 'N/A',
                'property_name' => optional(optional($b->room)->property)->name ?? 'Lodge Stay',
                'room_number' => optional($b->room)->room_number ?? 'Room #' . $b->room_id,
                'check_in' => $b->check_in ? $b->check_in->format('Y-m-d') : null,
                'check_out' => $b->check_out ? $b->check_out->format('Y-m-d') : null,
                'gross_amount' => $gross,
                'commission_rate' => $rate,
                'owner_share_percent' => (100 - $rate),
                'platform_fee' => $fee,
                'net_payout' => $payout,
                'status' => $b->status ?? 'Pending',
                'payment_status' => $b->payment_status ?? 'pending',
                'payment_reference' => $b->payment_reference ?? 'N/A',
                'created_at' => $b->created_at ? $b->created_at->format('Y-m-d H:i:s') : null,
            ];
        });

        return response()->json([
            'summary_cards' => [
                'total_owner_earnings' => round($totalOwnerEarnings, 2),
                'platform_commission' => round($platformCommission, 2),
                'gross_booking_value'  => round($grossBookingValue, 2),
                'pending_payouts'      => round($pendingPayouts, 2),
                'completed_payouts'    => round($completedPayouts, 2),
                'refunds'              => round($refunds, 2),
            ],
            'chart_series' => $chartSeries,
            'recent_transactions' => $recentBookings,
            'date_range' => [
                'selected' => $range,
                'from'     => $dateFrom ? $dateFrom->format('Y-m-d') : null,
                'to'       => $dateTo ? $dateTo->format('Y-m-d') : null,
            ]
        ]);
    }

    /**
     * Professional financial transaction ledger with multi-field search, date filtering,
     * owner filtering, lodge filtering, status filtering, sorting, and pagination.
     */
    public function ledger(Request $request)
    {
        $user = $request->user();
        $isAdmin = ($user->role === 'admin');

        $query = Booking::with(['guest', 'room.property', 'payments']);

        // Scope to owner if not admin
        if (!$isAdmin) {
            $properties = Property::where('host_id', $user->id)->pluck('id')->toArray();
            if (empty($properties)) {
                $query->whereRaw('1 = 0');
            } else {
                $roomIds = DB::table('rooms')->whereIn('property_id', $properties)->pluck('id')->toArray();
                $query->whereIn('room_id', $roomIds);
            }
        } else {
            if ($request->filled('owner_id')) {
                $ownerProps = Property::where('host_id', $request->input('owner_id'))->pluck('id')->toArray();
                if (empty($ownerProps)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $roomIds = DB::table('rooms')->whereIn('property_id', $ownerProps)->pluck('id')->toArray();
                    $query->whereIn('room_id', $roomIds);
                }
            }
        }

        if ($request->filled('property_id')) {
            $propRoomIds = DB::table('rooms')->where('property_id', $request->input('property_id'))->pluck('id')->toArray();
            $query->whereIn('room_id', $propRoomIds);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('min_amount')) {
            $query->where('total_price', '>=', (float)$request->input('min_amount'));
        }

        if ($request->filled('max_amount')) {
            $query->where('total_price', '<=', (float)$request->input('max_amount'));
        }

        if ($request->filled('transaction_id')) {
            $txIdStr = trim($request->input('transaction_id'));
            $cleanId = (int) preg_replace('/[^0-9]/', '', $txIdStr);
            if ($cleanId > 0) {
                $query->where('id', $cleanId);
            }
        }

        if ($request->filled('booking_reference')) {
            $code = trim($request->input('booking_reference'));
            $query->where('booking_code', 'ILIKE', "%{$code}%");
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('booking_code', 'ILIKE', "%{$search}%")
                  ->orWhere('payment_reference', 'ILIKE', "%{$search}%")
                  ->orWhereHas('guest', function($gq) use ($search) {
                      $gq->where('name', 'ILIKE', "%{$search}%")
                         ->orWhere('email', 'ILIKE', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sortBy, ['created_at', 'total_price', 'check_in'])) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $paginator = $query->paginate($perPage);

        $transformed = collect($paginator->items())->map(function ($b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            $isRefund = ($b->payment_status === 'refunded');
            $txType = $isRefund ? 'Refund' : (($b->payment_status === 'paid') ? 'Booking Payout' : 'Reservation');

            $payoutStatus = 'Pending Settlement';
            if (in_array($b->status, ['Completed', 'Checked In']) && $b->payment_status === 'paid') {
                $payoutStatus = 'Settled & Paid';
            } elseif ($isRefund) {
                $payoutStatus = 'Refunded';
            } elseif ($b->status === 'Pending') {
                $payoutStatus = 'Unconfirmed Reservation';
            }

            $property = optional(optional($b->room)->property);
            $host = $property ? User::find($property->host_id) : null;

            return [
                'transaction_id'     => 'TX-' . str_pad($b->id, 8, '0', STR_PAD_LEFT),
                'booking_id'         => $b->id,
                'booking_reference'  => $b->booking_code ?? ('BK-' . $b->id),
                'date'               => $b->created_at ? $b->created_at->format('Y-m-d H:i:s') : null,
                'owner'              => [
                    'id'    => $host ? $host->id : null,
                    'name'  => $host ? $host->name : 'N/A',
                    'email' => $host ? $host->email : 'N/A',
                ],
                'lodge'              => [
                    'id'   => $property ? $property->id : null,
                    'name' => $property ? $property->name : 'Lodge Stay',
                    'city' => $property ? $property->city : 'N/A',
                    'room_number' => optional($b->room)->room_number ?? 'N/A',
                ],
                'guest'              => [
                    'id'    => $b->guest_id,
                    'name'  => optional($b->guest)->name ?? 'Guest #' . $b->guest_id,
                    'email' => optional($b->guest)->email ?? 'N/A',
                ],
                'gross_amount'       => $gross,
                'platform_fee_10'    => $fee,
                'owner_net_90'       => $payout,
                'commission_rate'    => $rate,
                'transaction_type'   => $txType,
                'payment_status'     => $b->payment_status ?? 'pending',
                'payout_status'      => $payoutStatus,
                'payment_reference'  => $b->payment_reference ?? ('PAY-REF-' . $b->id),
                'check_in'           => $b->check_in ? $b->check_in->format('Y-m-d') : null,
                'check_out'          => $b->check_out ? $b->check_out->format('Y-m-d') : null,
                'booking_status'     => $b->status ?? 'Pending',
            ];
        });

        // Filter transformed items if client provided client-side specific filters
        if ($request->filled('transaction_type')) {
            $type = strtolower($request->input('transaction_type'));
            $transformed = $transformed->filter(fn($t) => strtolower($t['transaction_type']) === $type)->values();
        }
        if ($request->filled('payout_status')) {
            $ps = strtolower($request->input('payout_status'));
            $transformed = $transformed->filter(fn($t) => strtolower($t['payout_status']) === $ps)->values();
        }

        return response()->json([
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'data'         => $transformed,
        ]);
    }
}
