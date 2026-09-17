<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackLastActivity
{
    /**
     * Only write once per window per user; a cache key acts as the throttle so
     * a chatty client cannot turn every request into a write on the users table.
     */
    public const THROTTLE_SECONDS = 300;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Recorded after the response is sent so it never adds latency.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        if (! Cache::add(self::cacheKey($user->id), true, self::THROTTLE_SECONDS)) {
            return;
        }

        $now = now();

        // Bare update: no model events, no `updated_at` touch, single indexed row.
        DB::table('users')
            ->where('id', $user->id)
            ->update(['last_activity_at' => $now]);

        $user->setAttribute('last_activity_at', $now)->syncOriginalAttribute('last_activity_at');
    }

    public static function cacheKey(string $userId): string
    {
        return "users:{$userId}:last-activity";
    }
}
