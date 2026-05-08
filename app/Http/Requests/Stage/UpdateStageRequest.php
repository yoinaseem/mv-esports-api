<?php

namespace App\Http\Requests\Stage;

use App\Models\Stage;
use App\Rules\Stage\StageConfigMatchesFormat;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // StagePolicy handles caller authz.
    }

    public function rules(): array
    {
        // Format defaults to the existing stage's format for config validation —
        // a PATCH that doesn't change format still needs config to match the
        // current format.
        /** @var Stage|null $stage */
        $stage  = $this->route('stage');
        $format = $this->input('format', $stage?->format);

        return [
            'name'       => ['sometimes', 'string', 'max:255'],
            'format'     => ['sometimes', 'string', 'in:single_elim,double_elim,round_robin,swiss'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'config'     => [
                'sometimes',
                'nullable',
                'array',
                ...($format && in_array($format, ['single_elim', 'double_elim', 'round_robin', 'swiss'], true)
                    ? [new StageConfigMatchesFormat($format)]
                    : []),
            ],
        ];
    }
}
