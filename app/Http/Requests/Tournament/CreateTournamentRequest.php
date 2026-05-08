<?php

namespace App\Http\Requests\Tournament;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

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
            // Date-only fields (yyyy-mm-dd). Restricting to date_format keeps
            // ISO 8601 datetime input from being silently accepted (Postgres
            // would store only the date portion); also makes Scramble's
            // OpenAPI emit `format: date` rather than `date-time`.
            'start_date'             => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date'               => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
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
     *
     * Both fields can arrive as date-only ('2027-08-24') or as full ISO 8601
     * datetimes ('2027-08-24T14:15:22Z'); Carbon handles both. Comparison is
     * against the END of the start day so registration may close at any
     * time on the morning of the tournament.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $start    = $this->input('start_date');
                $closesAt = $this->input('registration_closes_at');

                if (! $start || ! $closesAt) {
                    return;
                }

                try {
                    $startEndOfDay = Carbon::parse($start)->endOfDay();
                    $closesAtTs    = Carbon::parse($closesAt);
                } catch (\Throwable) {
                    // Field-level `date` validators will already have rejected
                    // unparseable input; nothing to add here.
                    return;
                }

                if ($closesAtTs->greaterThan($startEndOfDay)) {
                    $validator->errors()->add(
                        'registration_closes_at',
                        'Registration must close on or before the tournament start date.'
                    );
                }
            },
        ];
    }
}
