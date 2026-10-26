<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public static function roles(): array
    {
        return [
            'client' => ['CLIENT'],
            'organizer' => ['ORGANIZER'],
            'admin' => ['ADMIN'],
        ];
    }

    #[DataProvider('roles')]
    public function test_logout_invalidates_the_token_for_every_role(string $role): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];

        $this->postJson('/api/auth/logout', [], $headers)
            ->assertOk()
            ->assertExactJson(['message' => 'Sesión cerrada correctamente.']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/logout', [], $headers)->assertUnauthorized();
    }

    public function test_logout_only_revokes_the_current_token_and_keeps_the_account(): void
    {
        $user = User::factory()->create();
        $firstToken = JWTAuth::fromUser($user);
        $otherToken = JWTAuth::fromUser($user);

        $this->postJson('/api/auth/logout', [], [
            'Authorization' => "Bearer {$firstToken}",
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/logout', [], [
            'Authorization' => "Bearer {$otherToken}",
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'active' => true]);
    }

    public function test_deactivated_user_can_end_a_previously_opened_session(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $user->update(['active' => false]);

        $this->postJson('/api/auth/logout', [], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();
    }

    public function test_logout_requires_a_valid_token_without_redirecting_to_login(): void
    {
        $this->post('/api/auth/logout')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->app['auth']->forgetGuards();
        $this->post('/api/auth/logout', [], ['Authorization' => 'Bearer invalid'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
