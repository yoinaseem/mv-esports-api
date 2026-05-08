<?php

namespace App\Http\Requests\StageQualification;

use App\Models\Stage;
use App\Rules\StageQualification\NoCycleInQualificationGraph;
use App\Rules\StageQualification\QualificationConfigMatchesType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateStageQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // StageQualificationPolicy handles caller authz.
    }

    public function rules(): array
    {
        /** @var Stage $targetStage */
        $targetStage    = $this->route('stage');
        $sourceStageId  = $this->input('source_stage_id');
        $ruleType       = $this->input('rule_type');

        return [
            'source_stage_id' => [
                'nullable',
                'integer',
                'exists:stages,id',
                ...($targetStage ? [
                    new NoCycleInQualificationGraph(
                        $sourceStageId !== null ? (int) $sourceStageId : null,
                        $targetStage->id,
                    ),
                ] : []),
            ],
            'rule_type'   => ['required', 'string', 'in:top_n,top_n_per_group,manual,all'],
            'rule_config' => [
                'nullable',
                'array',
                ...($ruleType ? [new QualificationConfigMatchesType($ruleType)] : []),
            ],
        ];
    }

    /**
     * Cross-field check: the source stage (when not null) must belong to
     * the same tournament as the target stage. Cross-tournament
     * qualifications are nonsensical.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $sourceId = $this->input('source_stage_id');
                if ($sourceId === null) {
                    return;
                }

                /** @var Stage $target */
                $target = $this->route('stage');
                $source = Stage::find($sourceId);

                if ($source === null) {
                    return; // 'exists:' rule will handle this
                }

                if ($source->tournament_id !== $target->tournament_id) {
                    $validator->errors()->add(
                        'source_stage_id',
                        'The source stage must belong to the same tournament as the target stage.'
                    );
                }
            },
        ];
    }
}
