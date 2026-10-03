<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
            'phone_number' => 'nullable|string|max:50',
            'role' => 'nullable|string|in:customer,owner',
        ]);

        $phone = $request->phone_number !== null ? trim((string) $request->phone_number) : '';
        $emailTaken = User::where('email', $request->email)->exists();

        // A phone number must resolve to exactly one account: sign-in accepts a
        // phone number, and the OTP lookup in LoginOtpController does
        // where(phone_number)->first(). Two accounts sharing a number would make
        // either lookup return an arbitrary account, so a duplicate number is
        // rejected rather than stored.
        $phoneTaken = $phone !== '' && User::where('phone_number', $phone)->exists();

        if ($emailTaken) {
            return response()->json([
                'message' => 'An account already exists with this email address. Sign in instead, or register with a different email.',
                'errors' => [
                    'email' => ['An account already exists with this email address. Sign in instead, or register with a different email.']
                ]
            ], 422);
        }

        if ($phoneTaken) {
            // Worth distinguishing from the email case: someone may legitimately
            // want a second, separate account and only the number overlaps.
            return response()->json([
                'message' => 'That phone number is already linked to an account. Use a different number, or leave it blank if it is optional.',
                'errors' => [
                    'phone_number' => ['That phone number is already linked to an account. Use a different number, or leave it blank if it is optional.']
                ]
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone_number' => $phone !== '' ? $phone : null,
            // 'admin' is NOT accepted from the request body. It used to be, which
            // meant anyone could self-register as an administrator. Promotion to
            // admin happens server-side via /admin endpoints only.
            'role' => $request->role === 'owner' ? 'owner' : 'customer',
        ]);

        try {
            \App\Services\ResendMailService::sendWelcomeEmail($user);
        } catch (\Throwable $e) {
            Log::warning("Welcome email failed to send: " . $e->getMessage());
        }

        try {
            $token = $user->createToken('auth_token')->plainTextToken;
        } catch (\Throwable $e) {
            $token = 'auth_token_' . bin2hex(random_bytes(24));
        }

        return response()->json([
            'message' => 'Account created successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        Log::info("AUTH DEBUG 1 — REQUEST RECEIVED", ['ip' => $request->ip(), 'agent' => $request->userAgent()]);
        
        Log::info("AUTH DEBUG 2 — VALIDATION START");
        $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);
        Log::info("AUTH DEBUG 3 — VALIDATION COMPLETE");

        $login = trim($request->email);
        Log::info("AUTH DEBUG 4 — USER DATABASE LOOKUP START", ['login' => $login]);
        $user = User::where('email', $login)
                    ->orWhere('phone_number', $login)
                    ->orWhere('phone_number', preg_replace('/[^0-9+]/', '', $login))
                    ->first();
        Log::info("AUTH DEBUG 5 — USER DATABASE LOOKUP COMPLETE", ['found' => (bool)$user]);

        Log::info("AUTH DEBUG 6 — PASSWORD VERIFICATION START");
        if (!$user || !Hash::check($request->password, $user->password)) {
            Log::warning("AUTH DEBUG 7 — PASSWORD VERIFICATION FAILED (Invalid credentials)");
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }
        Log::info("AUTH DEBUG 7 — PASSWORD VERIFICATION COMPLETE");

        // A suspended account must not be able to mint a fresh token.
        if ($user->status !== null && strtolower((string) $user->status) !== 'active') {
            Log::warning("AUTH BLOCKED — account {$user->id} status={$user->status}");

            return response()->json([
                'message' => 'This account is not active. Please contact support.',
            ], 403);
        }

        Log::info("AUTH DEBUG 8 — AUTHENTICATION/TOKEN OR SESSION CREATION START");
        $token = $user->createToken('auth_token')->plainTextToken;
        Log::info("AUTH DEBUG 9 — AUTHENTICATION/TOKEN OR SESSION CREATION COMPLETE");

        // Send NextSMS notification non-blocking if user has a phone number
        if (!empty($user->phone_number)) {
            try {
                $smsMessage = "Hi {$user->name}, a new login was detected on your FastNetStays account.";
                \App\Services\NextSmsService::sendSms($user->phone_number, $smsMessage);
            } catch (\Throwable $e) {
                Log::warning("SMS notification failed: " . $e->getMessage());
            }
        }

        Log::info("AUTH DEBUG 10 — RESPONSE RETURNING");
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    /**
     * Passwordless sign-in entry point.
     *
     * Security history: this issued a Sanctum token for ANY matched user with
     * no proof of ownership, created accounts with the hardcoded password
     * "fastnet123456", and (until hardened) signed brand-new contacts straight
     * in with zero proof they controlled the address or number — letting
     * anyone mint accounts for addresses they don't own, which then collide
     * in login's orWhere(phone) lookup.
     *
     * Now it never creates an account and never issues a token. Every contact
     * — known or new — must prove control via the OTP flow; LoginOtpController
     * creates the account only after the code verifies.
     */
    public function easyAuth(Request $request)
    {
        $request->validate([
            'login' => 'required|string|min:3',
        ]);

        $login = trim($request->login);
        $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL);

        if ($isEmail) {
            $user = User::where('email', strtolower($login))->first();
        } else {
            // Exact match only. This used to also try a LIKE '%last8digits%'
            // fallback, which both mis-identified real users and turned the
            // endpoint into a fuzzy "does this number exist" oracle.
            $cleanPhone = preg_replace('/[^0-9+]/', '', $login);
            $user = User::where('phone_number', $cleanPhone)->first();
        }

        if ($user) {
            Log::info("easyAuth challenge issued for existing account {$user->id}.");
        } else {
            Log::info("easyAuth challenge issued for new contact.");
        }

        return response()->json([
            'message' => 'Verify this contact to continue.',
            'verification_required' => true,
            'resend_endpoint' => '/api/login/otp/request',
            'verify_endpoint' => '/api/login/otp/verify',
        ], 202);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Retired: a customer can no longer be promoted to owner in place.
     *
     * The product rule is that a guest account (bookings, stays) is a different
     * thing from a host account (listings, payouts), and someone who wants to
     * host must register a separate host account with its own sign-in. This
     * endpoint used to let any signed-in customer become owner with one call,
     * which silently merged the two identities and made the guest's booking
     * history and the host's payout account the same record.
     *
     * It is kept as a 410 rather than deleted so a stale mobile build gets a
     * clear, actionable answer instead of a bare 404. Nothing in this repo
     * calls it any more.
     */
    public function becomeHost(Request $request)
    {
        return response()->json([
            'message' => 'A guest account cannot be upgraded to a host account. '
                .'Please register a separate host account and sign in to it.',
            'register_endpoint' => '/api/register',
        ], 410);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone_number' => 'nullable|string',
            'date_of_birth' => 'nullable|string',
            'gender' => 'nullable|string',
            'bio' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact' => 'nullable|string',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    public function uploadProfilePhoto(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $user = $request->user();

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'profile_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('profiles', $filename, 'public');
            $url = asset('storage/' . $path);

            $user->update([
                'profile_photo_url' => $url,
            ]);

            return response()->json([
                'photo_url' => $url,
            ]);
        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);
        $user = $request->user();
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }
        $user->update(['password' => Hash::make($request->new_password)]);
        // revoke other tokens
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json(['message' => 'Password updated successfully.', 'access_token' => $token]);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['message' => 'Account deleted successfully.']);
    }
}
