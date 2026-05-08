<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageQualificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'source_stage_id' => $this->source_stage_id,
            'target_stage_id' => $this->target_stage_id,
            'rule_type'       => $this->rule_type,
            'rule_config'     => $this->rule_config,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
