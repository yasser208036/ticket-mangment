<?php

namespace App\Models;

use App\Policies\TicketPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

#[Fillable(['subject', 'description', 'requester_id', 'category_id', 'priority_id', 'status_id', 'assigned_to'])]
#[UsePolicy(TicketPolicy::class)]
class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['escalation_level' => 'integer', 'escalated_at' => 'datetime', 'first_responded_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * Admin: everything. Agent: theirs plus the unassigned queue they may
     * request from. End user: what they filed.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }
        if ($user->isAgent()) {
            return $query->where(fn (Builder $scoped) => $scoped->where('assigned_to', $user->getKey())->orWhereNull('assigned_to'));
        }

        return $query->where('created_by', $user->getKey());
    }

    /**
     * Refused outright. `ticket_activities.ticket_id` is ON DELETE CASCADE
     * (create_ticket_activities_table.php:13), so a hard delete takes the
     * ticket's entire audit trail with it. This is that risk enforced.
     *
     * There is no purge endpoint and TicketPolicy::delete gates a soft delete
     * only, so nothing in the application loses a capability here. The complete
     * fix is restrictOnDelete() on the foreign key, which is a migration and is
     * recommended to the backlog owner rather than taken in this story.
     */
    public function forceDelete(): never
    {
        throw new LogicException('A ticket cannot be hard-deleted: it would cascade through ticket_activities and erase the audit trail. Soft-delete it instead.');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Requester::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    public function assignmentRequests(): HasMany
    {
        return $this->hasMany(TicketAssignmentRequest::class);
    }
}
