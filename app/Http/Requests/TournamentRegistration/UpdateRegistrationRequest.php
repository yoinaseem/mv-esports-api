<?php

namespace App\Http\Requests\TournamentRegistration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /tournaments/{tournament}/registrations/{registration}.
 * Two callers (admin / participant owner) need different rules. The
 * controller decides via the policy and the role; this FormRequest
 * accepts the union (status / seed) and the controller filters.
 *
 * Validation here is the loose superset; finer rules (which status values
 * are reachable from the current one) live in the controller against the
 * RegistrationStatus enum's canTransitionTo.
 */
class UpdateRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // TournamentRegistrationPolicy handles caller authz.
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected,withdrawn'],
            'seed'   => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
