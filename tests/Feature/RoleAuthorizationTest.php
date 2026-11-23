<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticate user with a specific role.
     */
    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Administrator can access user management.
     */
    public function test_administrator_can_access_user_management(): void
    {
        $this->actingAsRole(User::ROLE_ADMINISTRATOR);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * Manager cannot access user management.
     */
    public function test_manager_cannot_access_user_management(): void
    {
        $this->actingAsRole(User::ROLE_MANAGER);

        $this->getJson('/api/users')
            ->assertForbidden();
    }

    /**
     * Staff cannot access user management.
     */
    public function test_staff_cannot_access_user_management(): void
    {
        $this->actingAsRole(User::ROLE_STAFF);

        $this->getJson('/api/users')
            ->assertForbidden();
    }

    /**
     * Viewer cannot access user management.
     */
    public function test_viewer_cannot_access_user_management(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER);

        $this->getJson('/api/users')
            ->assertForbidden();
    }

    /**
     * All active roles can view imports.
     */
    public function test_all_active_roles_can_view_imports(): void
    {
        $roles = User::AVAILABLE_ROLES;

        foreach ($roles as $role) {
            $this->app['auth']->forgetGuards();

            $this->actingAsRole($role);

            $this->getJson('/api/imports')
                ->assertOk();
        }
    }

    /**
     * Viewer cannot upload Excel.
     */
    public function test_viewer_cannot_upload_excel(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER);

        $this->postJson('/api/imports', [])
            ->assertForbidden();
    }

    /**
     * Staff cannot reopen individual reviews.
     */
    public function test_staff_cannot_reopen_review(): void
    {
        $this->actingAsRole(User::ROLE_STAFF);

        $this->patchJson('/api/reviews/999999/reopen', [])
            ->assertForbidden();
    }

    /**
     * Staff cannot perform bulk resolve.
     */
    public function test_staff_cannot_bulk_resolve_reviews(): void
    {
        $this->actingAsRole(User::ROLE_STAFF);

        $this->patchJson('/api/reviews/bulk-resolve', [])
            ->assertForbidden();
    }

    /**
     * Staff cannot perform bulk reopen.
     */
    public function test_staff_cannot_bulk_reopen_reviews(): void
    {
        $this->actingAsRole(User::ROLE_STAFF);

        $this->patchJson('/api/reviews/bulk-reopen', [])
            ->assertForbidden();
    }

    /**
     * Viewer cannot generate variance reports.
     */
    public function test_viewer_cannot_generate_variance(): void
    {
        $this->actingAsRole(User::ROLE_VIEWER);

        $this->postJson('/api/variance-reports', [])
            ->assertForbidden();
    }

    /**
     * Staff can pass authorization for individual resolve.
     */
    public function test_staff_is_authorized_to_resolve_review(): void
    {
        $this->actingAsRole(User::ROLE_STAFF);

        $response = $this->patchJson(
            '/api/reviews/999999/resolve',
            []
        );

        $this->assertNotSame(
            403,
            $response->status(),
            'Staff should be authorized to resolve individual reviews.'
        );
    }

    /**
     * Manager can pass authorization for individual reopen.
     */
    public function test_manager_is_authorized_to_reopen_review(): void
    {
        $this->actingAsRole(User::ROLE_MANAGER);

        $response = $this->patchJson(
            '/api/reviews/999999/reopen',
            []
        );

        $this->assertNotSame(
            403,
            $response->status(),
            'Manager should be authorized to reopen individual reviews.'
        );
    }
}
