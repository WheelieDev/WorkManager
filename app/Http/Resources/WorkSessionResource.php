<?php

namespace App\Http\Resources;

use App\Models\WorkSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkSession
 */
class WorkSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'kind' => [
                'value' => $this->kind->value,
                'label' => $this->kind->label(),
            ],
            'work_area' => WorkAreaResource::make($this->whenLoaded('workArea')),
            'note' => $this->note,
        ];
    }
}
