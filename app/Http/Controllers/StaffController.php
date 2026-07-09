<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    /**
     * Get staff list for the host.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Admin can see all staff; hosts see their own staff
        if ($user->role === 'admin') {
            $staff = Staff::orderBy('created_at', 'desc')->get();
        } elseif ($user->role === 'owner') {
            $staff = Staff::where('host_id', $user->id)->orderBy('created_at', 'desc')->get();
        } else {
            return response()->json(['message' => 'Unauthorized. Only hosts or admins can view staff.'], 403);
        }

        return response()->json($staff);
    }

    /**
     * Invite/Add new staff member.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'owner' && $user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Only hosts or admins can add staff.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|string|in:Housekeeper,Receptionist,Maintenance,Manager',
            'phone' => 'required|string',
            'status' => 'nullable|string|in:On-Duty,Off-Duty',
            'room' => 'nullable|string',
        ]);

        $staff = Staff::create([
            'name' => $request->name,
            'role' => $request->role,
            'phone' => $request->phone,
            'status' => $request->status ?? 'Off-Duty',
            'room' => $request->room ?? 'None',
            'host_id' => $user->id,
        ]);

        return response()->json($staff, 201);
    }

    /**
     * Update staff details, status, or task assignments.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $staff = Staff::findOrFail($id);

        if ($user->role !== 'admin' && $staff->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized. Can only manage own staff.'], 403);
        }

        $request->validate([
            'name' => 'nullable|string|max:255',
            'role' => 'nullable|string|in:Housekeeper,Receptionist,Maintenance,Manager',
            'phone' => 'nullable|string',
            'status' => 'nullable|string|in:On-Duty,Off-Duty',
            'room' => 'nullable|string',
        ]);

        $staff->update($request->only(['name', 'role', 'phone', 'status', 'room']));

        return response()->json($staff);
    }

    /**
     * Remove staff member.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $staff = Staff::findOrFail($id);

        if ($user->role !== 'admin' && $staff->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized. Can only manage own staff.'], 403);
        }

        $staff->delete();

        return response()->json(['message' => 'Staff member deleted successfully.']);
    }
}
