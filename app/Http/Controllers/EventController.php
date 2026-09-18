<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\IndexEventRequest;
use App\Http\Requests\Event\PurchaseTicketRequest;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function index(IndexEventRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $events = Event::query()
            ->with('zones')
            ->where('status', 'PUBLISHED')
            ->where('starts_at', '>', now())
            ->when($filters['category'] ?? null, function ($query, $category) {
                $query->whereRaw('LOWER(category) = ?', [mb_strtolower($category)]);
            })
            ->when($filters['venue'] ?? null, function ($query, $venue) {
                $needle = '%'.mb_strtolower($venue).'%';

                $query->where(function ($query) use ($needle) {
                    $query->whereRaw('LOWER(venue_name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(venue_address) LIKE ?', [$needle]);
                });
            })
            ->when($filters['date'] ?? null, function ($query, $date) {
                $query->whereDate('starts_at', $date);
            })
            ->orderBy('starts_at')
            ->get();

        return response()->json(EventResource::collection($events));
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $organizer = $request->user('api');

        $event = DB::transaction(function () use ($data, $organizer) {
            $event = Event::create([
                'organizer_id' => $organizer->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'venue_name' => $data['venueName'],
                'venue_address' => $data['venueAddress'],
                'venue_capacity' => $data['venueCapacity'],
                'starts_at' => Carbon::parse($data['startsAt'])->utc(),
                'ends_at' => isset($data['endsAt']) ? Carbon::parse($data['endsAt'])->utc() : null,
                'status' => 'PUBLISHED',
            ]);

            foreach ($data['zones'] as $zone) {
                $event->zones()->create([
                    'name' => $zone['name'],
                    'capacity' => $zone['capacity'],
                    'available_capacity' => $zone['capacity'],
                    'base_price' => $zone['basePrice'],
                ]);
            }

            return $event;
        });

        $event->load('zones');

        return response()->json(new EventResource($event), 201);
    }

    public function show(string $id): JsonResponse
    {
        $event = Event::with('zones')->find($id);

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado.'], 404);
        }

        return response()->json(new EventResource($event));
    }

    public function purchaseTickets(string $id, PurchaseTicketRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user('api');

        try {
            $ticket = DB::transaction(function () use ($data, $user, $id) {
                $zone = DB::table('event_zones')
                    ->where('id', $data['zoneId'])
                    ->where('event_id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $zone || $zone->available_capacity < $data['quantity']) {
                    throw new \App\Exceptions\InsufficientCapacityException();
                }

                DB::table('event_zones')
                    ->where('id', $zone->id)
                    ->decrement('available_capacity', $data['quantity']);

                $totalPrice = round($zone->base_price * $data['quantity'], 2);

                return Ticket::create([
                    'user_id' => $user->id,
                    'event_zone_id' => $zone->id,
                    'quantity' => $data['quantity'],
                    'total_price' => $totalPrice,
                    'code' => Str::uuid()->toString(),
                ]);
            });
        } catch (\App\Exceptions\InsufficientCapacityException) {
            return response()->json(['message' => 'No hay suficiente capacidad disponible en esa zona.'], 409);
        }

        $ticket->load('eventZone.event');

        return response()->json(new TicketResource($ticket), 201);
    }
}
