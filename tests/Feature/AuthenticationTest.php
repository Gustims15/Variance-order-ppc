<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful login.
     */
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->viewer()->create([
            'email' => 'viewer@test.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'viewer@test.com',
            'password' => 'password',
            'device_name' => 'testing-device',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', User::ROLE_VIEWER)
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty(
            $response->json('data.token')
        );

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'testing-device',
        ]);
    }

    /**
     * Test login with incorrect password.
     */
    public function test_user_cannot_login_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'viewer@test.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'viewer@test.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    /**
     * Test inactive account cannot login.
     */
    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'email' => 'inactive@test.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inactive@test.com',
            'password' => 'password',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * Test login validation.
     */
    public function test_login_requires_valid_email_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'invalid-email',
            'password' => '',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
                'password',
            ]);
    }

    /**
     * Test unauthenticated user cannot access profile.
     */
    public function test_guest_cannot_access_authenticated_profile(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    /**
     * Test authenticated user can access profile.
     */
    public function test_authenticated_user_can_access_profile(): void
    {
        $user = User::factory()->manager()->create();

        $token = $user->createToken('testing-device')
            ->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/auth/me');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', User::ROLE_MANAGER)
            ->assertJsonPath('data.is_active', true);
    }


/**
 * Test logout revokes current token.
 */
public function test_logout_revokes_current_token(): void
{
    $user = User::factory()->create();

    $token = $user->createToken('testing-device')
        ->plainTextToken;

    // Perform logout.
    $response = $this->withToken($token)
        ->postJson('/api/auth/logout');

    $response
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Verify token has been deleted from database.
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $user->id,
    ]);

    // Clear cached authentication guards.
    $this->app['auth']->forgetGuards();

    // The revoked token must no longer authenticate.
    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertStatus(401);
}

}
