<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    /**
     * Get all users.
     */
    public function users(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $users = User::orderBy('created_at', 'desc')->get();
        return response()->json($users);
    }

    /**
     * Update user status.
     */
    public function updateUserStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Active,Suspended,Pending Verification',
        ]);

        $user = User::findOrFail($id);
        $user->update(['status' => $request->status]);

        return response()->json($user);
    }

    /**
     * Add a user.
     */
    public function addUser(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:4',
            'phone_number' => 'nullable|string',
            'role' => 'required|string|in:customer,owner,admin',
            'status' => 'nullable|string|in:Active,Suspended,Pending Verification',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone_number' => $request->phone_number,
            'role' => $request->role,
            'status' => $request->status ?? 'Active',
        ]);

        return response()->json($user, 201);
    }

    /**
     * Get all properties (listings).
     */
    public function properties(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $properties = Property::with(['host', 'rooms'])->orderBy('created_at', 'desc')->get();
        return response()->json($properties);
    }

    /**
     * Update property status.
     */
    public function updatePropertyStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Active,Pending,Removed',
        ]);

        $property = Property::findOrFail($id);
        $property->update(['status' => $request->status]);

        return response()->json($property);
    }
}
