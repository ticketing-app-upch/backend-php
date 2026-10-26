<?php

namespace App\Http\Controllers;

use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = $request->user('api')->tickets()->with('eventZone.event')->orderByDesc('id')->get();

        return response()->json(TicketResource::collection($tickets));
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $ticket = $request->user('api')->tickets()->with('eventZone.event')->find($id);
        abort_unless($ticket, 404, 'Ticket no encontrado');

        return response()->json(new TicketResource($ticket));
    }

    public function validatePublic(string $code): JsonResponse
    {
        $ticket = Ticket::with('eventZone.event')->where('code', $code)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Ticket no encontrado'], 404);
        }

        return response()->json(['message' => 'Confirmación de compra', 'ticket' => new TicketResource($ticket)]);
    }
}
