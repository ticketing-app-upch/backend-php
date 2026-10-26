<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCapacityException;
use App\Http\Requests\Event\IndexEventRequest;
use App\Http\Requests\Event\PurchaseTicketRequest;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\SalesReportResource;
use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
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
        $query = Event::with(['zones', 'venue.zones'])->withExists('banner')
            ->where('status', 'PUBLISHED')->where('starts_at', '>', now())
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereRaw('LOWER(category) = ?', [mb_strtolower($category)]))
            ->when($filters['venue'] ?? null, function ($query, $venue) {
                $needle = '%'.mb_strtolower($venue).'%';
                $query->where(fn ($query) => $query->whereRaw('LOWER(venue_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(venue_address) LIKE ?', [$needle]));
            })
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
        $data = $request->validated();
        $event = DB::transaction(function () use ($data, $request) {
            $organizer = User::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
            if (! $organizer->active) {
                abort(403, 'Tu cuenta está desactivada. Contacta al administrador');
            }
            // Serializa el control de fechas de todas las creaciones del mismo recinto.
            $venue = Venue::with('zones')->whereKey($data['venueId'])->lockForUpdate()->firstOrFail();
            $prices = collect($data['zones'])->keyBy('venueZoneId');
            if ($prices->count() !== $venue->zones->count()
                || $venue->zones->pluck('id')->diff($prices->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['zones' => 'Debes indicar el precio de cada zona del recinto seleccionado.']);
            }
            $startsAt = CarbonImmutable::parse($data['startsAt']);
            $day = $startsAt->setTimezone(config('ticketing.venue_timezone'))->startOfDay();
            if (Event::where('venue_id', $venue->id)
                ->where('starts_at', '>=', $day->utc())
                ->where('starts_at', '<', $day->addDay()->utc())->exists()) {
                abort(409, 'El recinto ya tiene un evento en ese día calendario.');
            }
            $event = Event::create([
                'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
                'title' => $data['title'], 'description' => $data['description'],
                'category' => $data['category'], 'venue_name' => $venue->name,
                'venue_address' => $venue->address, 'venue_capacity' => $venue->capacity,
                'starts_at' => $startsAt->utc(),
                'ends_at' => isset($data['endsAt']) ? CarbonImmutable::parse($data['endsAt'])->utc() : null,
                'status' => 'PUBLISHED',
            ]);
            foreach ($venue->zones as $zone) {
                $event->zones()->create([
                    'venue_zone_id' => $zone->id, 'name' => $zone->name,
                    'capacity' => $zone->capacity, 'available_capacity' => $zone->capacity,
                    'base_price' => $prices[$zone->id]['basePrice'],
                ]);
            }
            $event->banner()->create(['content' => base64_encode($request->file('banner')->getContent())]);

            return $event;
        });

        return response()->json(new EventResource($event->load(['zones', 'venue.zones'])->loadExists('banner')), 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $events = $request->user('api')->events()->with(['zones', 'venue.zones'])
            ->withExists('banner')->orderBy('starts_at')->get();

        return response()->json(EventResource::collection($events));
    }

    public function show(string $id): JsonResponse
    {
        $event = Event::with(['zones', 'venue.zones'])->withExists('banner')
            ->where('status', 'PUBLISHED')->find($id);
        if (! $event) {
            return response()->json(['message' => 'Evento no disponible'], 404);
        }

        return response()->json(new EventResource($event));
    }

    public function banner(string $id): Response
    {
        $event = Event::where('status', 'PUBLISHED')->find($id);
        abort_unless($event, 404, 'Evento no disponible');
        $banner = $event->banner;
        abort_unless($banner, 404, 'Banner no encontrado.');

        return response(base64_decode($banner->content), 200, [
            'Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }

    public function update(string $id): JsonResponse
    {
        abort_unless(Event::whereKey($id)->exists(), 404, 'Evento no disponible');

        return response()->json(['message' => 'Los eventos no se pueden modificar en Demo 3.'], 403);
    }

    public function purchaseTickets(string $id, PurchaseTicketRequest $request): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? [['zoneId' => $data['zoneId'], 'quantity' => $data['quantity']]];
        try {
            $tickets = DB::transaction(function () use ($request, $items, $id) {
                $user = User::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
                if (! $user->active) {
                    abort(403, 'Tu cuenta está desactivada. Contacta al administrador');
                }
                $event = Event::where('status', 'PUBLISHED')->where('starts_at', '>', now())->find($id);
                abort_unless($event, 404, 'Evento no disponible');
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
                $tickets = new Collection;
                foreach ($items as $item) {
                    $zone = $zones[$item['zoneId']];
                    $zone->decrement('available_capacity', $item['quantity']);
                    for ($index = 0; $index < $item['quantity']; $index++) {
                        $tickets->push(Ticket::create([
                            'user_id' => $user->id, 'event_zone_id' => $zone->id,
                            'quantity' => 1, 'unit_price' => $zone->base_price, 'total_price' => $zone->base_price,
                            'code' => 'TICK-'.now()->year.'-'.strtoupper(bin2hex(random_bytes(8))),
                            'event_title' => $event->title, 'event_starts_at' => $event->starts_at,
                            'venue_name' => $event->venue_name, 'venue_address' => $event->venue_address,
                            'zone_name' => $zone->name,
                        ]));
                    }
                }

                return $tickets->load('eventZone.event');
            });
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
        $event->load(['zones' => fn ($query) => $query
            ->withSum('tickets as tickets_sold', 'quantity')
            ->withSum('tickets as revenue', 'total_price')->orderBy('id')]);

        return response()->json(new SalesReportResource($event));
    }
}
