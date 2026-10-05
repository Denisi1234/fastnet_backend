<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin verification decisions (the admin-portal Approve / Reject buttons).
 *
 * Regression cover for two silent production killers:
 *  1. `users.status` was missing from User::$fillable, so every owner
 *     approve/reject/suspend returned 200 while persisting NOTHING.
 *  2. Unauthenticated api/* calls 500'd ("Route [login] not defined")
 *     instead of 401 whenever Accept: application/json was absent.
 */
class AdminVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'Active',
        ]);
        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => bcrypt('password'), 'role' => 'owner', 'status' => 'Pending Verification',
        ]);
        $this->property = Property::create([
            'host_id' => $this->owner->id,
            'name' => 'Test Lodge',
            'city' => 'Dar es Salaam',
            'area' => 'Ubungo',
            'price_per_night' => 50000,
            'latitude' => -6.8,
            'longitude' => 39.2,
            'status' => 'Pending',
        ]);
    }

    public function test_unauthenticated_raw_request_returns_401(): void
    {
        // No Accept header either — the old guest redirect crashed on
        // route('login') and answered 500 for every auth route.
        $response = $this->call('POST', '/api/admin/verification/owner/'.$this->owner->id, ['status' => 'approved']);
        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_admin_can_approve_owner_and_status_persists(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/verification/owner/'.$this->owner->id, ['status' => 'approved']);

        $response->assertOk();
        $this->assertSame('Active', $this->owner->fresh()->status);
    }

    public function test_admin_can_reject_owner_and_status_persists(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/verification/owner/'.$this->owner->id, [
                'status' => 'rejected',
                'notes' => 'Docs blurry',
            ]);

        $response->assertOk();
        $this->assertSame('Suspended', $this->owner->fresh()->status);

        $audit = VerificationRequest::where('entity_type', 'owner')
            ->where('entity_id', $this->owner->id)
            ->latest('id')->first();
        $this->assertSame('Docs blurry', $audit->reason);
        $this->assertSame('Docs blurry', $audit->admin_notes);
    }

    public function test_admin_can_approve_and_reject_lodge(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/verification/lodge/'.$this->property->id, ['status' => 'Active']);
        $response->assertOk();
        $this->assertSame('Active', $this->property->fresh()->status);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/verification/lodge/'.$this->property->id, [
                'status' => 'rejected', 'reason' => 'Fake photos',
            ]);
        $response->assertOk();
        $this->assertSame('rejected', $this->property->fresh()->status);
    }

    public function test_non_admin_cannot_review(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/admin/verification/owner/'.$this->owner->id, ['status' => 'approved']);
        $response->assertForbidden();
    }
}
