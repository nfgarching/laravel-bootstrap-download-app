<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends ApiController
{
    /**
     * Protokolleinträge seit dem letzten Abruf.
     *
     * Die interne Anwendung merkt sich next_cursor und übergibt ihn beim
     * nächsten Mal als ?after=. has_more zeigt, ob sofort weitergelesen werden soll.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $after = (int) ($data['after'] ?? 0);
        $limit = (int) ($data['limit'] ?? 100);

        $events = Event::query()
            ->where('api_client_id', $this->client($request)->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $events->count() > $limit;
        $events = $events->take($limit);

        return response()->json([
            'data' => $events->map(fn (Event $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'shipment_id' => $event->shipment_uuid,
                'recipient_id' => $event->recipient_uuid,
                'file_id' => $event->file_uuid,
                'ip' => $event->ip,
                'meta' => $event->meta,
                'created_at' => $event->created_at->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $events->last()?->id ?? $after,
            'has_more' => $hasMore,
        ]);
    }
}
