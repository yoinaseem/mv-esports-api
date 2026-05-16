<?php

namespace App\Http\Requests\TournamentRegistration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk seed assignment for a tournament's approved registrations.
 *
 * The controller is responsible for the cross-cutting validation:
 *  - full-set (count of assignments equals count of approved registrations)
 *  - no duplicate registration_id
 *  - seeds form a contiguous 1..N sequence
 *  - all referenced registrations belong to the tournament and are approved
 *
 * This request only validates the per-row shape.
 */
class BulkSeedRegistrationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller-level policy check
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignments'                   => ['required', 'array', 'min:1'],
            'assignments.*.registration_id' => ['required', 'integer'],
            'assignments.*.seed'            => ['required', 'integer', 'min:1'],
        ];
    }
}
