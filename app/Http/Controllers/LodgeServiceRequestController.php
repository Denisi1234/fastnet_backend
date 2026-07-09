<?php

namespace App\Http\Controllers;

use App\Models\LodgeServiceRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use Illuminate\Http\Request;

class LodgeServiceRequestController extends Controller
{
    /**
     * Get service requests (guest sees their own; host sees bookings' requests; admin sees all).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $requests = LodgeServiceRequest::with('guest')->orderBy('created_at', 'desc')->get();
        } elseif ($user->role === 'owner') {
            $hostPropertyIds = Property::where('host_id', $user->id)->pluck('id');
            $hostRoomIds = Room::whereIn('property_id', $hostPropertyIds)->pluck('id');
            $guestIds = Booking::whereIn('room_id', $hostRoomIds)->pluck('guest_id')->unique();

            $requests = LodgeServiceRequest::whereIn('guest_id', $guestIds)
                ->with('guest')
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            // Customer
            $requests = LodgeServiceRequest::where('guest_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return response()->json($requests);
    }

    /**
     * Submit a new service request (food order, laundry, spa, etc.).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'room_number' => 'required|string',
            'type' => 'required|string|in:food_order,laundry,cleaning,amenities,maintenance,spa,taxi',
            'details' => 'nullable|array',
            'price' => 'nullable|numeric|min:0',
            'status' => 'nullable|string',
        ]);

        // Default statuses based on request type
        $defaultStatus = 'Pending';
        if ($request->type === 'food_order') {
            $defaultStatus = 'Received';
        }

        $serviceRequest = LodgeServiceRequest::create([
            'guest_id' => $user->id,
            'room_number' => $request->room_number,
            'type' => $request->type,
            'details' => $request->details,
            'price' => $request->price ?? 0.00,
            'status' => $request->status ?? $defaultStatus,
        ]);

        return response()->json($serviceRequest, 201);
    }

    /**
     * Update request status (e.g. Preparing, Ready, Delivered, Completed).
     */
    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        $serviceRequest = LodgeServiceRequest::findOrFail($id);

        // Host/Admin can update status
        if ($user->role !== 'owner' && $user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Only staff/hosts can update status.'], 403);
        }

        $request->validate([
            'status' => 'required|string',
        ]);

        $serviceRequest->update([
            'status' => $request->status,
        ]);

        return response()->json($serviceRequest);
    }

    /**
     * Clear or delete requests.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $serviceRequest = LodgeServiceRequest::findOrFail($id);

        if ($user->role !== 'admin' && $serviceRequest->guest_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $serviceRequest->delete();

        return response()->json(['message' => 'Service request deleted successfully.']);
    }
}
