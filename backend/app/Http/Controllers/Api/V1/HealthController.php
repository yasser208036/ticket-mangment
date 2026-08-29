<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness and readiness in one endpoint.
 *
 * "The PHP process is up" and "the app can actually serve a request" are
 * different facts, and only the second one is useful to a deploy script or a
 * load balancer — so the database is probed rather than assumed.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['ok']);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'version' => config('app.version'),
            'api' => 'v1',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * @param  callable():mixed  $probe
     * @return array{ok: bool, error?: string}
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (Throwable $e) {
            // The message can name hosts and drivers, so only leak it in debug.
            return [
                'ok' => false,
                'error' => config('app.debug') ? $e->getMessage() : 'unavailable',
            ];
        }
    }
}
