<?php

namespace App\Models;

use App\Enums\AssignmentRequestStatus;
use App\Policies\AssignmentRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'user_id', 'note'])]
#[UsePolicy(AssignmentRequestPolicy::class)]
class TicketAssignmentRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AssignmentRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<TicketAssignmentRequest> $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AssignmentRequestStatus::Pending);
    }
}
