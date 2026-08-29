<?php

namespace App\Models;

use App\Enums\StatusBucket;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'bucket', 'color', 'is_default', 'is_terminal', 'sort_order'])]
#[Hidden(['is_default_unique'])]
class Status extends Model
{
    public const SLUG_NEW = 'new';

    public const SLUG_RESOLVED = 'resolved';

    public const SLUG_CLOSED = 'closed';

    public const SLUG_REOPENED = 'reopened';

    protected function casts(): array
    {
        return ['bucket' => StatusBucket::class, 'is_default' => 'boolean', 'is_terminal' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @param Builder<Status> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
