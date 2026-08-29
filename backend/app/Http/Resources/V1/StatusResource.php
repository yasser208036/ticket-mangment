<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'bucket' => $this->bucket->value, 'color' => $this->color, 'is_default' => $this->is_default, 'is_terminal' => $this->is_terminal, 'sort_order' => $this->sort_order];
    }
}
