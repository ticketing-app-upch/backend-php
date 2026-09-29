<?php

namespace Tests\Feature;

use App\Models\ClientProfile;
use App\Models\OrganizerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => str_repeat('test-only-secret', 4)]);
        $this->seed(RoleSeeder::class);
    }

    private function payload(string $role = 'CLIENT'): array
    {
        return [
            'fullName' => 'Juan Perez',
            'email' => 'juan@example.com',
            'password' => 'Secret123!',
            'role' => $role,
            ...($role === 'CLIENT'
                ? ['profile' => ['docNumber' => '01234567']]
                : ['organizer' => ['taxId' => '20123456789']]),
        ];
    }

    public function test_minimal_client_registration_and_login(): void
    {
        $payload = $this->payload();
        $response = $this->postJson('/api/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('user.role', 'CLIENT')
            ->assertJsonMissingPath('user.profile.docType')
            ->assertJsonPath('user.profile.docNumber', '01234567')
            ->assertJsonMissingPath('user.profile.city')
            ->assertJsonMissingPath('user.profile.hasPeruvianNationality')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.organizer');

        $this->assertNotEmpty($response->json('token'));
        $user = User::sole();
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertSame($user->id, ClientProfile::sole()->user_id);
        $this->assertDatabaseCount('organizer_profiles', 0);

        auth('api')->forgetUser();
        $this->postJson('/api/auth/login', [
            'email' => $payload['email'], 'password' => $payload['password'],
        ])->assertOk()->assertJsonPath('user.profile.docNumber', '01234567');
    }

    public function test_minimal_organizer_registration_and_login(): void
    {
        $payload = $this->payload('ORGANIZER');
        $this->postJson('/api/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('user.organizer.taxId', '20123456789')
            ->assertJsonMissingPath('user.organizer.displayName')
            ->assertJsonMissingPath('user.organizer.orgType')
            ->assertJsonMissingPath('user.organizer.verificationStatus')
            ->assertJsonMissingPath('user.profile')
            ->assertJsonMissingPath('user.password');

        $this->assertSame(User::sole()->id, OrganizerProfile::sole()->user_id);
        $this->assertDatabaseCount('client_profiles', 0);
        auth('api')->forgetUser();
        $this->postJson('/api/auth/login', [
            'email' => $payload['email'], 'password' => $payload['password'],
        ])->assertOk()->assertJsonPath('user.organizer.taxId', '20123456789');
    }

    public static function invalidFields(): array
    {
        return [
            'missing name' => ['CLIENT', 'fullName', null],
            'oversized name' => ['CLIENT', 'fullName', str_repeat('a', 256)],
            'invalid email' => ['CLIENT', 'email', 'invalid'],
            'weak password' => ['CLIENT', 'password', '12345678'],
            'public admin' => ['CLIENT', 'role', 'ADMIN'],
            'missing DNI' => ['CLIENT', 'profile.docNumber', null],
            'short DNI' => ['CLIENT', 'profile.docNumber', '1234567'],
            'long DNI' => ['CLIENT', 'profile.docNumber', '123456789'],
            'alphabetic DNI' => ['CLIENT', 'profile.docNumber', '1234567A'],
            'numeric DNI' => ['CLIENT', 'profile.docNumber', 12345678],
            'other document' => ['CLIENT', 'profile.docType', 'CE'],
            'missing RUC' => ['ORGANIZER', 'organizer.taxId', null],
            'DNI as RUC' => ['ORGANIZER', 'organizer.taxId', '12345678'],
            'long RUC' => ['ORGANIZER', 'organizer.taxId', '201234567890'],
            'alphabetic RUC' => ['ORGANIZER', 'organizer.taxId', '2012345678A'],
            'invalid RUC prefix' => ['ORGANIZER', 'organizer.taxId', '99123456789'],
            'numeric RUC' => ['ORGANIZER', 'organizer.taxId', 20123456789],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_registration_creates_no_records(string $role, string $field, mixed $value): void
    {
        $payload = $this->payload($role);
        data_set($payload, $field, $value);

        $this->postJson('/api/auth/register', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('client_profiles', 0);
        $this->assertDatabaseCount('organizer_profiles', 0);
    }

    public function test_duplicate_email_is_case_insensitive(): void
    {
        $this->postJson('/api/auth/register', $this->payload())->assertCreated();
        $payload = $this->payload('ORGANIZER');
        $payload['email'] = 'JUAN@example.com';
        $this->postJson('/api/auth/register', $payload)->assertConflict();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizer_profiles', 0);
    }

    public function test_profile_failure_rolls_back_user_creation(): void
    {
        $dispatcher = ClientProfile::getEventDispatcher();
        ClientProfile::setEventDispatcher(clone $dispatcher);
        ClientProfile::creating(function () {
            throw new \RuntimeException('Simulated profile failure');
        });

        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/auth/register', $this->payload());
            $this->fail('Expected the profile insert to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated profile failure', $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('client_profiles', 0);
        } finally {
            ClientProfile::setEventDispatcher($dispatcher);
        }
    }

    public function test_duplicate_dni_creates_no_orphan_user(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/auth/register', $payload)->assertCreated();
        $payload['email'] = 'another@example.com';
        $this->postJson('/api/auth/register', $payload)->assertConflict();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('client_profiles', 1);
    }

    public function test_duplicate_ruc_creates_no_orphan_user(): void
    {
        $payload = $this->payload('ORGANIZER');
        $this->postJson('/api/auth/register', $payload)->assertCreated();
        $payload['email'] = 'another@example.com';
        $this->postJson('/api/auth/register', $payload)->assertConflict();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizer_profiles', 1);
    }

    public function test_removed_fields_are_ignored_and_not_returned(): void
    {
        $payload = $this->payload();
        $payload['acceptedTerms'] = true;
        $payload['marketingOptIn'] = true;
        $payload['profile'] += [
            'country' => 'PE', 'city' => 'Lima', 'district' => 'Miraflores',
            'hasPeruvianNationality' => true, 'gender' => 'M',
            'phoneCode' => '+51', 'phone' => '987654321',
        ];

        $response = $this->postJson('/api/auth/register', $payload)->assertCreated()
            ->assertJsonMissingPath('user.marketingOptIn')
            ->assertJsonMissingPath('user.acceptedTerms');
        foreach (array_keys($payload['profile']) as $field) {
            if ($field !== 'docNumber') {
                $response->assertJsonMissingPath("user.profile.{$field}");
            }
        }
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'doc_number', 'created_at', 'updated_at'],
            array_keys(ClientProfile::sole()->getAttributes()),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'full_name', 'email', 'password', 'role_id', 'active', 'created_at', 'updated_at'],
            array_keys(User::sole()->getAttributes()),
        );
    }

    public function test_removed_organizer_fields_are_ignored_and_not_returned(): void
    {
        $payload = $this->payload('ORGANIZER');
        $payload['organizer'] += [
            'orgType' => 'EMPRESA', 'displayName' => 'Eventos',
            'legalName' => 'Eventos SAC', 'repName' => 'Juan Perez',
            'country' => 'PE', 'city' => 'Lima', 'phone' => '987654321',
            'website' => 'https://example.com',
        ];
        $response = $this->postJson('/api/auth/register', $payload)->assertCreated();
        foreach (array_keys($payload['organizer']) as $field) {
            if ($field !== 'taxId') {
                $response->assertJsonMissingPath("user.organizer.{$field}");
            }
        }
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'tax_id', 'created_at', 'updated_at'],
            array_keys(OrganizerProfile::sole()->getAttributes()),
        );
    }

    public function test_untrusted_fields_cannot_override_role_or_create_another_profile(): void
    {
        $payload = $this->payload('ORGANIZER');
        $payload['role_id'] = 1;
        $payload['organizer']['verificationStatus'] = 'VERIFIED';
        $payload['profile'] = ['docNumber' => '01234567'];

        $this->postJson('/api/auth/register', $payload)->assertCreated()
            ->assertJsonPath('user.role', 'ORGANIZER')
            ->assertJsonMissingPath('user.organizer.verificationStatus');
        $this->assertDatabaseCount('client_profiles', 0);
    }
}
