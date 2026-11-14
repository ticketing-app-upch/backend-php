<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCapacityException;
use App\Http\Requests\Event\IndexEventRequest;
use App\Http\Requests\Event\PurchaseTicketRequest;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\SalesReportResource;
use App\Mail\PurchaseConfirmed;
use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\User;
use App\Models\Venue;
use App\Support\VirtualQueue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function index(IndexEventRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = Event::with(['zones', 'venue.zones'])->withExists(['banner',
            'banner as secondary_banner_exists' => fn ($query) => $query->whereNotNull('secondary_content')])
            ->where('status', 'PUBLISHED')->where('starts_at', '>', now())
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereRaw('LOWER(category) = ?', [mb_strtolower($category)]))
            ->when($filters['venue'] ?? null, function ($query, $venue) {
                $needle = '%'.mb_strtolower($venue).'%';
                $query->where(fn ($query) => $query->whereRaw('LOWER(venue_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(venue_address) LIKE ?', [$needle]));
            })
            ->when($filters['search'] ?? null, function ($query, $search) {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))).'%';
                $query->where(fn ($query) => $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("LOWER(venue_name) LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("LOWER(city) LIKE ? ESCAPE '!'", [$needle]));
            })
            ->when($request->boolean('available'), fn ($query) => $query->whereHas('zones', fn ($zones) => $zones->where('available_capacity', '>', 0)))
            ->when($filters['date'] ?? null, function ($query, $date) {
                $day = CarbonImmutable::parse($date, config('ticketing.venue_timezone'))->startOfDay();
                $query->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc());
            });

        $user = $request->user('api');
        if ($user && $user->role->name === 'CLIENT') {
            $query->withExists(['favoritedBy as is_favorite' => fn ($query) => $query->where('users.id', $user->id)]);
        }

        return response()->json(EventResource::collection($query->orderBy('starts_at')->get()));
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        return $this->persistEvent($request);
    }

    public function mine(Request $request): JsonResponse
    {
        $events = $request->user('api')->events()->with(['zones', 'venue.zones'])
            ->withExists(['banner', 'banner as secondary_banner_exists' => fn ($query) => $query->whereNotNull('secondary_content')])
            ->orderBy('starts_at')->get();

        return response()->json(EventResource::collection($events));
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $event = Event::with(['zones', 'venue.zones'])->withExists(['banner',
            'banner as secondary_banner_exists' => fn ($query) => $query->whereNotNull('secondary_content')])
            ->where('status', 'PUBLISHED')->find($id);
        if ($event) {
            return response()->json(new EventResource($event));
        }

        $owner = $request->bearerToken() ? $request->user('api') : null;
        $private = $owner?->role->name === 'ORGANIZER' ? Event::with(['zones', 'venue.zones', 'banner'])
            ->where('organizer_id', $owner->id)
            ->whereIn('status', [Event::STATUS_PENDING_APPROVAL, Event::STATUS_REJECTED])
            ->find($id) : null;

        return $private
            ? response()->json(new EventResource($private))->header('Cache-Control', 'private, no-store')
            : response()->json(['message' => 'Evento no disponible'], 404);
    }

    public function banner(string $id): Response
    {
        return $this->image($id, false);
    }

    public function secondaryBanner(string $id): Response
    {
        return $this->image($id, true);
    }

    private function image(string $id, bool $secondary): Response
    {
        $event = Event::where('status', 'PUBLISHED')->find($id);
        abort_unless($event, 404, 'Evento no disponible');
        $banner = $event->banner;
        abort_unless($banner, 404, 'Banner no encontrado.');

        $content = $secondary ? $banner->secondary_content : $banner->content;
        abort_unless($content, 404, 'Banner no encontrado.');

        return response(base64_decode($content), 200, [
            'Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }

    public function update(
        string $id,
        UpdateEventRequest $request,
    ): JsonResponse {
        return $this->persistEvent($request, $id);
    }

    public function byOrganizer(string $organizerId, Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_unless($user->role->name === 'ADMIN' || (string) $user->id === $organizerId, 403, 'No tienes acceso a estos eventos.');
        $events = Event::with(['zones', 'venue.zones'])->where('organizer_id', $organizerId)
            ->withExists(['banner', 'banner as secondary_banner_exists' => fn ($query) => $query->whereNotNull('secondary_content')])
            ->orderBy('starts_at')->get();

        return response()->json(EventResource::collection($events));
    }

    public function all(): JsonResponse
    {
        $events = Event::with(['zones', 'venue.zones'])->withExists(['banner',
            'banner as secondary_banner_exists' => fn ($query) => $query->whereNotNull('secondary_content')])
            ->orderByDesc('starts_at')->get();

        return response()->json(EventResource::collection($events));
    }

    public function destroy(string $id, Request $request): Response
    {
        DB::transaction(function () use ($id, $request) {
            $event = Event::whereKey($id)->lockForUpdate()->first();
            abort_unless($event, 404, 'Evento no disponible');
            abort_unless($request->user('api')->role->name === 'ADMIN' || $event->organizer_id === $request->user('api')->id,
                403, 'No tienes permisos para eliminar este evento.');
            $zones = $event->zones()->orderBy('id')->lockForUpdate()->get();
            abort_if($zones->contains(fn ($zone) => $zone->available_capacity < $zone->capacity)
                || Ticket::whereIn('event_zone_id', $zones->pluck('id'))->exists()
                || TicketOrder::where('event_id', $id)->exists(), 409,
                'Un evento con ventas debe conservarse para proteger el historial de entradas.');
            $event->delete();
        });

        return response('', 204);
    }

    public function purchaseTickets(string $id, PurchaseTicketRequest $request, VirtualQueue $queue): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? [['zoneId' => $data['zoneId'], 'quantity' => $data['quantity']]];
        try {
            $tickets = DB::transaction(function () use ($request, $queue, $data, $items, $id) {
                $user = User::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
                if (! $user->active) {
                    abort(403, 'Tu cuenta está desactivada. Contacta al administrador');
                }
                $event = Event::whereKey($id)
                    ->where('status', 'PUBLISHED')
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $event !== null && $event->starts_at->isFuture(),
                    404,
                    'Evento no disponible',
                );
                $turn = $queue->authorizePurchase($event, (string) $user->id, $data['queuePass'] ?? null);

                if (array_sum(array_column($items, 'quantity')) > min(6, $event->max_per_order)) {
                    throw ValidationException::withMessages(['items' => 'La cantidad supera el límite del evento.']);
                }
                // Cuenta entradas de ambas rutas, incluidas las históricas sin orden.
                $purchasedQuantity = (int) $user->tickets()
                    ->whereHas('eventZone', fn ($zones) => $zones->where('event_id', $event->id))
                    ->where(fn ($tickets) => $tickets->whereNull('ticket_order_id')
                        ->orWhereHas('order', fn ($orders) => $orders->where('status', 'CONFIRMADA')))
                    ->sum('quantity');
                abort_if($purchasedQuantity + $requestedQuantity > 6, 409,
                    'La compra supera el máximo de 6 entradas por usuario para este evento.');
                // Orden estable: dos compras multizona toman los bloqueos en el mismo orden.
                $zones = EventZone::where('event_id', $event->id)->whereIn('id', array_column($items, 'zoneId'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($zones->count() !== count($items)) {
                    throw ValidationException::withMessages(['items' => 'Todas las zonas deben pertenecer al evento.']);
                }
                foreach ($items as $item) {
                    if ($zones[$item['zoneId']]->available_capacity < $item['quantity']) {
                        throw new InsufficientCapacityException;
                    }
                }
                // El pago es simulado: formatos validados; no se guardan tarjeta ni CVV.
                $lines = collect($items)->map(fn ($item) => [
                    'zoneId' => (string) $item['zoneId'], 'zoneName' => $zones[$item['zoneId']]->name,
                    'unitPrice' => (float) $zones[$item['zoneId']]->base_price, 'quantity' => (int) $item['quantity'],
                ])->values()->all();
                $total = round(collect($lines)->sum(fn ($line) => $line['unitPrice'] * $line['quantity']), 2);
                $order = TicketOrder::create([
                    'code' => 'TICK-'.now()->year.'-'.strtoupper(bin2hex(random_bytes(8))),
                    'user_id' => $user->id, 'event_id' => $event->id, 'buyer_name' => $user->full_name,
                    'event_name' => $event->title, 'event_starts_at' => $event->starts_at,
                    'event_venue' => $event->venue_name.($event->city ? ', '.$event->city : ''),
                    'status' => 'CONFIRMADA', 'payment_method' => 'CARD', 'lines' => $lines,
                    'subtotal' => $total, 'fee' => 0, 'total' => $total,
                ]);
                $tickets = new Collection;
                foreach ($items as $item) {
                    $zone = $zones[$item['zoneId']];
                    $zone->decrement('available_capacity', $item['quantity']);
                    for ($index = 0; $index < $item['quantity']; $index++) {
                        $tickets->push(Ticket::create([
                            'user_id' => $user->id, 'event_zone_id' => $zone->id, 'ticket_order_id' => $order->id,
                            'quantity' => 1, 'unit_price' => $zone->base_price, 'total_price' => $zone->base_price,
                            'code' => 'TICK-'.now()->year.'-'.strtoupper(bin2hex(random_bytes(8))),
                            'event_title' => $event->title, 'event_starts_at' => $event->starts_at,
                            'venue_name' => $event->venue_name, 'venue_address' => $event->venue_address,
                            'zone_name' => $zone->name,
                        ]));
                    }
                }

                $event->recordFirstSoldOut();
                if ($turn !== null) {
                    $queue->recordPurchase($event, $order->id, $turn);
                    DB::afterCommit(fn () => $queue->notifyCommittedChange($event->id));
                }

                PurchaseConfirmed::sendAfterCommit($order, $user->email);

                return $tickets->load('eventZone.event');
            }, 3);
        } catch (InsufficientCapacityException) {
            return response()->json(['message' => 'Aforo insuficiente para la cantidad solicitada'], 409);
        }

        return response()->json([
            'paymentStatus' => 'APPROVED', 'quantity' => $tickets->count(),
            'totalPrice' => round($tickets->sum('total_price'), 2),
            'tickets' => TicketResource::collection($tickets),
        ], 201);
    }

    public function salesReport(string $id, Request $request): JsonResponse
    {
        $event = Event::find($id);
        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }
        if ($event->organizer_id !== $request->user('api')->id) {
            return response()->json(['message' => 'No tienes permiso para consultar este reporte.'], 403);
        }
        if ($event->status !== Event::STATUS_PUBLISHED) {
            return response()->json([
                'message' => 'El reporte solo está disponible para eventos publicados.',
            ], 403);
        }
        $event->load(['zones' => fn ($query) => $query
            ->withSum(['tickets as tickets_sold' => fn ($tickets) => $tickets->where(fn ($valid) => $valid
                ->whereNull('ticket_order_id')->orWhereHas('order', fn ($orders) => $orders->where('status', 'CONFIRMADA')))], 'quantity')
            ->withSum(['tickets as revenue' => fn ($tickets) => $tickets->where(fn ($valid) => $valid
                ->whereNull('ticket_order_id')->orWhereHas('order', fn ($orders) => $orders->where('status', 'CONFIRMADA')))], 'total_price')->orderBy('id')]);

        return response()->json(new SalesReportResource($event));
    }

    private function persistEvent(
        StoreEventRequest $request,
        ?string $id = null,
    ): JsonResponse {
        $data = $request->validated();

        $event = DB::transaction(function () use ($request, $data, $id) {
            $organizer = User::whereKey($request->user('api')->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $organizer->active,
                403,
                'Tu cuenta está desactivada. Contacta al administrador',
            );

            $snapshot = $id === null ? null : Event::find($id);

            if ($id !== null) {
                abort_unless($snapshot, 404, 'Evento no encontrado.');
            }

            $frontend = $request->usesFrontendContract();

            $venueQuery = Venue::query();

            $selectedVenue = ($frontend
                ? $venueQuery->where('name', $data['venue'])
                    ->whereNotNull('slug')
                : $venueQuery->whereKey($data['venueId']))
                ->firstOrFail();

            // Al cambiar de recinto, bloquear ambos en el mismo orden.
            $venueIds = array_values(array_unique(array_filter([
                $selectedVenue->id,
                $snapshot?->venue_id,
            ], fn ($value) => $value !== null)));

            $venues = Venue::with('zones')
                ->whereIn('id', $venueIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $venue = $venues->firstWhere('id', $selectedVenue->id);

            abort_unless(
                $venue,
                409,
                'El recinto cambió. Actualiza la información e intenta nuevamente.',
            );

            $event = null;

            if ($id !== null) {
                $event = Event::whereKey($id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($event, 404, 'Evento no encontrado.');

                // Revalidar dentro de la transacción, después de los bloqueos.
                abort_unless(
                    (string) $event->organizer_id === (string) $organizer->id,
                    403,
                    'No tienes permiso para editar este evento.',
                );

                abort_unless(
                    $event->status === Event::STATUS_REJECTED,
                    403,
                    'Solo se pueden editar eventos rechazados.',
                );

                abort_if(
                    $event->venue_id !== $snapshot->venue_id,
                    409,
                    'El evento cambió. Actualiza la información antes de editarlo.',
                );

                $zoneIds = $event->zones()->pluck('id');

                abort_if(
                    TicketOrder::where('event_id', $event->id)->exists()
                        || Ticket::whereIn('event_zone_id', $zoneIds)->exists()
                        || $event->zones()
                            ->whereColumn('available_capacity', '<>', 'capacity')
                            ->exists(),
                    409,
                    'Un evento con historial de ventas no puede modificarse.',
                );
            }

            if ($frontend) {
                if ($id === null && ! empty($data['id'])) {
                    throw ValidationException::withMessages([
                        'id' => 'Un evento creado no se puede modificar.',
                    ]);
                }

                if (
                    $data['city'] !== $venue->city
                    || (int) $data['venueCapacity'] !== $venue->capacity
                ) {
                    throw ValidationException::withMessages([
                        'venue' => 'La ciudad y el aforo deben coincidir con el catálogo del recinto.',
                    ]);
                }

                $byName = collect($data['zones'])->keyBy('name');

                if (
                    $byName->count() !== $venue->zones->count()
                    || $venue->zones->contains(
                        fn ($zone) => ! isset($byName[$zone->name])
                            || (int) $byName[$zone->name]['capacity']
                                !== $zone->capacity
                    )
                ) {
                    throw ValidationException::withMessages([
                        'zones' => 'Las zonas y aforos deben coincidir exactamente con el recinto.',
                    ]);
                }

                $prices = $venue->zones->mapWithKeys(
                    fn ($zone) => [
                        $zone->id => [
                            'basePrice' => $byName[$zone->name]['price'],
                        ],
                    ]
                );
            } else {
                $prices = collect($data['zones'])->keyBy('venueZoneId');
            }

            if (
                $prices->count() !== $venue->zones->count()
                || $venue->zones->pluck('id')->diff($prices->keys())->isNotEmpty()
            ) {
                throw ValidationException::withMessages([
                    'zones' => 'Debes indicar el precio de cada zona del recinto seleccionado.',
                ]);
            }

            $startsAt = CarbonImmutable::parse($data['startsAt']);

            // La fecha puede vencer mientras la petición espera un bloqueo.
            if ($startsAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'startsAt' => 'La fecha del evento debe ser futura.',
                ]);
            }

            $conflicts = Event::where('venue_id', $venue->id)
                ->whereIn('status', [
                    Event::STATUS_PENDING_APPROVAL,
                    Event::STATUS_PUBLISHED,
                ]);

            if ($event !== null) {
                $conflicts->where('id', '!=', $event->id);
            }

            $conflicts
                ->where('starts_at', '>', $startsAt->utc()->subHours(24))
                ->where('starts_at', '<', $startsAt->utc()->addHours(24));

            $conflict = $conflicts
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id']);

            abort_if(
                $conflict !== null,
                409,
                'El recinto ya tiene un evento con menos de 24 horas de diferencia.',
            );

            $attributes = [
                'organizer_id' => $organizer->id,
                'venue_id' => $venue->id,
                'title' => $frontend ? $data['name'] : $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'venue_name' => $venue->name,
                'venue_address' => $venue->address,
                'venue_capacity' => $venue->capacity,
                'starts_at' => $startsAt->utc(),
                'ends_at' => isset($data['endsAt'])
                    ? CarbonImmutable::parse($data['endsAt'])->utc()
                    : null,
                'status' => Event::STATUS_PENDING_APPROVAL,
                'queue_enabled' => $data['queueEnabled']
                    ?? ($event?->queue_enabled ?? false),
                'city' => $venue->city,
                'image_url' => $frontend
                    ? ($data['imageUrl'] ?? null)
                    : $event?->image_url,
                'published_at' => null,
                'max_per_order' => $frontend
                    ? $data['maxPerOrder']
                    : ($event?->max_per_order ?? 6),
                'rejection_reason' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ];

            if ($event === null) {
                $event = Event::create($attributes);
            } else {
                $event->update($attributes);

                // Solo se reemplazan zonas tras comprobar que no existe historial.
                $event->zones()->delete();
            }

            foreach ($venue->zones as $zone) {
                $event->zones()->create([
                    'venue_zone_id' => $zone->id,
                    'name' => $zone->name,
                    'capacity' => $zone->capacity,
                    'available_capacity' => $zone->capacity,
                    'base_price' => $prices[$zone->id]['basePrice'],
                ]);
            }

            $event->banner()->updateOrCreate([], [
                'content' => $frontend
                    ? substr(
                        $data['bannerUrl'],
                        strlen('data:image/jpeg;base64,'),
                    )
                    : base64_encode($request->file('banner')->getContent()),
                'secondary_content' => $frontend
                    ? substr(
                        $data['secondaryBannerUrl'],
                        strlen('data:image/jpeg;base64,'),
                    )
                    : base64_encode(
                        $request->file('secondaryBanner')->getContent()
                    ),
            ]);

            return $event;
        }, 3);

        $event->load(['zones', 'venue.zones'])->loadExists([
            'banner',
            'banner as secondary_banner_exists' => fn ($query) => $query
                ->whereNotNull('secondary_content'),
        ]);

        return response()->json(
            new EventResource($event),
            $id === null ? 201 : 200,
        );
    }

    public function showOwn(string $id, Request $request): JsonResponse
    {
        $event = Event::where('organizer_id', $request->user('api')->id)
            ->with(['zones', 'venue.zones'])
            ->withExists([
                'banner',
                'banner as secondary_banner_exists' => fn ($query) => $query
                    ->whereNotNull('secondary_content'),
            ])
            ->find($id);

        abort_unless($event, 404, 'Evento no encontrado.');

        return response()->json(new EventResource($event));
    }

    public function ownBanner(string $id, Request $request): Response
    {
        return $this->ownImage($id, $request, false);
    }

    public function ownSecondaryBanner(string $id, Request $request): Response
    {
        return $this->ownImage($id, $request, true);
    }

    private function ownImage(
        string $id,
        Request $request,
        bool $secondary,
    ): Response {
        $event = Event::where('organizer_id', $request->user('api')->id)
            ->find($id);

        abort_unless($event, 404, 'Evento no encontrado.');

        $banner = $event->banner;

        abort_unless($banner, 404, 'Banner no encontrado.');

        $content = $secondary
            ? $banner->secondary_content
            : $banner->content;

        abort_unless($content, 404, 'Banner no encontrado.');

        $bytes = base64_decode($content, true);

        abort_if($bytes === false, 404, 'Banner no disponible.');

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
