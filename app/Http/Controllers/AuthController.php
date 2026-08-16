<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Mail\WelcomeUserMail;
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
            'phone_number' => 'nullable|string',
            'role' => 'nullable|string|in:customer,owner,admin',
        ]);

        $existing = User::where('email', $request->email)
            ->when(!empty($request->phone_number), function ($q) use ($request) {
                return $q->orWhere('phone_number', $request->phone_number);
            })
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'An account with this email or phone number already exists. Please sign in instead.',
                'errors' => [
                    'email' => ['An account with this email or phone number already exists. Please sign in instead.']
                ]
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone_number' => $request->phone_number,
            'role' => $request->role ?? 'customer',
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

    public function easyAuth(Request $request)
    {
        $request->validate([
            'login' => 'required|string|min:3',
        ]);

        $login = trim($request->login);
        $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL);
        $isNewUser = false;

        if ($isEmail) {
            $user = User::where('email', $login)->first();
            if (!$user) {
                $name = explode('@', $login)[0];
                $user = User::create([
                    'name' => ucfirst($name),
                    'email' => $login,
                    'password' => Hash::make('fastnet123456'),
                    'role' => 'customer',
                ]);
                $isNewUser = true;
            }
        } else {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $login);
            $user = User::where('phone_number', $cleanPhone)
                        ->orWhere('phone_number', 'LIKE', "%" . substr($cleanPhone, -8) . "%")
                        ->first();
            if (!$user) {
                $dummyEmail = 'user_' . preg_replace('/[^0-9]/', '', $cleanPhone) . '@fastnetstays.com';
                $user = User::create([
                    'name' => 'Guest ' . substr($cleanPhone, -4),
                    'email' => $dummyEmail,
                    'phone_number' => $cleanPhone,
                    'password' => Hash::make('fastnet123456'),
                    'role' => 'customer',
                ]);
                $isNewUser = true;
            }
        }

        if ($isNewUser) {
            try {
                \App\Services\ResendMailService::sendWelcomeEmail($user);
            } catch (\Throwable $e) {
                Log::warning("Welcome email failed: " . $e->getMessage());
            }
        } else {
            // Send NextSMS notification for existing users logging in
            if (!empty($user->phone_number)) {
                try {
                    $smsMessage = "Hi {$user->name}, a new login was detected on your FastNetStays account.";
                    \App\Services\NextSmsService::sendSms($user->phone_number, $smsMessage);
                } catch (\Throwable $e) {
                    Log::warning("SMS alert failed: " . $e->getMessage());
                }
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Signed in successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
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
}
