<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Resolve a validated per_page value from the request. Used by every
     * paginated index endpoint so the validation + cap behaviour is
     * consistent across the API. `?per_page=` is optional; out-of-range
     * values produce a 422.
     */
    protected function perPage(Request $request, int $default): int
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return (int) ($request->integer('per_page') ?: $default);
    }
}
