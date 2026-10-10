<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('initial_admin.name');
        $email = config('initial_admin.email');
        $password = config('initial_admin.password');

        if (blank($name) && blank($email) && blank($password)) {
            return;
        }

        Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/'],
        ])->validate();

        $adminRole = Role::where('name', 'ADMIN')->firstOrFail();

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'full_name' => $name,
                'password' => $password,
                'role_id' => $adminRole->id,
                'active' => true,
            ],
        );

        if ($user->role_id !== $adminRole->id) {
            throw new RuntimeException('El correo configurado para el administrador ya pertenece a otro rol.');
        }
    }
}
