<?php

namespace App\Http\Requests\Stage;

use App\Rules\Stage\StageConfigMatchesFormat;
use Illuminate\Foundation\Http\FormRequest;

class CreateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // StagePolicy handles caller authz.
    }

    public function rules(): array
    {
        $format = $this->input('format');

        return [
            'name'       => ['required', 'string', 'max:255'],
            'format'     => ['required', 'string', 'in:single_elim,double_elim,round_robin,swiss'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'config'     => [
                'nullable',
                'array',
                ...(in_array($format, ['single_elim', 'double_elim', 'round_robin', 'swiss'], true)
                    ? [new StageConfigMatchesFormat($format)]
                    : []),
            ],
        ];
    }
}
