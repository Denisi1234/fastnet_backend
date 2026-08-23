<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Property;
use App\Models\Booking;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PayoutController extends Controller
{
    /**
     * List all payouts or filter by status, owner, property, search, date.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Payout::with(['owner', 'property', 'processor'])->orderBy('created_at', 'desc');

        if ($user->role !== 'admin') {
            $query->where('owner_id', $user->id);
        } else {
            if ($request->filled('owner_id')) {
                $query->where('owner_id', $request->input('owner_id'));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->input('property_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('payout_reference', 'ILIKE', "%{$search}%")
                  ->orWhereHas('owner', function($uq) use ($search) {
                      $uq->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('email', 'ILIKE', "%{$search}%");
                  });
            });
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $payouts = $query->paginate($perPage);

        return response()->json($payouts);
    }

    /**
     * Get owner payout overview & metrics (Available, Requested, Processing, Paid, Failed).
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $isAdmin = ($user->role === 'admin');

        $ownerId = $request->input('owner_id');
        if (!$isAdmin) {
            $ownerId = $user->id;
        }

        if ($ownerId) {
            $owners = User::where('id', $ownerId)->where('role', 'owner')->get();
        } else {
            $owners = User::where('role', 'owner')->get();
        }

        $summaries = $owners->map(function ($owner) {
            return $this->calculateOwnerPayoutSummary($owner);
        });

        // Global Totals across owners
        $totals = [
            'total_available'  => $summaries->sum('available_balance'),
            'total_requested'  => $summaries->sum('requested_payout'),
            'total_processing' => $summaries->sum('processing_payout'),
            'total_paid'       => $summaries->sum('completed_payout'),
            'total_failed'     => $summaries->sum('failed_payout'),
        ];

        return response()->json([
            'totals' => $totals,
            'owners' => $summaries,
        ]);
    }

    /**
     * Request a new payout (by Owner or Admin on owner behalf).
     */
    public function requestPayout(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'owner_id'       => 'nullable|exists:users,id',
            'property_id'    => 'nullable|exists:properties,id',
            'amount'         => 'required|numeric|min:1000',
            'payment_method' => 'required|string|in:Mobile Money,Bank Transfer,Manual',
            'account_details'=> 'required|string|max:255',
            'notes'          => 'nullable|string',
        ]);

        $ownerId = ($user->role === 'admin' && $request->filled('owner_id')) ? (int)$request->owner_id : $user->id;
        $owner = User::where('role', 'owner')->findOrFail($ownerId);

        return DB::transaction(function () use ($owner, $request, $user) {
            // Calculate available balance strictly
            $summary = $this->calculateOwnerPayoutSummary($owner);
            $requestedAmount = round((float) $request->amount, 2);

            if ($requestedAmount > $summary['available_balance']) {
                return response()->json([
                    'message' => "Payout request failed. Amount (TSh " . number_format($requestedAmount) . ") exceeds owner's available balance (TSh " . number_format($summary['available_balance']) . ")."
                ], 422);
            }

            // Prevent duplicate pending requests for exact amount within 5 minutes
            $duplicate = Payout::where('owner_id', $owner->id)
                ->where('amount', $requestedAmount)
                ->whereIn('status', ['REQUESTED', 'PROCESSING'])
                ->where('created_at', '>=', Carbon::now()->subMinutes(5))
                ->first();

            if ($duplicate) {
                return response()->json([
                    'message' => 'A payout request for this exact amount is already in progress.',
                    'payout'  => $duplicate,
                ], 409);
            }

            $payoutRef = 'PO-' . strtoupper(Str::random(10));

            $payout = Payout::create([
                'payout_reference' => $payoutRef,
                'owner_id'         => $owner->id,
                'property_id'      => $request->property_id,
                'amount'           => $requestedAmount,
                'payment_method'   => $request->payment_method,
                'account_details'  => $request->account_details,
                'status'           => 'REQUESTED',
                'notes'            => $request->notes,
                'processed_by'     => ($user->role === 'admin') ? $user->id : null,
            ]);

            return response()->json([
                'message' => 'Payout requested successfully.',
                'payout'  => $payout->load(['owner', 'property']),
            ], 201);
        });
    }

    /**
     * Process payout status workflow (Admin only).
     * Workflow rules:
     * REQUESTED -> PROCESSING
     * PROCESSING -> PAID (or FAILED)
     */
    public function updateStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:PROCESSING,PAID,FAILED',
            'notes'  => 'nullable|string',
        ]);

        $payout = Payout::findOrFail($id);
        $newStatus = $request->status;

        // Enforce Workflow Transitions
        $currentStatus = $payout->status;
        $allowed = false;

        if ($currentStatus === 'REQUESTED' && in_array($newStatus, ['PROCESSING', 'FAILED'])) {
            $allowed = true;
        } elseif ($currentStatus === 'PROCESSING' && in_array($newStatus, ['PAID', 'FAILED'])) {
            $allowed = true;
        } elseif ($currentStatus === 'REQUESTED' && $newStatus === 'PAID') {
            // Direct payment approval allowed by admin
            $allowed = true;
        }

        if (!$allowed) {
            return response()->json([
                'message' => "Invalid status transition from {$currentStatus} to {$newStatus}."
            ], 422);
        }

        $payout->status = $newStatus;
        if ($request->filled('notes')) {
            $payout->notes = trim($payout->notes . "\n[" . date('Y-m-d H:i') . "] " . $request->notes);
        }
        $payout->processed_by = $request->user()->id;
        if (in_array($newStatus, ['PAID', 'FAILED'])) {
            $payout->processed_at = Carbon::now();
        }
        $payout->save();

        return response()->json([
            'message' => "Payout {$payout->payout_reference} updated to {$newStatus}.",
            'payout'  => $payout->load(['owner', 'property', 'processor']),
        ]);
    }

    /**
     * Calculate comprehensive financial payout statistics per owner.
     */
    protected function calculateOwnerPayoutSummary(User $owner): array
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

        // Calculate gross 90% owner earnings from completed/paid stays
        $eligibleBookings = (clone $bookingQuery)
            ->where(function ($q) {
                $q->whereIn('status', ['Confirmed', 'Checked In', 'Completed'])
                  ->orWhere('payment_status', 'paid');
            })
            ->get();

        $totalOwnerEarnings = 0.0;
        foreach ($eligibleBookings as $b) {
            $gross = (float) $b->total_price;
            $rate = ($b->commission_rate !== null) ? (float) $b->commission_rate : 10.00;
            $fee = ($b->platform_fee !== null) ? (float) $b->platform_fee : round($gross * ($rate / 100), 2);
            $payout = ($b->owner_payout !== null) ? (float) $b->owner_payout : round($gross - $fee, 2);

            $totalOwnerEarnings += $payout;
        }

        // Refunds subtraction
        $refundedBookingsSum = (float) (clone $bookingQuery)->where('payment_status', 'refunded')->sum('total_price');
        $refundedOwnerShare = round($refundedBookingsSum * 0.90, 2);

        // Aggregate payouts table records
        $payouts = Payout::where('owner_id', $owner->id)->get();

        $requestedPayout  = (float) $payouts->where('status', 'REQUESTED')->sum('amount');
        $processingPayout = (float) $payouts->where('status', 'PROCESSING')->sum('amount');
        $completedPayout  = (float) $payouts->where('status', 'PAID')->sum('amount');
        $failedPayout     = (float) $payouts->where('status', 'FAILED')->sum('amount');

        // Net Available Balance = Owner 90% Earnings - Refunds (Owner share 90%) - Completed Payouts - Requested Payouts - Processing Payouts
        $availableBalance = max(0.0, round($totalOwnerEarnings - $refundedOwnerShare - $completedPayout - $requestedPayout - $processingPayout, 2));

        return [
            'owner_id'          => $owner->id,
            'owner_name'        => $owner->name,
            'owner_email'       => $owner->email,
            'owner_phone'       => $owner->phone_number ?? 'N/A',
            'property_count'    => count($properties),
            'total_earnings'    => round($totalOwnerEarnings, 2),
            'available_balance' => round($availableBalance, 2),
            'requested_payout'  => round($requestedPayout, 2),
            'processing_payout' => round($processingPayout, 2),
            'completed_payout'  => round($completedPayout, 2),
            'failed_payout'     => round($failedPayout, 2),
        ];
    }
}
