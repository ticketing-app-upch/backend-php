<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\IndexEventReviewRequest;
use App\Http\Requests\Admin\ReviewEventRequest;
use App\Http\Resources\AdminEventResource;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AdminEventController extends Controller
{
    public function review(
        string $id,
        ReviewEventRequest $request,
    ): JsonResponse {
        $data = $request->validated();

        $event = DB::transaction(function () use ($id, $request, $data) {
            $snapshot = Event::find($id);

            abort_unless($snapshot, 404, 'Evento no encontrado.');

            // Serializa las operaciones relacionadas con el mismo recinto.
            if ($snapshot->venue_id !== null) {
                Venue::whereKey($snapshot->venue_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $event = Event::whereKey($id)
                ->lockForUpdate()
                ->first();

            abort_unless($event, 404, 'Evento no encontrado.');

            abort_if(
                $event->venue_id !== $snapshot->venue_id,
                409,
                'El recinto cambió. Actualiza el evento antes de revisarlo.',
            );

            abort_unless(
                $event->status === Event::STATUS_PENDING_APPROVAL,
                409,
                'Solo se pueden revisar eventos pendientes.',
            );

            abort_if(
                $event->isReviewExpired(),
                409,
                'El evento ya venció',
            );

            $approved = $data['status'] === Event::STATUS_PUBLISHED;

            $event->update([
                'status' => $data['status'],
                'rejection_reason' => $approved
                    ? null
                    : $data['rejectionReason'],
                'reviewed_at' => now(),
                'reviewed_by' => $request->user('api')->id,
                'published_at' => $approved ? now() : null,
            ]);

            return $event;
        }, 3);

        return response()->json([
            'id' => (string) $event->id,
            'status' => $event->status,
            'rejectionReason' => $event->rejection_reason,
            'reviewedAt' => $event->reviewed_at->toIso8601String(),
            'reviewedBy' => (string) $event->reviewed_by,
            'publishedAt' => $event->published_at?->toIso8601String(),
        ]);
    }

    public function index(IndexEventReviewRequest $request): JsonResponse
    {
        $events = $this->pendingEvents()
            ->with(['organizer:id,full_name', 'venue.zones', 'zones'])
            ->withExists([
                'banner',
                'banner as secondary_banner_exists' => fn ($query) => $query
                    ->whereNotNull('secondary_content'),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return response()->json(AdminEventResource::collection($events))->header('Cache-Control', 'private, no-store');
    }

    public function show(string $id): JsonResponse
    {
        $event = $this->pendingEvents()
            ->with(['organizer:id,full_name', 'venue.zones', 'zones'])
            ->withExists([
                'banner',
                'banner as secondary_banner_exists' => fn ($query) => $query
                    ->whereNotNull('secondary_content'),
            ])
            ->find($id);

        abort_unless($event, 404, 'Evento pendiente no disponible.');

        return response()->json(new AdminEventResource($event));
    }

    public function banner(string $id): Response
    {
        return $this->privateImage($id, false);
    }

    public function secondaryBanner(string $id): Response
    {
        return $this->privateImage($id, true);
    }

    public function signedImage(string $id, string $image): Response
    {
        return $this->privateImage($id, $image === 'secondary-banner');
    }

    private function pendingEvents(): Builder
    {
        return Event::query()
            ->where('status', Event::STATUS_PENDING_APPROVAL)
            ->where('starts_at', '>', now());
    }

    private function privateImage(string $id, bool $secondary): Response
    {
        $event = $this->pendingEvents()->find($id);

        abort_unless($event, 404, 'Evento pendiente no disponible.');

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
