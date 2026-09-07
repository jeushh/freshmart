<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    // -- Self-service: POST /api/me/password ---------------------------------

    public function test_user_can_change_their_own_password_with_correct_current_password(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->postJson('/api/me/password', [
            'current_password' => 'test123',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertOk();

        $this->assertTrue(
            Hash::check('a-new-strong-password', DB::table('admin_users')->where('id', $cashier->id)->value('password_hash')),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.password_changed',
            'entity_id' => (string) $cashier->id,
            'username' => 'cashier',
        ]);
    }

    public function test_user_cannot_change_password_with_incorrect_current_password(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->postJson('/api/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(
            Hash::check('test123', DB::table('admin_users')->where('id', $cashier->id)->value('password_hash')),
        );
    }

    public function test_change_password_requires_matching_confirmation(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->postJson('/api/me/password', [
            'current_password' => 'test123',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'does-not-match',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_change_password_rejects_reusing_the_current_password(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->postJson('/api/me/password', [
            'current_password' => 'test123',
            'password' => 'test123',
            'password_confirmation' => 'test123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_change_password_enforces_minimum_length(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->postJson('/api/me/password', [
            'current_password' => 'test123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_guest_cannot_change_a_password(): void
    {
        $this->postJson('/api/me/password', [
            'current_password' => 'test123',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertUnauthorized();
    }

    // -- Admin reset: POST /api/workspace/users/{id}/reset-password ----------

    public function test_admin_can_reset_another_users_password(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($admin);

        $this->postJson("/api/workspace/users/{$cashier->id}/reset-password", [
            'password' => 'temporary-password-1',
        ])->assertOk()
            ->assertJsonMissing(['password_hash']);

        $this->assertTrue(
            Hash::check('temporary-password-1', DB::table('admin_users')->where('id', $cashier->id)->value('password_hash')),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.password_reset',
            'entity_id' => (string) $cashier->id,
            'username' => 'admin',
        ]);

        // The cashier's own current password no longer works; a reset does not
        // require (or preserve) knowledge of the old one.
        $this->assertFalse(
            Hash::check('test123', DB::table('admin_users')->where('id', $cashier->id)->value('password_hash')),
        );
    }

    public function test_user_without_permission_cannot_reset_a_password(): void
    {
        $cashierA = User::where('username', 'cashier')->firstOrFail();
        $cashierB = $this->createCashier('cashier-b');
        $this->actingAs($cashierA);

        $this->postJson("/api/workspace/users/{$cashierB->id}/reset-password", [
            'password' => 'temporary-password-1',
        ])->assertForbidden();

        $this->assertTrue(
            Hash::check('test123', DB::table('admin_users')->where('id', $cashierB->id)->value('password_hash')),
        );
    }

    public function test_reset_password_enforces_minimum_length(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($admin);

        $this->postJson("/api/workspace/users/{$cashier->id}/reset-password", [
            'password' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_password_returns_404_for_an_unknown_user(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);

        $this->postJson('/api/workspace/users/999999/reset-password', [
            'password' => 'temporary-password-1',
        ])->assertNotFound();
    }

    private function createCashier(string $username): User
    {
        $roleId = DB::table('roles')->where('name', 'Cashier')->value('id');
        $id = DB::table('admin_users')->insertGetId([
            'username' => $username,
            'full_name' => 'Extra Cashier',
            'password_hash' => Hash::make('test123'),
            'role_id' => $roleId,
            'status' => 'Active',
        ]);

        return User::findOrFail($id);
    }
}
