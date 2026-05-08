<?php

namespace App\Http\Requests\Tournament;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation shape for both POST /tournaments/applications (host)
 * and POST /tournaments/drafts (manager). Authorization is per-route, not
 * here — this class only describes the request body.
 */
class CreateTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level middleware + controller authz handle this.
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:255'],
            'slug'                   => ['required', 'string', 'max:255', 'unique:tournaments,slug'],
            'game_id'                => ['required', 'integer', 'exists:games,id'],
            'organization_id'        => ['nullable', 'integer', 'exists:organizations,id'],
            'participant_type'       => ['required', 'string', 'in:team,player'],
            'registration_type'      => ['required', 'string', 'in:open,invite_only,signed_only'],
            'description'            => ['nullable', 'string'],
            'start_date'             => ['required', 'date', 'after_or_equal:today'],
            'end_date'               => ['required', 'date', 'after_or_equal:start_date'],
            'registration_opens_at'  => ['required', 'date'],
            'registration_closes_at' => ['required', 'date', 'after:registration_opens_at'],
            'stream_url'             => ['nullable', 'url', 'max:2048'],
            'banner_url'             => ['nullable', 'url', 'max:2048'],
            'max_participants'       => ['nullable', 'integer', 'min:2'],
        ];
    }

    /**
     * Cross-field invariants on the date fields. Closing registration after
     * the tournament has already started is nonsensical; flag it explicitly.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $start    = $this->input('start_date');
                $closesAt = $this->input('registration_closes_at');

                if ($start && $closesAt) {
                    // Compare against end-of-start-day so a same-day close is allowed
                    // (registration may close on the morning of the tournament).
                    if (strtotime($closesAt) > strtotime($start.' 23:59:59')) {
                        $validator->errors()->add(
                            'registration_closes_at',
                            'Registration must close on or before the tournament start date.'
                        );
                    }
                }
            },
        ];
    }
}
