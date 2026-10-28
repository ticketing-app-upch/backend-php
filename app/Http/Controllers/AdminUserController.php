<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function metrics(): JsonResponse
    {
        return response()->json([
            'users' => ['total' => User::count(),
                'clients' => User::whereHas('role', fn ($roles) => $roles->where('name', 'CLIENT'))->count(),
                'organizers' => User::whereHas('role', fn ($roles) => $roles->where('name', 'ORGANIZER'))->count(),
                'admins' => User::whereHas('role', fn ($roles) => $roles->where('name', 'ADMIN'))->count()],
            'events' => ['total' => Event::count(), 'published' => Event::where('status', 'PUBLISHED')->count(),
                'drafts' => Event::where('status', 'DRAFT')->count()],
            'orders' => ['total' => TicketOrder::count(), 'confirmed' => TicketOrder::where('status', 'CONFIRMADA')->count(),
                'cancelled' => TicketOrder::where('status', 'CANCELADA')->count()],
            'ticketsSold' => (int) (EventZone::sum('capacity') - EventZone::sum('available_capacity')),
            'grossRevenue' => (float) TicketOrder::where('status', 'CONFIRMADA')->sum('total'),
        ]);
    }

    public function index(IndexUserRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $users = User::query()
            ->with('role')
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['CLIENT', 'ORGANIZER']))
            ->when($filters['role'] ?? null, function ($query, $role) {
                $query->whereHas('role', fn ($query) => $query->where('name', $role));
            })
            ->when(array_key_exists('active', $filters), function ($query) use ($filters) {
                $query->where('active', $filters['active']);
            })
            ->orderBy('id')
            ->get();

        return response()->json(AdminUserResource::collection($users));
    }

    public function updateStatus(string $id, UpdateUserStatusRequest $request): JsonResponse
    {
        $user = User::with('role')->find($id);

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if (! in_array($user->role->name, ['CLIENT', 'ORGANIZER'], true)) {
            return response()->json(['message' => 'No puedes cambiar el estado de una cuenta administradora.'], 403);
        }

        // Se asigna un estado explícito: repetir la petición no vuelve a alternarlo.
        $user->update(['active' => $request->validated('active')]);

        return response()->json(new AdminUserResource($user));
    }
}
