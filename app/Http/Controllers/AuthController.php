<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\ClientProfile;
use App\Models\OrganizerProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = trim(strtolower($data['email']));

        if (User::where('email', $email)->exists()) {
            return response()->json(['message' => 'El correo ya está registrado.'], 409);
        }

        if ($data['role'] === 'CLIENT') {
            $duplicateDoc = ClientProfile::where('doc_number', $data['profile']['docNumber'])->exists();

            if ($duplicateDoc) {
                return response()->json(['message' => 'Ese documento ya está registrado.'], 409);
            }
        }

        if ($data['role'] === 'ORGANIZER') {
            $duplicateTax = OrganizerProfile::where('tax_id', $data['organizer']['taxId'])->exists();

            if ($duplicateTax) {
                return response()->json(['message' => 'Ese RUC ya está registrado.'], 409);
            }
        }

        $role = Role::where('name', $data['role'])->firstOrFail();

        try {
            $user = DB::transaction(function () use ($data, $email, $role) {
                $user = User::create([
                    'full_name' => $data['fullName'],
                    'email' => $email,
                    'password' => $data['password'],
                    'role_id' => $role->id,
                    'active' => true,
                ]);

                if ($role->name === 'CLIENT') {
                    $user->clientProfile()->create([
                        'doc_number' => $data['profile']['docNumber'],
                    ]);
                } else {
                    $user->organizerProfile()->create([
                        'tax_id' => $data['organizer']['taxId'],
                    ]);
                }

                return $user;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateEntry($e)) {
                return response()->json(['message' => 'Ese dato ya está registrado.'], 409);
            }

            throw $e;
        }

        $user->load(['role', 'clientProfile', 'organizerProfile']);

        $token = auth('api')->login($user);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $credentials['email'] = trim(strtolower($credentials['email']));

        $token = auth('api')->attempt($credentials);

        if (! $token) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        $user = auth('api')->user();

        if (! $user->active) {
            auth('api')->logout();

            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        $user->load(['role', 'clientProfile', 'organizerProfile']);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    private function isDuplicateEntry(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
