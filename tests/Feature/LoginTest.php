<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function createUser(string $role = 'CLIENT', bool $active = true): User
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Secret123!',
            'role_id' => Role::where('name', $role)->value('id'),
            'active' => $active,
        ]);

        if ($role === 'CLIENT') {
            $user->clientProfile()->create(['doc_number' => '01234567']);
        } elseif ($role === 'ORGANIZER') {
            $user->organizerProfile()->create(['tax_id' => '20123456789']);
        }

        return $user;
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
    public function test_valid_login_returns_the_user_and_a_signed_token_with_their_role(string $role): void
    {
        $user = $this->createUser($role);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123!',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'fullName', 'email', 'role']])
            ->assertJsonPath('user.id', (string) $user->id)
            ->assertJsonPath('user.role', $role)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.role_id')
            ->assertJsonMissingPath('user.active');

        if ($role === 'CLIENT') {
            $response->assertJsonPath('user.profile.docNumber', '01234567')
                ->assertJsonMissingPath('user.organizer');
        } elseif ($role === 'ORGANIZER') {
            $response->assertJsonPath('user.organizer.taxId', '20123456789')
                ->assertJsonMissingPath('user.profile');
        } else {
            $response->assertJsonMissingPath('user.profile')
                ->assertJsonMissingPath('user.organizer');
        }

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Decode through the JWT library so signature and claims are validated.
        $claims = JWTAuth::setToken($token)->getPayload()->toArray();
        $this->assertSame((string) $user->id, (string) $claims['sub']);
        $this->assertSame($role, $claims['role']);
        $this->assertSame([], array_diff(array_keys($claims), [
            'iss', 'iat', 'exp', 'nbf', 'sub', 'jti', 'prv', 'role',
        ]));
    }

    public static function invalidCredentials(): array
    {
        return [
            'wrong password' => ['login@example.com', 'Wrong123!'],
            'unknown email' => ['missing@example.com', 'Secret123!'],
        ];
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_return_the_same_401_without_a_token(string $email, string $password): void
    {
        $this->createUser();

        $this->postJson('/api/auth/login', compact('email', 'password'))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->assertGuest('api');
    }

    #[DataProvider('roles')]
    public function test_inactive_users_cannot_log_in_even_with_the_correct_password(string $role): void
    {
        $user = $this->createUser($role, false);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123!',
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->assertGuest('api');
    }

    public function test_login_uses_the_database_role_instead_of_the_submitted_role(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123!',
            'role' => 'ADMIN',
            'role_id' => Role::where('name', 'ADMIN')->value('id'),
        ])->assertOk()->assertJsonPath('user.role', 'CLIENT');

        $claims = JWTAuth::setToken($response->json('token'))->getPayload();
        $this->assertSame('CLIENT', $claims->get('role'));
        $this->assertSame((string) $user->id, (string) $claims->get('sub'));
        $this->assertSame('CLIENT', $user->fresh()->role->name);
    }

    public static function invalidFields(): array
    {
        return [
            'missing email' => ['email', null],
            'invalid email' => ['email', 'invalid'],
            'missing password' => ['password', null],
            'empty password' => ['password', ''],
            'numeric password' => ['password', 12345678],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_malformed_login_returns_422(string $field, mixed $value): void
    {
        $payload = ['email' => 'login@example.com', 'password' => 'Secret123!'];
        $payload[$field] = $value;

        $this->postJson('/api/auth/login', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field)
            ->assertJsonMissingPath('token');
        $this->assertGuest('api');
    }
}
