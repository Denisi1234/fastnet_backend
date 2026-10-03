<?php

namespace App\Http\Controllers;

use App\Models\LoginOtpCode;
use App\Models\User;
use App\Services\NextSmsService;
use App\Services\ResendMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless "sign in with a code" — the guest sign-in flow.
 *
 * The pre-existing `/api/verify-otp` cannot back a sign-in: it re-checks an
 * email+code pair and returns no token at all, so a client that skipped it lost
 * nothing. This controller mints a real Sanctum token once a code is proven.
 *
 * Design rules:
 *  - The account is created only AFTER the code is verified, so nobody can
 *    squat an email address or phone number they do not control.
 *  - `request` answers identically whether or not the contact has an account,
 *    so it cannot be used to discover who is registered.
 *  - Codes are stored hashed, expire in 10 minutes, accept 5 wrong guesses,
 *    and are single-use.
 */
class LoginOtpController extends Controller
{
    /** How long a code stays usable. */
    private const CODE_TTL_MINUTES = 10;

    /** Wrong guesses allowed per code before it is burned. */
    private const MAX_ATTEMPTS = 5;

    /** Codes allowed per contact per hour, to stop SMS/email bombardment. */
    private const MAX_REQUESTS_PER_HOUR = 5;

    /** Seconds a client must wait before asking for another code. */
    private const RESEND_COOLDOWN_SECONDS = 45;

    /**
     * POST /api/login/otp/request
     *
     * Starts the challenge. Always answers 200 with the same body so the
     * endpoint is not an account-existence oracle.
     */
    public function request(Request $request)
    {
        $contact = $this->normalizeContact($request->input('contact', $request->input('email', $request->input('phone'))));

        if ($contact === null) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a valid email address or Tanzanian mobile number.',
            ], 422);
        }

        if ($this->tooManyRequests($contact['value'])) {
            return response()->json([
                'success' => false,
                'message' => 'Too many codes requested. Please try again later.',
            ], 429);
        }

        $cooldown = $this->resendCooldownRemaining($contact['value']);
        if ($cooldown > 0) {
            return response()->json([
                'success' => false,
                'retry_after' => $cooldown,
                'message' => "Please wait {$cooldown} seconds before requesting another code.",
            ], 429);
        }

        $user = $this->findUser($contact);

        // A contact with no account is still allowed to request a code; the
        // user row is created in verify(), once the contact is proven.
        $channel = $this->pickChannel($contact, $user);
        $code = (string) random_int(100000, 999999);

        // Burn any earlier live code for this contact so only one can win.
        LoginOtpCode::where('contact', $contact['value'])
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        LoginOtpCode::create([
            'user_id'    => $user?->id,
            'contact'    => $contact['value'],
            'channel'    => $channel,
            'token_hash' => LoginOtpCode::hashCode($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $delivered = $this->deliver($channel, $contact, $user, $code);

        Log::info("Login OTP requested for contact={$contact['value']} channel={$channel} delivered=" . ($delivered ? 'yes' : 'no'));

        $payload = [
            'success'      => true,
            'message'      => 'If that contact can receive a code, it is on its way.',
            'channel'      => $channel,
            'expires_in'   => self::CODE_TTL_MINUTES * 60,
            'retry_after'  => self::RESEND_COOLDOWN_SECONDS,
        ];

        // Local/dev convenience only. Never leaks when APP_DEBUG is off, which
        // is the case in production.
        if (config('app.debug')) {
            $payload['debug_code'] = $code;
        }

        return response()->json($payload);
    }

    /**
     * POST /api/login/otp/verify
     *
     * Exchanges a correct code for a real access token.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'contact' => 'required|string',
            'code'    => 'required|string|size:6',
        ], [
            'code.size'    => 'Enter the 6-digit code we sent you.',
            'code.required' => 'Enter the 6-digit code we sent you.',
        ]);

        $contact = $this->normalizeContact($validated['contact']);
        $code    = trim($validated['code']);

        if ($contact === null) {
            return $this->invalidCode();
        }

        $record = LoginOtpCode::where('contact', $contact['value'])
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            return $this->invalidCode();
        }

        if (! $record->codeMatches($code)) {
            $record->increment('attempts');

            Log::warning("Login OTP wrong code for contact={$contact['value']}");

            // increment() has already bumped the stored count, so compare the
            // new value directly and burn the code once the budget is gone.
            if ($record->attempts >= self::MAX_ATTEMPTS) {
                $record->update(['consumed_at' => now()]);
            }

            return $this->invalidCode();
        }

        if (! $record->isUsable()) {
            $record->update(['consumed_at' => now()]);

            return response()->json([
                'success' => false,
                'message' => 'That code has expired. Please request a new one.',
            ], 422);
        }

        $record->update(['consumed_at' => now()]);

        $user = $this->findUser($contact);

        // Proven control of the contact: safe to create the account now.
        if ($user === null) {
            $user = $this->createUserFor($contact);
        }

        if ($user->status !== null && strtolower((string) $user->status) !== 'active') {
            Log::warning("Login OTP blocked for suspended user {$user->id}");

            return response()->json([
                'success' => false,
                'message' => 'This account is not active. Please contact support.',
            ], 403);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return response()->json([
            'success'      => true,
            'message'      => 'Signed in successfully.',
            'access_token' => $user->createToken('auth_token')->plainTextToken,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ]);
    }

    /**
     * POST /api/login/otp/resend
     *
     * Same work as request(), kept as its own path so the client can label the
     * button honestly.
     */
    public function resend(Request $request)
    {
        return $this->request($request);
    }

    /**
     * Normalise an email address or a Tanzanian mobile number to a stable key.
     *
     * Returns null when the input is neither, so callers can reject it.
     */
    private function normalizeContact(mixed $raw): ?array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [
                'type'  => 'email',
                'value' => strtolower($raw),
                'email' => strtolower($raw),
                'phone' => null,
            ];
        }

        $phone = $this->normalizeTzPhone($raw);
        if ($phone === null) {
            return null;
        }

        return [
            'type'  => 'phone',
            'value' => $phone,
            'email' => null,
            'phone' => $phone,
        ];
    }

    /**
     * Tanzanian mobile numbers to E.164 (255XXXXXXXXX).
     *
     * Accepts 0755…, +255755…, 255755… and 755…, and rejects anything that is
     * not a 9-digit national number starting 6 or 7.
     */
    private function normalizeTzPhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '255')) {
            $national = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        if (! preg_match('/^[67]\d{8}$/', $national)) {
            return null;
        }

        return '255' . $national;
    }

    private function findUser(array $contact): ?User
    {
        if ($contact['type'] === 'email') {
            return User::where('email', $contact['value'])->first();
        }

        return User::where('phone_number', $contact['value'])->first()
            // Tolerate numbers stored in local format by earlier signups.
            ?? User::where('phone_number', '0' . substr($contact['value'], 3))->first()
            ?? User::where('phone_number', '+' . $contact['value'])->first();
    }

    /**
     * SMS when we have a phone number that can receive one, email otherwise.
     */
    private function pickChannel(array $contact, ?User $user): string
    {
        $phone = $contact['phone'] ?? $user?->phone_number;

        if (! empty($phone)) {
            return 'sms';
        }

        return 'email';
    }

    private function deliver(string $channel, array $contact, ?User $user, string $code): bool
    {
        try {
            if ($channel === 'sms') {
                $phone = $contact['phone'] ?? $user?->phone_number;
                if (empty($phone)) {
                    return false;
                }

                return NextSmsService::sendSms(
                    $phone,
                    "{$code} is your FastNet Stays sign-in code. It expires in " . self::CODE_TTL_MINUTES . " minutes."
                );
            }

            $email = $contact['email'] ?? $user?->email;
            if (empty($email)) {
                return false;
            }

            return ResendMailService::sendOtpEmail($email, $code, 'Sign In');
        } catch (\Throwable $e) {
            Log::error("Login OTP delivery failed for contact={$contact['value']}: " . $e->getMessage());

            return false;
        }
    }

    private function createUserFor(array $contact): User
    {
        // 'status' is deliberately not set: it is not mass-assignable and the
        // column already defaults to 'Active'.
        if ($contact['type'] === 'email') {
            $name = ucfirst(strtok($contact['email'], '@'));

            return User::create([
                'name'     => $name,
                'email'    => $contact['email'],
                // Random and unguessable: this account signs in by code only.
                'password' => Hash::make(Str::random(40)),
                'role'     => 'customer',
            ]);
        }

        return User::create([
            'name'         => 'Guest ' . substr($contact['phone'], -4),
            'email'        => 'user_' . $contact['phone'] . '@users.fastnetstays.com',
            'phone_number' => $contact['phone'],
            'password'     => Hash::make(Str::random(40)),
            'role'         => 'customer',
        ]);
    }

    private function tooManyRequests(string $contact): bool
    {
        return LoginOtpCode::where('contact', $contact)
            ->where('created_at', '>=', now()->subHour())
            ->count() >= self::MAX_REQUESTS_PER_HOUR;
    }

    private function resendCooldownRemaining(string $contact): int
    {
        $last = LoginOtpCode::where('contact', $contact)
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            return 0;
        }

        // Carbon 3 returns a NEGATIVE value here (the target is in the past), so
        // this must be abs()'d. Without it, an hour-old code yields
        // 45 - (-3600) and the resend button locks for over an hour.
        $elapsed = abs((int) now()->diffInSeconds($last->created_at, absolute: false));

        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    private function invalidCode()
    {
        return response()->json([
            'success' => false,
            'message' => 'That code is not correct. Please check it and try again.',
        ], 422);
    }
}