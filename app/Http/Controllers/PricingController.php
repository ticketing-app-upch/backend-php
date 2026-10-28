<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\PriceQuoteRequest;
use App\Models\EventZone;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    public function quote(PriceQuoteRequest $request): JsonResponse
    {
        $zone = EventZone::whereKey($request->validated('id_zona'))->whereHas('event',
            fn ($query) => $query->where('status', 'PUBLISHED')->where('starts_at', '>', now()))->first();
        abort_unless($zone, 404, 'Zona no disponible.');

        // Compatibilidad Demo 3: precio base autoritativo, sin motor dinámico de Demo 4.
        return response()->json(['precio_ajustado' => (float) $zone->base_price, 'motivo' => 'Precio base']);
    }
}
