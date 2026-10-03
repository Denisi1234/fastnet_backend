<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class PersonalDetailsController extends Controller
{
    /**
     * Get user personal details.
     */
    public function getDetails(Request $request)
    {
        $user = $request->user('sanctum') ?? $request->user();

        // Previously an unauthenticated caller could pass ?user_id=N and read
        // any user's full profile (name, email, phone, address, DOB). Scope
        // strictly to the authenticated user.
        if (! $user) {
            return response()->json([
                'status' => 'unauthenticated',
                'message' => 'Authentication required.',
                'details' => null,
            ], 401);
        }

        if ($user) {
            $nameParts = explode(' ', trim((string)$user->name), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $details = [
                'id' => $user->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $user->name,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number ?? '',
                'phone' => $user->phone_number ?? '',
                'date_of_birth' => $user->date_of_birth ?? '',
                'gender' => $user->gender ?? 'Not set',
                'address' => $user->address ?? '',
                'emergency_contact' => $user->emergency_contact ?? '',
                'bio' => $user->bio ?? '',
                'is_verified' => !empty($user->email_verified_at),
                'avatar_url' => $user->profile_photo_url ?? '',
                'avatar' => $user->profile_photo_url ?? '',
                'role' => $user->role ?? 'traveler',
            ];

            return response()->json([
                'status' => 'success',
                'user_id' => $user->id,
                'details' => $details,
                'updated_at' => $user->updated_at ? $user->updated_at->toIso8601String() : now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Update user personal details.
     */
    public function updateDetails(Request $request)
    {
        $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'full_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|string|max:50',
            'gender' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:1000',
            'avatar' => 'nullable|string|max:255',
            'avatar_bg' => 'nullable|string|max:50',
            'avatar_color' => 'nullable|string|max:50',
        ]);

        $user = $request->user('sanctum') ?? $request->user();

        // A caller may only ever write to their own account. This previously
        // accepted an arbitrary user_id with no authentication, so anyone
        // could overwrite any user's name, email, phone and address.
        if (! $user) {
            return response()->json([
                'message' => 'Authentication required to update personal details.',
            ], 401);
        }

        $firstName = trim((string)$request->input('first_name', ''));
        $lastName = trim((string)$request->input('last_name', ''));
        $fullName = trim((string)($request->input('full_name') ?? $request->input('name') ?? ''));

        if ($fullName === '' && ($firstName !== '' || $lastName !== '')) {
            $fullName = trim("{$firstName} {$lastName}");
        } elseif ($fullName !== '' && $firstName === '' && $lastName === '') {
            $parts = explode(' ', $fullName, 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        }

        $email = trim((string)$request->input('email', ''));
        $phone = trim((string)($request->input('phone_number') ?? $request->input('phone') ?? ''));
        $dob = trim((string)$request->input('date_of_birth', ''));
        $gender = trim((string)$request->input('gender', ''));
        $address = trim((string)$request->input('address', ''));
        $emergency = trim((string)$request->input('emergency_contact', ''));
        $bio = trim((string)$request->input('bio', ''));

        if ($user) {
            if ($fullName !== '') $user->name = $fullName;
            if ($email !== '') $user->email = $email;
            if ($phone !== '') $user->phone_number = $phone;
            if ($dob !== '') $user->date_of_birth = $dob;
            if ($gender !== '') $user->gender = $gender;
            if ($address !== '') $user->address = $address;
            if ($emergency !== '') $user->emergency_contact = $emergency;
            if ($bio !== '') $user->bio = $bio;
            $user->save();

            $nameParts = explode(' ', trim((string)$user->name), 2);
            $updated = [
                'id' => $user->id,
                'first_name' => $nameParts[0] ?? '',
                'last_name' => $nameParts[1] ?? '',
                'full_name' => $user->name,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number ?? '',
                'phone' => $user->phone_number ?? '',
                'date_of_birth' => $user->date_of_birth ?? '',
                'gender' => $user->gender ?? 'Not set',
                'address' => $user->address ?? '',
                'emergency_contact' => $user->emergency_contact ?? '',
                'bio' => $user->bio ?? '',
                'is_verified' => !empty($user->email_verified_at),
                'avatar_url' => $user->profile_photo_url ?? '',
                'avatar' => $user->profile_photo_url ?? '',
                'role' => $user->role ?? 'traveler',
            ];

            Cache::put("user_personal_details_{$user->id}", $updated, now()->addDays(30));

            return response()->json([
                'status' => 'success',
                'message' => 'Personal details updated successfully.',
                'details' => $updated,
                'updated_at' => $user->updated_at ? $user->updated_at->toIso8601String() : now()->toIso8601String(),
            ]);
        }

    }
}
