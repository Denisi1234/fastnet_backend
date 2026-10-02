<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Property;
use App\Models\OwnerVerification;
use App\Models\LodgeDocument;
use App\Models\VerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VerificationController extends Controller
{
    // ==========================================
    // OWNER VERIFICATION WORKFLOW
    // ==========================================

    /**
     * Owner submits verification documents.
     */
    public function submitOwnerVerification(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only property owners can submit verification.'], 403);
        }

        $request->validate([
            'full_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'id_number' => 'required|string|max:255',
            'id_document_url' => 'required|string',
            'business_registration_number' => 'nullable|string',
            'business_document_url' => 'nullable|string',
            'payout_bank_name' => 'nullable|string',
            'payout_account_number' => 'nullable|string',
            'payout_account_name' => 'nullable|string',
        ]);

        $verification = OwnerVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'full_name' => $request->full_name,
                'phone_number' => $request->phone_number,
                'id_number' => $request->id_number,
                'id_document_url' => $request->id_document_url,
                'business_registration_number' => $request->business_registration_number,
                'business_document_url' => $request->business_document_url,
                'payout_bank_name' => $request->payout_bank_name,
                'payout_account_number' => $request->payout_account_number,
                'payout_account_name' => $request->payout_account_name,
                'status' => 'submitted',
            ]
        );

        // Update user status
        $user->update(['status' => 'Pending Verification']);

        // Log audit trail
        VerificationRequest::create([
            'entity_type' => 'owner',
            'entity_id' => $user->id,
            'submitted_by' => $user->id,
            'status' => 'submitted',
        ]);

        return response()->json([
            'message' => 'Owner verification submitted successfully.',
            'verification' => $verification,
        ], 201);
    }

    /**
     * Get owner verification profile (for current owner or admin)
     */
    public function getOwnerVerification(Request $request, $userId = null)
    {
        $targetId = $userId ?? $request->user()->id;
        $verification = OwnerVerification::with(['user', 'reviewer'])->where('user_id', $targetId)->first();
        
        $history = VerificationRequest::where('entity_type', 'owner')
            ->where('entity_id', $targetId)
            ->with(['reviewer', 'submitter'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'verification' => $verification,
            'audit_history' => $history,
        ]);
    }

    /**
     * Admin reviews owner verification request.
     */
    public function reviewOwner(Request $request, $ownerId)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:approved,rejected,changes_requested,suspended',
            'reason' => 'nullable|string',
            'admin_notes' => 'nullable|string',
        ]);

        $owner = User::findOrFail($ownerId);
        $verification = OwnerVerification::where('user_id', $ownerId)->first();

        if ($verification) {
            $verification->update([
                'status' => $request->status,
                'admin_notes' => $request->admin_notes,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ]);
        }

        // Map status to User status
        $userStatus = 'Pending Verification';
        if ($request->status === 'approved') $userStatus = 'Active';
        if ($request->status === 'rejected' || $request->status === 'suspended') $userStatus = 'Suspended';

        $owner->update(['status' => $userStatus]);

        // Audit Trail entry
        VerificationRequest::create([
            'entity_type' => 'owner',
            'entity_id' => $ownerId,
            'submitted_by' => $ownerId,
            'status' => $request->status,
            'reason' => $request->reason,
            'admin_notes' => $request->admin_notes,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => "Owner verification updated to {$request->status}.",
            'owner' => $owner,
        ]);
    }

    // ==========================================
    // LODGE VERIFICATION WORKFLOW
    // ==========================================

    /**
     * Submit lodge verification request with documents.
     */
    public function submitLodgeVerification(Request $request, $propertyId)
    {
        $property = Property::findOrFail($propertyId);
        $user = $request->user();

        if ($user->role !== 'admin' && $property->host_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized to submit verification for this lodge.'], 403);
        }

        $request->validate([
            'documents' => 'nullable|array',
            'documents.*.document_type' => 'required|string',
            'documents.*.file_url' => 'required|string',
            'documents.*.document_number' => 'nullable|string',
        ]);

        if ($request->has('documents')) {
            foreach ($request->documents as $doc) {
                LodgeDocument::create([
                    'property_id' => $property->id,
                    'document_type' => $doc['document_type'],
                    'file_url' => $doc['file_url'],
                    'document_number' => $doc['document_number'] ?? null,
                ]);
            }
        }

        $property->update(['status' => 'Pending']);

        // Log audit trail
        VerificationRequest::create([
            'entity_type' => 'lodge',
            'entity_id' => $property->id,
            'submitted_by' => $user->id,
            'status' => 'submitted',
        ]);

        return response()->json([
            'message' => 'Lodge verification request submitted successfully.',
            'property' => ['id' => $property->id, 'status' => $property->status],
        ]);
    }

    /**
     * Admin reviews lodge verification request.
     */
    public function reviewLodge(Request $request, $propertyId)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:Active,Pending,Removed,changes_requested,rejected',
            'reason' => 'nullable|string',
            'admin_notes' => 'nullable|string',
        ]);

        $property = Property::findOrFail($propertyId);
        $property->update(['status' => $request->status]);

        // Bust caches so approval is visible immediately (detail + search).
        // Heavy invalidation runs AFTER the response is sent, so the admin
        // action returns in ~3 EU round trips instead of ~7+ log/cache writes.
        Cache::increment('properties:search-version');
        Cache::forget("property:detail:v2:{$property->id}");
        \App\Jobs\InvalidatePropertyCache::dispatch($property->id, $property->city)->afterResponse();

        // Audit Trail Entry
        VerificationRequest::create([
            'entity_type' => 'lodge',
            'entity_id' => $propertyId,
            'submitted_by' => $property->host_id,
            'status' => strtolower($request->status),
            'reason' => $request->reason,
            'admin_notes' => $request->admin_notes,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // Slim response: callers (web optimistic UI, mobile) only need the
        // outcome — the full documents/rooms/host eager load cost 3 extra
        // cross-region DB round trips per approve/reject.
        return response()->json([
            'message' => "Lodge verification updated to {$request->status}.",
            'property' => ['id' => $property->id, 'status' => $property->status],
        ]);
    }

    /**
     * Get Verification Dashboard counters & lists for Admin.
     */
    public function adminVerificationSummary(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin role required.'], 403);
        }

        $pendingOwners = User::where('role', 'owner')->where('status', 'Pending Verification')->with('ownerVerification')->get();
        $approvedOwners = User::where('role', 'owner')->where('status', 'Active')->get();
        
        $pendingLodges = Property::where('status', 'Pending')->with(['host', 'rooms', 'documents'])->get();
        $approvedLodges = Property::where('status', 'Active')->with(['host', 'rooms', 'documents'])->get();

        return response()->json([
            'counts' => [
                'pending_owners' => $pendingOwners->count(),
                'approved_owners' => $approvedOwners->count(),
                'pending_lodges' => $pendingLodges->count(),
                'approved_lodges' => $approvedLodges->count(),
            ],
            'pending_owners' => $pendingOwners,
            'pending_lodges' => $pendingLodges,
        ]);
    }
}
