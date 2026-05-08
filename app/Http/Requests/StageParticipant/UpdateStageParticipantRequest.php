<?php

namespace App\Http\Requests\StageParticipant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStageParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // StageParticipantPolicy handles caller authz.
    }

    public function rules(): array
    {
        return [
            'seed'           => ['sometimes', 'integer', 'min:1'],
            'group_number'   => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status'         => ['sometimes', 'string', 'in:active,eliminated,withdrawn'],
            'final_position' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
