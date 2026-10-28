<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\FavoriteRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = $request->user('api')->favoriteEvents()->where('status', 'PUBLISHED')
            ->with(['zones', 'venue.zones'])->withExists('banner')->orderBy('starts_at')->get();
        $events->each(fn ($event) => $event->setAttribute('is_favorite', true));

        return response()->json(EventResource::collection($events));
    }

    public function store(FavoriteRequest $request): JsonResponse
    {
        $eventId = $request->validated('eventId');
        abort_unless(Event::where('status', 'PUBLISHED')->whereKey($eventId)->exists(), 404, 'Evento no disponible');
        DB::table('favorites')->upsert([
            ['user_id' => $request->user('api')->id, 'event_id' => $eventId,
                'created_at' => now(), 'updated_at' => now()],
        ], ['user_id', 'event_id'], ['updated_at']);

        return response()->json(['eventId' => (string) $eventId, 'isFavorite' => true]);
    }

    public function destroy(FavoriteRequest $request): JsonResponse
    {
        $eventId = $request->validated('eventId');
        DB::table('favorites')->where('user_id', $request->user('api')->id)->where('event_id', $eventId)->delete();

        return response()->json(['eventId' => (string) $eventId, 'isFavorite' => false]);
    }
}
