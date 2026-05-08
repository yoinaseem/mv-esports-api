<?php

namespace App\Http\Requests\Stage;

use App\Models\Tournament;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk reorder of a tournament's stages. Body shape:
 *   { "stages": [ {"id": 1, "sort_order": 0}, {"id": 2, "sort_order": 1}, ... ] }
 *
 * Validates that every stage id belongs to the URL's tournament and that
 * the submitted sort_orders form a unique set. The controller applies
 * the change in a transaction so two stages never temporarily share a
 * sort_order (which would violate the unique constraint).
 */
class ReorderStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stages'              => ['required', 'array', 'min:1'],
            'stages.*.id'         => ['required', 'integer', 'distinct', 'exists:stages,id'],
            'stages.*.sort_order' => ['required', 'integer', 'min:0', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Tournament $tournament */
                $tournament = $this->route('tournament');
                $submitted  = collect($this->input('stages'))->pluck('id')->all();

                $belonging = $tournament->stages()->pluck('id')->all();

                $foreign = array_diff($submitted, $belonging);
                if (! empty($foreign)) {
                    $validator->errors()->add('stages', 'All submitted stage ids must belong to this tournament.');
                }
            },
        ];
    }
}
