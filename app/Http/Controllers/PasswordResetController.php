<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\ResendMailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $contact = $request->input('email') ?? $request->input('contact');

        if (!$contact) {
            return response()->json(['message' => 'Please provide an email address or phone number.'], 422);
        }

        // Determine if it's an email or phone number
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL);
        $email = $contact;

        if (!$isEmail) {
            $user = User::where('phone_number', $contact)->first();
            if ($user && $user->email) {
                $email = $user->email;
            } else {
                return response()->json(['message' => 'No account found with this phone number.'], 404);
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json(['message' => 'No account found with this email address.'], 404);
        }

        // Generate 6-digit secure numeric OTP code
        $token = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => $token, 'created_at' => Carbon::now()]
        );

        // Send the real email using ResendMailService
        try {
            $sent = ResendMailService::sendOtpEmail($email, $token, 'Password Reset');
            if (!$sent) {
                Log::warning("Resend failed to deliver OTP to {$email}, token stored in DB.");
            }
        } catch (\Throwable $e) {
            Log::error("Error sending OTP email to {$email}: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'A 6-digit verification code has been sent to your email address.',
            'email' => $email
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'token' => 'required|string|min:6|max:6',
        ], [
            'token.required' => 'Please enter the 6-digit verification code.',
            'token.min' => 'The verification code must be exactly 6 digits.',
            'token.max' => 'The verification code must be exactly 6 digits.',
        ]);

        $token = trim($request->input('token'));
        if (empty($token) || strlen($token) !== 6) {
            return response()->json(['message' => 'Please enter the 6-digit verification code.'], 422);
        }

        $contact = $request->input('email');
        $email = $contact;

        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('phone_number', $contact)->first();
            if ($user && $user->email) {
                $email = $user->email;
            }
        }

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid verification code. The code does not match.'], 422);
        }

        // Check if token is older than 10 minutes
        if (Carbon::parse($record->created_at)->addMinutes(10)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json(['message' => 'Verification code has expired. Please click "Resend code" to get a new one.'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification code confirmed successfully.',
            'email' => $email,
            'token' => $token
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'token' => 'required|string|min:6|max:6',
            'password' => 'required|min:8',
        ], [
            'token.required' => 'Verification code is required.',
            'token.min' => 'The verification code must be exactly 6 digits.',
            'password.min' => 'Password must be at least 8 characters long.',
        ]);

        $token = trim($request->input('token'));
        if (empty($token) || strlen($token) !== 6) {
            return response()->json(['message' => 'A valid 6-digit verification code is required.'], 422);
        }

        $contact = $request->input('email');
        $email = $contact;

        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('phone_number', $contact)->first();
            if ($user && $user->email) {
                $email = $user->email;
            }
        }

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(10)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json(['message' => 'Verification code has expired. Please request a new one.'], 422);
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        // Clean up the used reset token so it can never be reused
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully. You can now sign in with your new password.'
        ]);
    }
}
