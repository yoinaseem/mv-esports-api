<?php

namespace App\Http\Requests\Match;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/matches/{match}. Sparse — only `scheduled_at` and
 * `best_of` are mutable here. Status changes go through dedicated
 * verb endpoints (`/walkover`) and are otherwise driven by services.
 */
class UpdateMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // MatchPolicy handles caller authz.
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            // best_of must be odd. Even values produce ambiguous strict-majority
            // outcomes (e.g. best_of=2 with score 1-1 has no winner).
            'best_of'      => [
                'sometimes', 'integer', 'min:1', 'max:99',
                function ($attribute, $value, $fail) {
                    if ($value % 2 === 0) {
                        $fail('The best_of must be an odd number.');
                    }
                },
            ],
        ];
    }
}
