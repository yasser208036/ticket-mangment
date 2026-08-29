<?php

namespace App\Models;

use App\Enums\TicketActivityEvent;
use App\Models\Builders\AppendOnlyBuilder;
use Database\Factories\TicketActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'user_id', 'event', 'field', 'old_value', 'new_value', 'meta'])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class TicketActivity extends Model
{
    /** @use HasFactory<TicketActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    // Do NOT add `protected $touches = ['ticket'];` here. It would bump
    // tickets.updated_at on every activity row, silently redefining the
    // staleness rule TM-43 built on that column and breaking its idempotency
    // clause. An internal note must not reset the staleness clock (TM-47 AC4).

    protected function casts(): array
    {
        return ['event' => TicketActivityEvent::class, 'meta' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
