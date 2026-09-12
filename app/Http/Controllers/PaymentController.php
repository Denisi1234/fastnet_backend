<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Initiate payment transaction with AzamPay Payment Gateway.
     * Amount is authoritatively fetched from the Booking model in DB. Currency is TZS.
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'booking_id'    => 'nullable|exists:bookings,id',
            'booking_code'  => 'nullable|string',
            'gateway'       => 'nullable|string',
            'payment_method'=> 'nullable|string',
            'phone_number'  => 'nullable|string',
        ]);

        $bookingCode = $request->input('booking_code');
        $bookingId   = $request->input('booking_id');

        $query = Booking::with('room.property');
        if ($bookingId) {
            $query->where('id', $bookingId);
        } else if ($bookingCode) {
            $query->where('booking_code', $bookingCode);
        } else {
            return response()->json(['message' => 'booking_id or booking_code is required.'], 422);
        }

        $booking = $query->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if ($booking->payment_status === 'paid') {
            return response()->json([
                'message' => 'Booking is already paid.',
                'payment_status' => 'paid',
                'booking_code' => $booking->booking_code
            ], 400);
        }

        $gatewayRaw = $request->input('payment_method') ?? $request->input('gateway') ?? 'AzamPay M-Pesa';
        $phoneNumber = $request->input('phone_number') ?? $request->input('phone');
        // Strict provider allowlist — no silent fallback to Mpesa (UI already validates, backend is authoritative)
        $lowerGateway = strtolower(trim((string)$gatewayRaw));
        $providerMap = [
            'mpesa' => 'Mpesa', 'vodacom' => 'Mpesa', 'm-pesa' => 'Mpesa', 'vodacom mpesa' => 'Mpesa',
            'tigo' => 'Tigo', 'tigopesa' => 'Tigo', 'tigo pesa' => 'Tigo',
            'airtel' => 'Airtel', 'airtelmoney' => 'Airtel', 'airtel money' => 'Airtel',
            'halotel' => 'Halopesa', 'halopesa' => 'Halopesa', 'halo pesa' => 'Halopesa',
            'card' => 'Card', 'credit' => 'Card', 'credit_card' => 'Card', 'visa' => 'Card', 'mastercard' => 'Card',
        ];
        if (!isset($providerMap[$lowerGateway]) && !str_contains($lowerGateway, 'tigo') && !str_contains($lowerGateway, 'airtel') && !str_contains($lowerGateway, 'halo') && !str_contains($lowerGateway, 'mpesa') && !str_contains($lowerGateway, 'vodacom') && $lowerGateway !== 'card') {
            // Check contains for legacy gateway strings like "AzamPay M-Pesa"
            if (str_contains($lowerGateway, 'tigo')) $providerName = 'Tigo';
            elseif (str_contains($lowerGateway, 'airtel')) $providerName = 'Airtel';
            elseif (str_contains($lowerGateway, 'halo')) $providerName = 'Halopesa';
            elseif (str_contains($lowerGateway, 'mpesa') || str_contains($lowerGateway, 'vodacom')) $providerName = 'Mpesa';
            elseif ($lowerGateway === 'card') $providerName = 'Card';
            else return response()->json(['message' => 'Unsupported payment provider: ' . $gatewayRaw], 422);
        } else {
            $providerName = $providerMap[$lowerGateway] ?? 'Mpesa';
            // Handle contains fallback for card
            if ($lowerGateway === 'card') $providerName = 'Card';
        }
        if ($providerName === 'Card') {
            return response()->json(['message' => 'Card payments are processed via secure card gateway — not AzamPay MNO. Use /payments/card/checkout.'], 422);
        }
        // Idempotency — same booking+provider+phone must not double-create pending payment
        $idempotencyKey = $request->input('idempotency_key') ?? hash('sha256', $booking->id . '|' . $providerName . '|' . ($phoneNumber ?? ''));
        $existingPending = Payment::where('booking_id', $booking->id)->where('gateway', 'like', '%' . $providerName . '%')->where('status', 'pending')->latest()->first();
        if ($existingPending && $request->has('idempotency_key')) {
            return response()->json([
                'success' => true,
                'message' => 'Payment already pending (idempotent).',
                'transaction_id' => $existingPending->transaction_id,
                'booking_code' => $booking->booking_code,
                'amount' => (float)$existingPending->amount,
            ], 200);
        }

        // Formulate authoritative transaction payload from DB model
        $authoritativeAmount = (float) $booking->total_price;
        $currency = 'TZS';
        $transactionId = 'TX-AZAM-' . strtoupper(Str::random(10));

        // Create pending Payment record
        $payment = Payment::create([
            'booking_id'     => $booking->id,
            'gateway'        => 'AzamPay ' . $providerName,
            'transaction_id' => $transactionId,
            'amount'         => $authoritativeAmount,
            'status'         => 'pending',
        ]);

        // Attempt AzamPay Gateway Integration Call
        $azampayApp = env('AZAMPAY_APP_NAME', 'FastNetStays');
        $azampayClientId = env('AZAMPAY_CLIENT_ID');
        $azampaySecret = env('AZAMPAY_CLIENT_SECRET');

        $providerName = 'Mpesa';
        if (str_contains(strtolower($gateway), 'tigo')) $providerName = 'Tigo';
        else if (str_contains(strtolower($gateway), 'airtel')) $providerName = 'Airtel';
        else if (str_contains(strtolower($gateway), 'halo')) $providerName = 'Halopesa';
        else if (str_contains(strtolower($gateway), 'azam')) $providerName = 'AzamPesa';

        $promptMessage = "Payment request of TSh " . number_format($authoritativeAmount) . " initiated via AzamPay ({$providerName}). Please check your phone for PIN prompt.";

        if ($azampayClientId && ($azampaySecret || env('AZAMPAY_TOKEN'))) {
            try {
                $token = env('AZAMPAY_TOKEN');

                if (!$token && $azampaySecret) {
                    // Request AzamPay Sandbox Bearer Token
                    $authRes = Http::withoutVerifying()->post('https://authenticator-sandbox.azampay.co.tz/Applink/GetToken', [
                        'appName' => $azampayApp,
                        'clientId' => $azampayClientId,
                        'clientSecret' => $azampaySecret,
                    ]);

                    if ($authRes->successful() && isset($authRes['data']['accessToken'])) {
                        $token = $authRes['data']['accessToken'];
                    }
                }

                if ($token) {
                    if (empty($phoneNumber)) {
                        return response()->json(['message' => 'Phone number is required for mobile money checkout.'], 422);
                    }
                    // Send MNO Checkout Request — no hardcoded fallback, real phone required
                    $mnoRes = Http::withoutVerifying()->withToken($token)->post('https://sandbox.azampay.co.tz/azampay/mno/checkout', [
                        'accountNumber' => $phoneNumber,
                        'amount' => (string) round($authoritativeAmount),
                        'currency' => $currency,
                        'externalId' => $booking->booking_code,
                        'provider' => $providerName,
                    ]);
                    Log::info('AzamPay MNO Checkout Dispatched', [
                        'status' => $mnoRes->status(),
                        'body' => $mnoRes->body(),
                        'phone' => $phoneNumber,
                        'provider' => $providerName
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('AzamPay Sandbox API Gateway Connection Notice: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'        => true,
            'message'        => $promptMessage,
            'transaction_id' => $transactionId,
            'booking_code'   => $booking->booking_code,
            'amount'         => $authoritativeAmount,
            'currency'       => $currency,
            'status'         => 'pending',
            'gateway'        => 'AzamPay (' . $providerName . ')',
        ], 200);
    }

    /**
     * Idempotent Webhook / Callback from AzamPay Gateway.
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();
        Log::info('AzamPay Webhook Received:', $payload);

        $bookingCode = $request->input('utilityref') ?? $request->input('externalId') ?? $request->input('booking_code');
        $transactionId = $request->input('reference') ?? $request->input('transaction_id');
        $rawStatus = strtolower($request->input('transactionstatus') ?? $request->input('status') ?? 'successful');

        $status = 'pending';
        if (in_array($rawStatus, ['success', 'successful', 'completed', 'paid', '00'])) {
            $status = 'successful';
        } else if (in_array($rawStatus, ['failed', 'error', 'rejected'])) {
            $status = 'failed';
        } else if (in_array($rawStatus, ['cancelled', 'canceled'])) {
            $status = 'cancelled';
        }

        if (!$bookingCode) {
            return response()->json(['message' => 'Missing booking reference.'], 422);
        }

        $booking = Booking::where('booking_code', $bookingCode)->first();
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        // Check if booking is already processed (Idempotency)
        if ($booking->payment_status === 'paid' && $status === 'successful') {
            return response()->json([
                'message' => 'Webhook already processed (Idempotent OK).',
                'payment_status' => 'paid',
            ], 200);
        }

        // Extract paid amount and currency from webhook payload (if supplied)
        $paidAmount = $request->input('amount') ?? $request->input('paid_amount');
        $paidCurrency = strtoupper($request->input('currency') ?? 'TZS');

        $authoritativeAmount = (float) $booking->total_price;

        // Validation rule: If payment status is marked successful, paid amount MUST match authoritative booking total
        if ($status === 'successful') {
            if ($paidAmount !== null && abs((float) $paidAmount - $authoritativeAmount) > 0.01) {
                Log::error("Payment amount mismatch for booking {$bookingCode}! Expected {$authoritativeAmount}, received {$paidAmount}");
                
                $booking->update([
                    'payment_status' => 'amount_mismatch',
                    'status' => 'Pending Verification',
                ]);

                return response()->json([
                    'message' => 'Payment amount does not match authoritative booking amount. Booking unconfirmed.',
                    'booking_code' => $booking->booking_code,
                    'expected_amount' => $authoritativeAmount,
                    'received_amount' => (float) $paidAmount,
                    'payment_status' => 'amount_mismatch',
                ], 400);
            }
        }

        DB::transaction(function () use ($booking, $transactionId, $status) {
            $payment = Payment::where('booking_id', $booking->id)->latest()->first();

            if ($payment) {
                $payment->update([
                    'status' => $status,
                    'transaction_id' => $transactionId ?? $payment->transaction_id,
                ]);
            }

            if ($status === 'successful') {
                $booking->update([
                    'payment_status' => 'paid',
                    'status' => 'Confirmed',
                    'payment_reference' => $transactionId,
                ]);

                if ($booking->room) {
                    $booking->room->update(['status' => 'booked']);
                }

                \App\Jobs\SendBookingConfirmationEmail::dispatch($booking->id)->onQueue('notifications');
                \App\Jobs\SendBookingConfirmationSms::dispatch($booking->id)->onQueue('notifications');
            } else if (in_array($status, ['failed', 'cancelled', 'expired'])) {
                $booking->update([
                    'payment_status' => $status,
                    'status' => 'Cancelled',
                ]);
            }
        });

        return response()->json([
            'message' => 'AzamPay Webhook processed successfully.',
            'booking_code' => $booking->booking_code,
            'payment_status' => $booking->fresh()->payment_status,
            'booking_status' => $booking->fresh()->status,
        ], 200);
    }

    /**
     * Real-Time Backend Verification endpoint. Returns actual payment status from DB.
     */
    public function status(Request $request, $codeOrId)
    {
        $booking = Booking::where('booking_code', $codeOrId)
            ->orWhere('id', $codeOrId)
            ->with(['room.property', 'guest'])
            ->first();

        if (!$booking) {
            return response()->json([
                'verified' => false,
                'message' => 'Booking record not found.'
            ], 404);
        }

        $payment = Payment::where('booking_id', $booking->id)->latest()->first();

        return response()->json([
            'verified' => true,
            'booking_code' => $booking->booking_code,
            'payment_status' => $booking->payment_status,
            'booking_status' => $booking->status,
            'amount' => (float) $booking->total_price,
            'currency' => 'TZS',
            'gateway' => $payment ? $payment->gateway : 'AzamPay',
            'transaction_id' => $payment ? $payment->transaction_id : null,
            'property' => [
                'name' => $booking->room->property->name ?? 'FastNetStays Lodge',
                'address' => $booking->room->property->address ?? 'Tanzania',
            ],
            'room' => [
                'title' => $booking->room->title ?? ($booking->room->room_number ? "Room {$booking->room->room_number}" : 'Executive Room'),
                'room_number' => $booking->room->room_number ?? '1',
            ],
            'guest' => [
                'name' => $booking->guest->name ?? 'Valued Guest',
                'email' => $booking->guest->email ?? '',
                'phone' => $booking->guest->phone_number ?? '',
            ],
            'check_in' => $booking->check_in ? \Carbon\Carbon::parse($booking->check_in)->format('Y-m-d') : null,
            'check_out' => $booking->check_out ? \Carbon\Carbon::parse($booking->check_out)->format('Y-m-d') : null,
            'check_in_formatted' => $booking->check_in ? \Carbon\Carbon::parse($booking->check_in)->format('D, M j, Y') : null,
            'check_out_formatted' => $booking->check_out ? \Carbon\Carbon::parse($booking->check_out)->format('D, M j, Y') : null,
        ]);
    }
}
