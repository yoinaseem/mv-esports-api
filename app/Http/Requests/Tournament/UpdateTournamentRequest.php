<?php

namespace App\Http\Requests\Tournament;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/tournaments/{tournament}. Status transitions go through
 * dedicated verb endpoints; this request rejects any `status` field with a
 * 403 from the controller. We don't list `status` in rules() — Laravel
 * silently drops un-listed fields, but the controller checks request->has
 * to surface the rejection clearly.
 */
class UpdateTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // TournamentPolicy::update handles caller authz.
    }

    public function rules(): array
    {
        $tournamentId = $this->route('tournament')?->id;

        return [
            'name'                   => ['sometimes', 'string', 'max:255'],
            'slug'                   => ['sometimes', 'string', 'max:255', 'unique:tournaments,slug,'.$tournamentId],
            'organization_id'        => ['nullable', 'integer', 'exists:organizations,id'],
            'description'            => ['nullable', 'string'],
            'start_date'             => ['sometimes', 'date'],
            'end_date'               => ['sometimes', 'date', 'after_or_equal:start_date'],
            'registration_opens_at'  => ['sometimes', 'date'],
            'registration_closes_at' => ['sometimes', 'date', 'after:registration_opens_at'],
            'stream_url'             => ['nullable', 'url', 'max:2048'],
            'banner_url'             => ['nullable', 'url', 'max:2048'],
            'max_participants'       => ['nullable', 'integer', 'min:2'],
        ];
    }
}
