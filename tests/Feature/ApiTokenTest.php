<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The API Tokens page mints a token by POSTing to /api/v1/tokens with the
     * browser session cookie (no Bearer token yet — chicken and egg). That only
     * works if Sanctum's stateful-frontend middleware is on the `api` group,
     * which Laravel 12 leaves off unless bootstrap/app.php calls statefulApi().
     * actingAs() hides the gap, so assert the middleware directly.
     */
    public function test_api_group_has_sanctum_stateful_middleware(): void
    {
        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            Route::getMiddlewareGroups()['api'] ?? [],
            'bootstrap/app.php must call $middleware->statefulApi() so the SPA can cookie-authenticate to /api.'
        );
    }

    private function clientUser(): User
    {
        return User::factory()->create([
            'role'              => 'client',
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_can_create_api_token(): void
    {
        $user = $this->clientUser();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/tokens', ['name' => 'My Token']);

        $response->assertSuccessful()
                 ->assertJsonStructure(['token', 'name', 'id']);
    }

    public function test_user_can_list_api_tokens(): void
    {
        $user = $this->clientUser();
        $user->createToken('Token A');
        $user->createToken('Token B');

        $this->actingAs($user)
            ->getJson('/api/v1/tokens')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_revoke_token(): void
    {
        $user  = $this->clientUser();
        $token = $user->createToken('Revokeable');

        $this->actingAs($user)
            ->deleteJson('/api/v1/tokens/'.$token->accessToken->id)
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_unauthenticated_cannot_access_api(): void
    {
        $this->getJson('/api/v1/me')
             ->assertUnauthorized();
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user  = $this->clientUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
             ->getJson('/api/v1/me')
             ->assertOk()
             ->assertJsonPath('email', $user->email);
    }
}
