<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ApiAuthenticationFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ruta que solo existe durante estos tests, protegida únicamente por
     * "auth:api", para verificar el comportamiento genérico de autenticación
     * de la API sin acoplar el test a un endpoint de negocio concreto.
     * Se registra bajo "/api/..." a propósito: shouldRenderJsonWhen()
     * decide JSON vs. HTML mirando $request->is('api/*'), por lo que debe
     * vivir bajo ese prefijo para replicar fielmente el comportamiento real.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Route::middleware(['auth:api'])
            ->get('/api/_test/protected', fn () => response()->json(['ok' => true]));
    }

    private function assertCleanUnauthenticatedJsonResponse($response): void
    {
        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');
        $response->assertJson(['message' => 'Unauthenticated.']);

        $this->assertNotSame(302, $response->getStatusCode());
        $this->assertNull($response->headers->get('Location'));
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function test_sin_jwt_y_sin_accept_header_devuelve_401_json(): void
    {
        // Sin helper getJson()/postJson(): simula un cliente real (curl,
        // Postman) que no envía "Accept: application/json".
        $response = $this->get('/api/_test/protected');

        $this->assertCleanUnauthenticatedJsonResponse($response);
    }

    public function test_jwt_invalido_y_sin_accept_header_devuelve_401_json(): void
    {
        $response = $this->get('/api/_test/protected', [
            'Authorization' => 'Bearer token-invalido-y-mal-formado',
        ]);

        $this->assertCleanUnauthenticatedJsonResponse($response);
    }

    public function test_jwt_expirado_y_sin_accept_header_devuelve_401_json(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', 'CLIENT')->value('id'),
        ]);

        // Token válido en el momento de emitirlo (TTL normal); viajamos el
        // reloj de prueba más allá de su expiración antes de usarlo, para
        // producir un token expirado de forma estable sin depender de
        // esperar tiempo real ni de emitir un token ya-expirado (esto
        // último hace que la librería JWT lance la excepción al crearlo,
        // no al validarlo).
        //
        // Se emite con JWTAuth::fromUser() y no con auth('api')->login():
        // login() deja el usuario cacheado en la instancia del guard, y
        // como el test comparte esa misma instancia con la petición HTTP
        // posterior, el guard nunca volvería a validar el token contra el
        // reloj viajado.
        $token = (string) JWTAuth::fromUser($user);

        $this->travel(config('jwt.ttl') + 1)->minutes();

        $response = $this->get('/api/_test/protected', [
            'Authorization' => "Bearer {$token}",
        ]);

        $this->assertCleanUnauthenticatedJsonResponse($response);
    }
}
