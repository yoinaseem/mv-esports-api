<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Health probe
     *
     * Public endpoint for load balancers / uptime monitors. Returns 200 when the API process can talk to the database; 503 when the DB probe fails. Response shape: `{status, timestamp, db}` where `status` is `ok` or `degraded` and `db` is `ok` or `down`.
     */
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }

        $status = $dbOk ? 'ok' : 'degraded';

        return response()->json([
            'status'    => $status,
            'timestamp' => now()->toIso8601String(),
            'db'        => $dbOk ? 'ok' : 'down',
        ], $dbOk ? 200 : 503);
    }
}
