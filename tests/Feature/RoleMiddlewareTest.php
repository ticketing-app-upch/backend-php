<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ruta que solo existe durante estos tests, para ejercitar la cadena
     * "auth:api" + "role:..." sin exponer un endpoint real en routes/api.php.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Route::middleware(['auth:api', 'role:ORGANIZER'])
            ->get('/_test/organizer-only', fn () => response()->json(['ok' => true]));
    }

    public function test_sin_token_devuelve_401(): void
    {
        $response = $this->getJson('/_test/organizer-only');

        $response->assertStatus(401);
    }

    public function test_con_rol_no_permitido_devuelve_403(): void
    {
        $client = User::factory()->create([
            'role_id' => Role::where('name', 'CLIENT')->value('id'),
        ]);

        $token = auth('api')->login($client);

        $response = $this->getJson('/_test/organizer-only', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_con_rol_permitido_devuelve_200(): void
    {
        $organizer = User::factory()->create([
            'role_id' => Role::where('name', 'ORGANIZER')->value('id'),
        ]);

        $token = auth('api')->login($organizer);

        $response = $this->getJson('/_test/organizer-only', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}
