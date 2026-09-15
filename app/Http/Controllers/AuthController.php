<?php

namespace App\Http\Controllers;

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
            $duplicateDoc = ClientProfile::where('doc_type', $data['profile']['docType'])
                ->where('doc_number', $data['profile']['docNumber'])
                ->exists();

            if ($duplicateDoc) {
                return response()->json(['message' => 'Ese documento ya está registrado.'], 409);
            }
        }

        if ($data['role'] === 'ORGANIZER') {
            $duplicateTax = OrganizerProfile::where('tax_id', $data['organizer']['taxId'])->exists();

            if ($duplicateTax) {
                return response()->json(['message' => 'Ese RUC/DNI ya está registrado.'], 409);
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
                    'marketing_opt_in' => $data['marketingOptIn'] ?? false,
                    'active' => true,
                    'accepted_terms_at' => now(),
                ]);

                if ($role->name === 'CLIENT') {
                    $user->clientProfile()->create([
                        'country' => $data['profile']['country'],
                        'city' => $data['profile']['city'],
                        'district' => $data['profile']['district'] ?? null,
                        'has_peruvian_nationality' => $data['profile']['hasPeruvianNationality'],
                        'doc_type' => $data['profile']['docType'],
                        'doc_number' => $data['profile']['docNumber'],
                        'gender' => $data['profile']['gender'],
                        'phone_code' => $data['profile']['phoneCode'],
                        'phone' => $data['profile']['phone'],
                    ]);
                } else {
                    $user->organizerProfile()->create([
                        'org_type' => $data['organizer']['orgType'],
                        'display_name' => $data['organizer']['displayName'],
                        'tax_id' => $data['organizer']['taxId'],
                        'legal_name' => $data['organizer']['legalName'] ?? null,
                        'rep_name' => $data['organizer']['repName'],
                        'phone' => $data['organizer']['phone'],
                        'country' => $data['organizer']['country'],
                        'city' => $data['organizer']['city'] ?? null,
                        'website' => $data['organizer']['website'] ?? null,
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

    private function isDuplicateEntry(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
