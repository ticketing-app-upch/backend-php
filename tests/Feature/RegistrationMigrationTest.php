<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_migrations_create_only_current_registration_columns_and_constraints(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'full_name', 'email', 'password', 'role_id', 'active', 'created_at', 'updated_at'],
            Schema::getColumnListing('users'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'doc_number', 'created_at', 'updated_at'],
            Schema::getColumnListing('client_profiles'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'tax_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('organizer_profiles'),
        );

        foreach ([
            'users' => ['email'],
            'client_profiles' => ['user_id', 'doc_number'],
            'organizer_profiles' => ['user_id', 'tax_id'],
        ] as $table => $uniqueColumns) {
            $indexes = Schema::getIndexes($table);
            $uniqueIndexes = array_filter($indexes, fn (array $index) => $index['unique']);

            foreach ($uniqueColumns as $column) {
                $this->assertContains([$column], array_column($uniqueIndexes, 'columns'));
            }
        }

        $this->assertContains(['role_id'], array_column(Schema::getForeignKeys('users'), 'columns'));
        $this->assertContains(['user_id'], array_column(Schema::getForeignKeys('client_profiles'), 'columns'));
        $this->assertContains(['user_id'], array_column(Schema::getForeignKeys('organizer_profiles'), 'columns'));
    }

    public function test_dni_uniqueness_is_enforced_by_the_database(): void
    {
        $this->seed(RoleSeeder::class);
        $roleId = Role::where('name', 'CLIENT')->value('id');
        $first = User::factory()->create(['role_id' => $roleId]);
        $second = User::factory()->create(['role_id' => $roleId]);
        $first->clientProfile()->create(['doc_number' => '01234567']);

        $this->expectException(QueryException::class);
        $second->clientProfile()->create(['doc_number' => '01234567']);
    }

    public function test_ruc_uniqueness_is_enforced_by_the_database(): void
    {
        $this->seed(RoleSeeder::class);
        $roleId = Role::where('name', 'ORGANIZER')->value('id');
        $first = User::factory()->create(['role_id' => $roleId]);
        $second = User::factory()->create(['role_id' => $roleId]);
        $first->organizerProfile()->create(['tax_id' => '20123456789']);

        $this->expectException(QueryException::class);
        $second->organizerProfile()->create(['tax_id' => '20123456789']);
    }
}
