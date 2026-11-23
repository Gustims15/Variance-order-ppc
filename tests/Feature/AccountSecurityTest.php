<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createAdministrator(): User
    {
        return User::factory()->create([
            'name' => 'Security Administrator',
            'email' => 'security-admin@example.com',
            'password' => 'AdminPassword2026!',
            'role' => User::ROLE_ADMINISTRATOR,
            'is_active' => true,
        ]);
    }

    public function test_administrator_can_deactivate_user_and_revoke_tokens(): void
    {
        $admin = $this->createAdministrator();

        $user = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $adminToken = $admin->createToken('admin-session')->plainTextToken;
        $userToken = $user->createToken('user-session')->plainTextToken;

        $this->withToken($adminToken)
            ->patchJson("/api/users/{$user->id}/status", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        // Clear previously resolved authentication guards.
$this->app['auth']->forgetGuards();

// Verify that the revoked token cannot authenticate.
$this->withToken($userToken)
    ->getJson('/api/auth/me')
    ->assertUnauthorized();
    }

    public function test_administrator_can_reactivate_inactive_user(): void
    {
        $admin = $this->createAdministrator();

        $user = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'is_active' => false,
        ]);

        $adminToken = $admin->createToken('admin-session')->plainTextToken;

        $this->withToken($adminToken)
            ->patchJson("/api/users/{$user->id}/status", [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_administrator_cannot_deactivate_own_account(): void
    {
        $admin = $this->createAdministrator();

        $adminToken = $admin->createToken('admin-session')->plainTextToken;

        $this->withToken($adminToken)
            ->patchJson("/api/users/{$admin->id}/status", [
                'is_active' => false,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'is_active' => true,
        ]);
    }

    public function test_password_reset_revokes_old_tokens(): void
    {
        $admin = $this->createAdministrator();

        $user = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $adminToken = $admin->createToken('admin-session')->plainTextToken;
        $userToken = $user->createToken('old-session')->plainTextToken;

        $newPassword = 'NewSecurePassword2026!';

        $this->withToken($adminToken)
            ->patchJson("/api/users/{$user->id}/password", [
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();

        $this->assertTrue(
            Hash::check($newPassword, $user->password)
        );

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);

        // Clear previously resolved authentication guards.
$this->app['auth']->forgetGuards();

// Verify that the old token cannot authenticate.
$this->withToken($userToken)
    ->getJson('/api/auth/me')
    ->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $newPassword,
        ])->assertOk();
    }

    public function test_password_reset_requires_confirmation(): void
    {
        $admin = $this->createAdministrator();

        $user = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $adminToken = $admin->createToken('admin-session')->plainTextToken;

        $this->withToken($adminToken)
            ->patchJson("/api/users/{$user->id}/password", [
                'password' => 'NewSecurePassword2026!',
            ])
            ->assertUnprocessable();

    }

    public function test_password_reset_requires_at_least_twelve_characters(): void
    {
        $admin = $this->createAdministrator();

        $user = User::factory()->create([
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ]);

        $adminToken = $admin->createToken('admin-session')->plainTextToken;

        $this->withToken($adminToken)
            ->patchJson("/api/users/{$user->id}/password", [
                'password' => 'Short123',
                'password_confirmation' => 'Short123',
            ])
            ->assertUnprocessable();
    }
}
