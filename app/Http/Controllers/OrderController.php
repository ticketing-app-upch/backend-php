<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreOrderRequest;
use App\Http\Requests\Event\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $items = collect($data['items'])->map(fn ($item) => [
            'zoneId' => (int) $item['zoneId'], 'quantity' => (int) $item['quantity'],
        ])->sortBy('zoneId')->values();
        $hash = hash('sha256', json_encode([
            'eventId' => (int) $data['eventId'], 'items' => $items->all(),
            'expectedTotal' => isset($data['expectedTotal']) ? (int) round($data['expectedTotal'] * 100) : null,
            'paymentMethod' => $data['paymentMethod'] ?? 'CARD', 'paymentResult' => $data['paymentResult'] ?? 'APPROVED',
        ], JSON_THROW_ON_ERROR));
        $created = false;
        $order = DB::transaction(function () use ($request, $data, $items, $hash, &$created) {
            // Serializa reintentos de la misma cuenta antes de tocar zonas o stock.
            $user = User::whereKey($request->user('api')->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 403, 'Tu cuenta está desactivada. Contacta al administrador');
            if (isset($data['idempotencyKey'])) {
                $previous = TicketOrder::where('user_id', $user->id)
                    ->where('idempotency_key', $data['idempotencyKey'])->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_hash, $hash), 409,
                        'La clave de idempotencia ya se utilizó con otra compra.');

                    return $previous;
                }
            }
            $event = Event::whereKey($data['eventId'])->where('status', 'PUBLISHED')->where('starts_at', '>', now())->first();
            abort_unless($event, 404, 'Evento no disponible');
            if ($items->sum('quantity') > min(6, $event->max_per_order)) {
                throw ValidationException::withMessages(['items' => 'La cantidad supera el límite del evento.']);
            }
            $zones = EventZone::where('event_id', $event->id)->whereIn('id', $items->pluck('zoneId'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($zones->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Todas las zonas deben pertenecer al evento.']);
            }
            $subtotal = 0;
            $entries = [];
            $lines = [];
            foreach ($items as $item) {
                $zone = $zones[$item['zoneId']];
                abort_if($zone->available_capacity < $item['quantity'], 409, 'Aforo insuficiente para la cantidad solicitada');
                $price = (int) round((float) $zone->base_price * 100);
                $subtotal += $price * $item['quantity'];
                $lines[] = ['zoneId' => (string) $zone->id, 'zoneName' => $zone->name,
                    'unitPrice' => $price / 100, 'quantity' => $item['quantity']];
                for ($index = 0; $index < $item['quantity']; $index++) {
                    $entries[] = ['zone' => $zone, 'price' => $price];
                }
            }
            // Demo 3: el precio publicado por zona es final, sin recargo de servicio.
            $total = $subtotal;
            abort_if(isset($data['expectedTotal']) && (int) round($data['expectedTotal'] * 100) !== $total,
                409, 'El precio cambió. Solicita una nueva cotización antes de confirmar.');
            // Simulación de Demo 3: nunca se reciben ni se persisten datos de tarjeta.
            abort_if(($data['paymentResult'] ?? 'APPROVED') === 'DECLINED', 402,
                'El pago fue rechazado. Verifica tu medio de pago e inténtalo nuevamente.');
            $order = TicketOrder::create([
                'code' => 'TICK-'.now()->year.'-'.strtoupper(bin2hex(random_bytes(8))),
                'user_id' => $user->id, 'event_id' => $event->id, 'buyer_name' => $user->full_name,
                'event_name' => $event->title, 'event_starts_at' => $event->starts_at,
                'event_venue' => $event->venue_name.($event->city ? ', '.$event->city : ''),
                'status' => 'CONFIRMADA', 'payment_method' => $data['paymentMethod'] ?? 'CARD',
                'idempotency_key' => $data['idempotencyKey'] ?? null, 'request_hash' => $hash,
                'lines' => $lines, 'subtotal' => $subtotal / 100, 'fee' => 0, 'total' => $total / 100,
            ]);
            foreach ($entries as $entry) {
                $zone = $entry['zone'];
                $order->tickets()->create([
                    'user_id' => $user->id, 'event_zone_id' => $zone->id, 'quantity' => 1,
                    'code' => 'TICK-'.now()->year.'-'.strtoupper(bin2hex(random_bytes(8))),
                    'unit_price' => $entry['price'] / 100, 'service_fee' => 0,
                    'total_price' => $entry['price'] / 100,
                    'event_title' => $event->title, 'event_starts_at' => $event->starts_at,
                    'venue_name' => $event->venue_name, 'venue_address' => $event->venue_address,
                    'zone_name' => $zone->name,
                ]);
            }
            foreach ($items as $item) {
                $zones[$item['zoneId']]->decrement('available_capacity', $item['quantity']);
            }
            $created = true;

            return $order;
        }, 3);

        return response()->json(new OrderResource($order->load('tickets')), $created ? 201 : 200);
    }

    public function mine(Request $request): JsonResponse
    {
        $orders = TicketOrder::with('tickets')->where('user_id', $request->user('api')->id)->orderByDesc('id')->get();

        return response()->json(OrderResource::collection($orders));
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $order = TicketOrder::with('tickets')->whereKey($id)
            ->when($request->user('api')->role->name !== 'ADMIN', fn ($query) => $query->where('user_id', $request->user('api')->id))
            ->first();
        abort_unless($order, 404, 'Orden no encontrada.');

        return response()->json(new OrderResource($order));
    }

    public function all(): JsonResponse
    {
        return response()->json(OrderResource::collection(TicketOrder::with('tickets')->orderByDesc('id')->get()));
    }

    public function updateStatus(string $id, UpdateOrderStatusRequest $request): JsonResponse
    {
        $order = DB::transaction(function () use ($id, $request) {
            $order = TicketOrder::with('tickets')->whereKey($id)->lockForUpdate()->first();
            abort_unless($order, 404, 'Orden no encontrada.');
            abort_unless($order->status === 'CONFIRMADA', 409, 'Esta orden no puede cancelarse.');
            $zones = EventZone::whereIn('id', $order->tickets->pluck('event_zone_id'))->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');
            foreach ($order->tickets->groupBy('event_zone_id') as $zoneId => $tickets) {
                $zone = $zones[$zoneId];
                $quantity = $tickets->sum('quantity');
                abort_if($zone->available_capacity + $quantity > $zone->capacity, 409, 'El aforo de la orden es inconsistente.');
                $zone->increment('available_capacity', $quantity);
            }
            $order->update(['status' => $request->validated('status')]);

            return $order;
        }, 3);

        return response()->json(new OrderResource($order));
    }
}
