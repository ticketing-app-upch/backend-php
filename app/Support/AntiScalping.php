<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AntiScalping
{
    public const BLOCKED_MESSAGE = 'Transacción bloqueada por patrón sospechoso de compra';

    /**
     * Consulta el motor anti-scalping de Python y aborta con 429 si BLOCK.
     * Fail-open: sin configuración o si el servicio falla, la compra continúa
     * (los cold starts de Render no deben cortar ventas); solo bloquea con
     * una respuesta BLOCK explícita.
     */
    public function assertAllowed(float $timeElapsedSeconds, User $user, int $ticketsRequested): void
    {
        $url = config('ticketing.virtual_queue.url');
        $token = config('ticketing.virtual_queue.service_token');
        if (! $url || ! is_string($token) || strlen($token) < 32) {
            return;
        }

        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->connectTimeout(1)->timeout(3)
                ->post(rtrim($url, '/').'/api/v1/risk-score', [
                    'time_elapsed_seconds' => $timeElapsedSeconds,
                    'account_age_hours' => round($user->created_at->diffInHours(now()), 2),
                    'tickets_requested' => $ticketsRequested,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            report(new RuntimeException('Anti-scalping invalid response: HTTP '.$response->status()));

            return;
        }

        if (($response->json('action') ?? null) === 'BLOCK') {
            abort(429, self::BLOCKED_MESSAGE);
        }
    }
}
