<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\JsonResponse;

final class UpcomingEventController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (config('app.env') === 'production' || ! config('features.public_upcoming_events')) {
            return response()->json(['message' => 'Public event access is pending client confirmation.'], 403);
        }

        $events = Event::query()
            ->where('is_public', true)
            ->whereNotNull('published_at')
            ->whereNull('cancelled_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'title', 'starts_at', 'location']);

        return response()->json(['data' => $events]);
    }
}
