<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReturnPermissionTest extends TestCase
{
    public function test_staff_without_returns_permission_is_forbidden_from_return_operations(): void
    {
        // 1. Staff with only unrelated permission (e.g., blog.read)
        $limitedStaff = User::factory()->create([
            'role' => 'staff',
            'permissions' => ['blog.read'],
            'status' => 'active',
        ]);

        Sanctum::actingAs($limitedStaff, ['admin:access']);

        // 2. Index should be 403
        $resIndex = $this->getJson('/api/admin/returns');
        $resIndex->assertStatus(403);

        // 3. Refund should be 403
        $resRefund = $this->postJson('/api/admin/returns/1/refund', [
            'refund_amount' => 100,
            'refund_method' => 'cash',
        ]);
        $resRefund->assertStatus(403);

        // 4. Destroy should be 403
        $resDestroy = $this->deleteJson('/api/admin/returns/1');
        $resDestroy->assertStatus(403);
    }

    public function test_staff_with_returns_manage_permission_can_access_returns(): void
    {
        $authorizedStaff = User::factory()->create([
            'role' => 'staff',
            'permissions' => ['returns.manage', 'finance.manage'],
            'status' => 'active',
        ]);

        Sanctum::actingAs($authorizedStaff, ['admin:access']);

        $resIndex = $this->getJson('/api/admin/returns');
        $resIndex->assertStatus(200);
    }
}
