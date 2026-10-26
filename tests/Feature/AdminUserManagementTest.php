<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/usuarios';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role = 'CLIENT', bool $active = true): User
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'password' => 'Secret123!',
            'active' => $active,
        ]);

        if ($role === 'CLIENT') {
            $user->clientProfile()->create(['doc_number' => str_pad((string) $user->id, 8, '0', STR_PAD_LEFT)]);
        } elseif ($role === 'ORGANIZER') {
            $user->organizerProfile()->create(['tax_id' => '20'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT)]);
        }

        return $user;
    }

    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function eventFor(User $organizer): Event
    {
        return Event::create([
            'organizer_id' => $organizer->id,
            'title' => 'Evento publicado',
            'description' => 'Evento que se conserva al desactivar al organizador.',
            'category' => 'CONCIERTO',
            'venue_name' => 'Estadio Nacional',
            'venue_address' => 'Lima',
            'venue_capacity' => 100,
            'starts_at' => now()->addMonth(),
            'status' => 'PUBLISHED',
        ]);
    }

    public function test_list_includes_only_attendees_and_organizers_with_administration_fields(): void
    {
        $admin = $this->user('ADMIN');
        $client = $this->user();
        $organizer = $this->user('ORGANIZER', false);
        $this->user('ADMIN');

        $this->getJson(self::URL, $this->headersFor($admin))
            ->assertOk()
            ->assertExactJson([
                ['id' => (string) $client->id, 'fullName' => $client->full_name,
                    'email' => $client->email, 'role' => 'CLIENT', 'active' => true],
                ['id' => (string) $organizer->id, 'fullName' => $organizer->full_name,
                    'email' => $organizer->email, 'role' => 'ORGANIZER', 'active' => false],
            ]);
    }

    public function test_list_is_empty_when_there_are_only_administrators(): void
    {
        $admin = $this->user('ADMIN');

        $this->getJson(self::URL, $this->headersFor($admin))
            ->assertOk()->assertExactJson([]);
    }

    public static function filters(): array
    {
        return [
            'role' => ['role=CLIENT', ['clientActive', 'clientInactive']],
            'active' => ['active=1', ['clientActive', 'organizerActive']],
            'inactive' => ['active=0', ['clientInactive', 'organizerInactive']],
            'both' => ['role=ORGANIZER&active=0', ['organizerInactive']],
        ];
    }

    #[DataProvider('filters')]
    public function test_list_filters_by_role_and_state(string $query, array $expectedKeys): void
    {
        $admin = $this->user('ADMIN');
        $users = [
            'clientActive' => $this->user(),
            'clientInactive' => $this->user('CLIENT', false),
            'organizerActive' => $this->user('ORGANIZER'),
            'organizerInactive' => $this->user('ORGANIZER', false),
        ];

        $response = $this->getJson(self::URL.'?'.$query, $this->headersFor($admin))->assertOk();

        $this->assertSame(
            array_map(fn ($key) => (string) $users[$key]->id, $expectedKeys),
            array_column($response->json(), 'id'),
        );
    }

    public static function invalidFilters(): array
    {
        return [
            'admin role' => ['role=ADMIN', 'role'],
            'unknown role' => ['role=INVALID', 'role'],
            'invalid state' => ['active=invalid', 'active'],
            'array state' => ['active[]=1', 'active'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422(string $query, string $field): void
    {
        $this->getJson(self::URL.'?'.$query, $this->headersFor($this->user('ADMIN')))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_both_endpoints_require_authentication_even_without_accept_header(): void
    {
        $this->get(self::URL)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->patch(self::URL.'/1/estado', ['active' => false])->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public static function nonAdminRoles(): array
    {
        return ['client' => ['CLIENT'], 'organizer' => ['ORGANIZER']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_list_or_change_users(string $role): void
    {
        $user = $this->user($role);
        $target = $this->user();

        $this->getJson(self::URL, $this->headersFor($user))->assertForbidden();
        $this->patchJson(self::URL."/{$target->id}/estado", ['active' => false], $this->headersFor($user))
            ->assertForbidden();
        $this->assertTrue($target->fresh()->active);
    }

    public function test_expired_admin_token_cannot_list_or_change_users(): void
    {
        $headers = $this->headersFor($this->user('ADMIN'));
        $target = $this->user();
        $this->travel(config('jwt.ttl') + 1)->minutes();

        $this->getJson(self::URL, $headers)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->patchJson(self::URL."/{$target->id}/estado", ['active' => false], $headers)
            ->assertUnauthorized();
        $this->assertTrue($target->fresh()->active);
    }

    public function test_admin_can_assign_a_state_idempotently_without_changing_other_fields(): void
    {
        $admin = $this->user('ADMIN');
        $client = $this->user();
        $url = self::URL."/{$client->id}/estado";
        $payload = ['active' => false, 'role' => 'ADMIN', 'role_id' => $admin->role_id,
            'email' => 'tampered@example.com', 'password' => 'Changed123!'];

        $this->patchJson($url, $payload, $this->headersFor($admin))
            ->assertOk()->assertJsonPath('active', false)->assertJsonPath('role', 'CLIENT');
        $this->patchJson($url, ['active' => false], $this->headersFor($admin))
            ->assertOk()->assertJsonPath('active', false);

        $this->assertSame($client->email, $client->fresh()->email);
        $this->assertTrue(Hash::check('Secret123!', $client->fresh()->password));
        $this->assertDatabaseHas('client_profiles', ['user_id' => $client->id]);
    }

    public function test_reactivation_restores_login_with_the_same_credentials(): void
    {
        $admin = $this->user('ADMIN');
        $client = $this->user('CLIENT', false);
        $credentials = ['email' => $client->email, 'password' => 'Secret123!'];

        $this->postJson('/api/auth/login', $credentials)->assertForbidden();
        $this->patchJson(self::URL."/{$client->id}/estado", ['active' => true], $this->headersFor($admin))
            ->assertOk()->assertJsonPath('active', true);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', $credentials)
            ->assertOk()->assertJsonPath('user.id', (string) $client->id);
    }

    public function test_admin_cannot_change_their_own_state_or_another_admins_state(): void
    {
        $admin = $this->user('ADMIN');
        $other = $this->user('ADMIN');

        foreach ([$admin, $other] as $target) {
            $this->patchJson(self::URL."/{$target->id}/estado", ['active' => false], $this->headersFor($admin))
                ->assertForbidden();
            $this->assertTrue($target->fresh()->active);
        }
    }

    public function test_unknown_user_returns_404(): void
    {
        $this->patchJson(self::URL.'/999999/estado', ['active' => false], $this->headersFor($this->user('ADMIN')))
            ->assertNotFound()->assertExactJson(['message' => 'Usuario no encontrado.']);
    }

    public static function invalidStates(): array
    {
        return [
            'missing' => [[]],
            'null' => [['active' => null]],
            'string false' => [['active' => 'false']],
            'number' => [['active' => 0]],
            'array' => [['active' => []]],
        ];
    }

    #[DataProvider('invalidStates')]
    public function test_state_change_requires_a_json_boolean(array $payload): void
    {
        $admin = $this->user('ADMIN');
        $client = $this->user();

        $this->patchJson(self::URL."/{$client->id}/estado", $payload, $this->headersFor($admin))
            ->assertUnprocessable()->assertJsonValidationErrors('active');
        $this->assertTrue($client->fresh()->active);
    }

    public function test_deactivation_preserves_events_tickets_and_existing_sessions(): void
    {
        $admin = $this->user('ADMIN');
        $organizer = $this->user('ORGANIZER');
        $client = $this->user();
        $event = $this->eventFor($organizer);
        $zone = $event->zones()->create([
            'name' => 'General', 'capacity' => 100, 'available_capacity' => 99, 'base_price' => 50,
        ]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'event_zone_id' => $zone->id,
            'quantity' => 1, 'total_price' => 50, 'code' => 'existing-ticket',
        ]);
        $organizerHeaders = $this->headersFor($organizer);

        foreach ([$organizer, $client] as $target) {
            $this->patchJson(self::URL."/{$target->id}/estado", ['active' => false], $this->headersFor($admin))
                ->assertOk();
        }

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->getJson("/api/events/{$event->id}/sales-report", $organizerHeaders)->assertOk();
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'user_id' => $client->id]);
        $this->assertDatabaseHas('organizer_profiles', ['user_id' => $organizer->id]);
        $this->assertDatabaseHas('client_profiles', ['user_id' => $client->id]);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'PUBLISHED']);
        $this->getJson('/api/events')->assertOk()->assertJsonFragment(['id' => (string) $event->id]);
    }
}
