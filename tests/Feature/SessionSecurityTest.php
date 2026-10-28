<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(now()->startOfSecond());

        Route::middleware('auth:api')
            ->get('/api/_test/session', fn () => response()->json(['ok' => true]));
    }

    private function organizer(): User
    {
        $user = User::factory()->create([
            'email' => 'session@example.com',
            'password' => 'Secret123!',
            'role_id' => Role::where('name', 'ORGANIZER')->value('id'),
            'active' => true,
        ]);
        $user->organizerProfile()->create(['tax_id' => '20123456789']);

        return $user;
    }

    private function eventFor(User $organizer): Event
    {
        return Event::create([
            'organizer_id' => $organizer->id,
            'title' => 'Evento de prueba de sesión',
            'description' => 'Verificación de autenticación.',
            'category' => 'CONCIERTO',
            'venue_name' => 'Estadio Nacional',
            'venue_address' => 'Lima',
            'venue_capacity' => 1000,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(3),
            'status' => 'PUBLISHED',
        ]);
    }

    private function loginToken(User $organizer): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $organizer->email,
            'password' => 'Secret123!',
        ])->assertOk();

        // Simula una nueva petición HTTP sin el usuario cacheado por el login.
        $this->app['auth']->forgetGuards();

        return $response->json('token');
    }

    public function test_deactivation_preserves_the_existing_session_until_token_expiration(): void
    {
        $organizer = $this->organizer();
        $event = $this->eventFor($organizer);
        $token = $this->loginToken($organizer);
        $url = "/api/events/{$event->id}/sales-report";
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->get($url, $headers)->assertOk();

        $organizer->update(['active' => false]);
        $this->assertFalse($organizer->fresh()->active);
        $this->app['auth']->forgetGuards();

        $this->get($url, $headers)->assertOk();

        $this->travel(config('jwt.ttl') + 1)->minutes();
        $this->app['auth']->forgetGuards();

        $this->get($url, $headers)
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
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
    public function test_deactivated_users_keep_previously_issued_tokens_for_all_roles(string $role): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'active' => true,
        ]);
        $token = (string) JWTAuth::fromUser($user);
        $user->update(['active' => false]);

        $this->get('/api/_test/session', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public static function tokenDurations(): array
    {
        return [
            'one minute' => [1],
            'five minutes' => [5],
            'fifteen minutes' => [15],
        ];
    }

    #[DataProvider('tokenDurations')]
    public function test_token_duration_is_configurable_in_minutes(int $minutes): void
    {
        config(['jwt.ttl' => $minutes]);
        $token = $this->loginToken($this->organizer());
        $claims = JWTAuth::setToken($token)->getPayload();

        $this->assertSame($minutes * 60, $claims->get('exp') - $claims->get('iat'));
    }

    public static function expirationBoundaries(): array
    {
        return [
            'one second before expiration' => [-1, 200],
            'one second after expiration' => [1, 401],
        ];
    }

    #[DataProvider('expirationBoundaries')]
    public function test_expiration_on_a_real_protected_route(int $offsetSeconds, int $status): void
    {
        $organizer = $this->organizer();
        $event = $this->eventFor($organizer);
        $token = $this->loginToken($organizer);

        $this->travel(config('jwt.ttl') * 60 + $offsetSeconds)->seconds();

        $response = $this->get("/api/events/{$event->id}/sales-report", [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus($status);

        if ($status === 401) {
            $response->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_public_event_catalog_remains_accessible_with_an_expired_token(): void
    {
        $token = $this->loginToken($this->organizer());
        $this->travel(config('jwt.ttl') + 1)->minutes();

        $this->get('/api/events', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }
}
