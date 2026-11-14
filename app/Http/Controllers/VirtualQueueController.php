<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Support\VirtualQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class VirtualQueueController extends Controller
{
    public function __construct(private VirtualQueue $queue) {}

    public function join(string $id, Request $request): JsonResponse
    {
        return response()->json($this->turn($id, $request, 'join'))->header('Cache-Control', 'private, no-store');
    }

    public function status(string $id, Request $request): JsonResponse
    {
        return response()->json($this->turn($id, $request, 'status'))->header('Cache-Control', 'private, no-store');
    }

    public function cancel(string $id, Request $request): Response
    {
        $this->turn($id, $request, 'cancel');

        return response()->noContent();
    }

    public function openPayment(string $id, Request $request): Response
    {
        $this->turn($id, $request, 'open-payment');

        return response()->noContent();
    }

    public function enablePurchases(string $id, Request $request): JsonResponse
    {
        $event = DB::transaction(function () use ($id, $request) {
            $user = User::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
            $event = Event::whereKey($id)->lockForUpdate()->first();

            abort_unless($event, 404, 'Evento no encontrado.');
            abort_unless($event->organizer_id === $user->id, 403, 'No tienes acceso a este evento.');
            abort_unless($user->active, 403, 'Tu cuenta está desactivada. Contacta al administrador');
            abort_if(
                $event->status !== Event::STATUS_PUBLISHED || ! $event->starts_at->isFuture()
                || ! $event->queue_enabled || $event->purchases_enabled_at !== null,
                409,
                'Las compras no se pueden habilitar para este evento.',
            );

            $event->purchases_enabled_at = now();
            $event->save();
            DB::afterCommit(fn () => $this->queue->notifyCommittedChange($event->id, advance: true));

            return $event;
        }, 3);

        $event->load(['zones', 'venue.zones'])->loadExists([
            'banner',
            'banner as secondary_banner_exists' => fn ($query) => $query->whereNotNull('secondary_content'),
        ]);

        return response()->json(new EventResource($event));
    }

    private function turn(string $id, Request $request, string $action): array
    {
        return DB::transaction(function () use ($id, $request, $action) {
            $user = User::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 403, 'Tu cuenta está desactivada. Contacta al administrador');

            $event = Event::whereKey($id)->where('status', Event::STATUS_PUBLISHED)->lockForUpdate()->first();
            abort_unless($event, 404, 'Evento no disponible');

            if ($action === 'open-payment') {
                abort_unless($event->starts_at->isFuture(), 404, 'Evento no disponible');
                if (! $event->queue_enabled) {
                    return [];
                }
                abort_if($event->purchases_enabled_at === null, 403, VirtualQueue::PURCHASES_DISABLED);

                return $this->queue->turn($event, $action, (string) $user->id, ['openedAt' => now()->timestamp]);
            }

            abort_unless($event->queue_enabled, 404, 'Este evento no tiene cola virtual disponible.');

            return $this->queue->turn($event, $action, (string) $user->id);
        }, 3);
    }
}
