<?php

namespace App\Http\Controllers;

use App\Models\SavedPaymentMethod;
use Illuminate\Http\Request;

/**
 * Saved mobile-money numbers for checkout.
 *
 * The payment-detail page already lists, adds and deletes these; the routes
 * did not exist, so every call failed. Field names match what that page
 * sends/reads ({type, phone, name} in, {id, type, label, name} out) rather
 * than inventing a new contract.
 */
class SavedPaymentMethodController extends Controller
{
    private const PROVIDERS = ['vodacom', 'tigo', 'airtel', 'halotel', 'mpesa'];

    public function index(Request $request)
    {
        return response()->json([
            'data' => SavedPaymentMethod::where('user_id', $request->user()->id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'nullable|string|max:32',
            'type'     => 'nullable|string|max:32',
            'phone'    => 'required|string|max:32',
            'phone_number' => 'nullable|string|max:32',
            'name'     => 'nullable|string|max:255',
            'label'    => 'nullable|string|max:64',
        ]);

        $provider = strtolower(trim((string)($validated['provider'] ?? $validated['type'] ?? '')));
        if (! in_array($provider, self::PROVIDERS, true)) {
            return response()->json([
                'message' => 'Unknown provider. Use one of: ' . implode(', ', self::PROVIDERS) . '.',
            ], 422);
        }

        $phone = preg_replace('/[^0-9+]/', '', (string)($validated['phone'] ?? $validated['phone_number'] ?? ''));
        if (strlen($phone) < 9) {
            return response()->json(['message' => 'Enter a valid mobile money number.'], 422);
        }

        $method = SavedPaymentMethod::firstOrCreate(
            [
                'user_id'      => $request->user()->id,
                'provider'     => $provider,
                'phone_number' => $phone,
            ],
            [
                'label' => trim((string)($validated['label'] ?? '')) ?: null,
                'name'  => trim((string)($validated['name'] ?? '')) ?: null,
            ]
        );

        return response()->json(['data' => $method], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $method = SavedPaymentMethod::where('user_id', $request->user()->id)->find($id);

        if (! $method) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $method->delete();

        return response()->json(['message' => 'Payment method deleted.']);
    }
}