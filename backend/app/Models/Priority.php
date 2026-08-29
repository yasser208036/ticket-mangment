<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'level', 'color', 'is_default'])]
#[Hidden(['is_default_unique'])]
class Priority extends Model
{
    protected function casts(): array
    {
        return ['level' => 'integer', 'is_default' => 'boolean'];
    }

    /** @param Builder<Priority> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('level');
    }
}
