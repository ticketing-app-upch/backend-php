<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'initial_admin.name' => null,
            'initial_admin.email' => null,
            'initial_admin.password' => null,
        ]);
    }

    public function test_default_seeding_creates_roles_without_an_admin_when_credentials_are_missing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_seeding_creates_an_admin_and_does_not_reset_it_on_repeat(): void
    {
        config([
            'initial_admin.name' => 'Administradora Inicial',
            'initial_admin.email' => 'admin@example.com',
            'initial_admin.password' => 'Secure123!',
        ]);

        $this->seed(DatabaseSeeder::class);

        $user = User::sole();
        $this->assertSame('ADMIN', $user->role->name);
        $this->assertSame('Administradora Inicial', $user->full_name);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('Secure123!', $user->password));

        config(['initial_admin.password' => 'Another123!']);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Hash::check('Secure123!', User::sole()->password));
    }

    public function test_seeding_rejects_an_email_that_belongs_to_another_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('name', 'CLIENT')->firstOrFail()->id,
        ]);

        config([
            'initial_admin.name' => 'Administradora Inicial',
            'initial_admin.email' => 'admin@example.com',
            'initial_admin.password' => 'Secure123!',
        ]);

        $this->expectException(RuntimeException::class);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_incomplete_admin_configuration_is_rejected(): void
    {
        config(['initial_admin.email' => 'admin@example.com']);

        $this->expectException(ValidationException::class);
        $this->seed(DatabaseSeeder::class);
    }
}
