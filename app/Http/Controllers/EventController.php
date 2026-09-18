<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\IndexEventRequest;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
}
