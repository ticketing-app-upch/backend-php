<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_add_is_idempotent_and_catalog_marks_only_the_authenticated_clients_favorites(): void
    {
        $event = $this->publishedEvent();
        $client = $this->actor();
        for ($index = 0; $index < 2; $index++) {
            $this->postJson('/api/v1/favoritos', ['eventId' => $event->id], $this->headersFor($client))
                ->assertOk()->assertJsonPath('isFavorite', true);
        }
        $this->assertDatabaseCount('favorites', 1);
        $this->getJson('/api/events', $this->headersFor($client))->assertOk()->assertJsonPath('0.isFavorite', true);
        $this->getJson('/api/events', $this->headersFor($this->actor()))->assertOk()->assertJsonPath('0.isFavorite', false);
    }

    public function test_list_returns_only_own_published_favorites_and_empty_array_when_none(): void
    {
        $event = $this->publishedEvent();
        $hidden = $this->publishedEvent(['status' => 'DRAFT']);
        $client = $this->actor();
        $other = $this->actor();
        $client->favoriteEvents()->attach([$event->id, $hidden->id]);

        $this->getJson('/api/v1/favoritos', $this->headersFor($client))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', (string) $event->id)
            ->assertJsonPath('0.isFavorite', true);
        $this->getJson('/api/v1/favoritos', $this->headersFor($other))->assertOk()->assertExactJson([]);
    }

    public function test_remove_is_idempotent_and_cannot_remove_another_clients_favorite(): void
    {
        $event = $this->publishedEvent();
        $owner = $this->actor();
        $other = $this->actor();
        $owner->favoriteEvents()->attach($event->id);
        for ($index = 0; $index < 2; $index++) {
            $this->deleteJson('/api/v1/favoritos', ['eventId' => $event->id, 'userId' => $owner->id], $this->headersFor($other))
                ->assertOk()->assertJsonPath('isFavorite', false);
        }
        $this->assertDatabaseHas('favorites', ['user_id' => $owner->id, 'event_id' => $event->id]);
        $this->deleteJson('/api/v1/favoritos', ['eventId' => $event->id], $this->headersFor($owner))->assertOk();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_add_rejects_unknown_or_unpublished_events(): void
    {
        $hidden = $this->publishedEvent(['status' => 'DRAFT']);
        $client = $this->actor();
        foreach ([$hidden->id, 999999] as $id) {
            $this->postJson('/api/v1/favoritos', ['eventId' => $id], $this->headersFor($client))->assertNotFound();
        }
        $this->assertDatabaseCount('favorites', 0);
    }

    public static function forbiddenRoles(): array
    {
        return ['organizer' => ['ORGANIZER'], 'admin' => ['ADMIN']];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_only_clients_can_use_all_favorite_endpoints(string $role): void
    {
        $user = $this->actor($role);
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->json($method, '/api/v1/favoritos', ['eventId' => 1], $this->headersFor($user))->assertForbidden();
        }
    }

    public function test_endpoints_require_authentication_and_validate_payload(): void
    {
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->json($method, '/api/v1/favoritos')->assertUnauthorized();
        }
        $client = $this->actor();
        $this->postJson('/api/v1/favoritos', [], $this->headersFor($client))
            ->assertUnprocessable()->assertJsonValidationErrors('eventId');
        $this->deleteJson('/api/v1/favoritos', ['eventId' => []], $this->headersFor($client))
            ->assertUnprocessable()->assertJsonValidationErrors('eventId');
    }

    public function test_database_enforces_unique_user_event_pair(): void
    {
        $event = $this->publishedEvent();
        $client = $this->actor();
        $row = ['user_id' => $client->id, 'event_id' => $event->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('favorites')->insert($row);
        $this->expectException(QueryException::class);
        DB::table('favorites')->insert($row);
    }
}
