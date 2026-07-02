<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function checkout(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'gateway' => 'required|in:stripe,flutterwave,mpesa',
        ]);

        $booking = Booking::with('room.property')->findOrFail($request->booking_id);

        if ($booking->payment_status === 'paid') {
            return response()->json(['message' => 'Booking is already paid.'], 400);
        }

        // Mock payment initiation
        $transactionId = 'tx_' . Str::random(16);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'gateway' => $request->gateway,
            'transaction_id' => $transactionId,
            'amount' => $booking->total_price,
            'status' => 'pending',
        ]);

        // Simulating redirect URL for checkout page
        $checkoutUrl = "https://checkout.fastnet.com/pay/{$transactionId}?amount={$booking->total_price}&gateway={$request->gateway}";

        return response()->json([
            'message' => 'Payment initiated.',
            'transaction_id' => $transactionId,
            'checkout_url' => $checkoutUrl,
        ]);
    }

    public function webhook(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|string',
            'status' => 'required|in:successful,failed',
        ]);

        $payment = Payment::where('transaction_id', $request->transaction_id)->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment record not found.'], 444);
        }

        if ($payment->status !== 'pending') {
            return response()->json(['message' => 'Payment has already been processed.'], 400);
        }

        DB::transaction(function () use ($payment, $request) {
            $payment->update([
                'status' => $request->status,
            ]);

            if ($request->status === 'successful') {
                $booking = Booking::find($payment->booking_id);
                $booking->update([
                    'payment_status' => 'paid',
                    'payment_reference' => $payment->transaction_id,
                ]);

                // Set room status to booked
                $booking->room->update(['status' => 'booked']);
            }
        });

        return response()->json([
            'message' => 'Webhook received and processed.',
            'payment_status' => $payment->status,
        ]);
    }
}
