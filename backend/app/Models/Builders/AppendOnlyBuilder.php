<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Refuses every Eloquent write except an insert.
 *
 * Both model funnels end in a call on this builder -- Model::performUpdate()
 * finishes with `setKeysForSaveQuery($query)->update($dirty)` and
 * performDeleteOnModel() with `->delete()` -- so overriding the builder covers
 * save(), update(), delete(), destroy(), every *Quietly() variant,
 * withoutEvents(), mass builder writes and relation deletes, all in one place.
 *
 * `insert` is in Eloquent\Builder::$passthru, so it is forwarded to the base
 * query builder and never reaches this class. That is what keeps
 * ActivityRecorder -- the only legitimate writer -- working.
 *
 * The limit, stated rather than hidden: DB::table('ticket_activities')->update()
 * bypasses Eloquent entirely and no model-layer guard can stop it. No route can
 * reach it, nothing in app/ does it, and SingleWriterTest fails the build with
 * a filename if that changes.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    /** @param array<string, mixed> $values */
    public function update(array $values): never
    {
        $this->refuse('update');
    }

    /** @param array<int, array<string, mixed>> $values */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->refuse('upsert');
    }

    /** @param array<string, mixed> $extra */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('increment');
    }

    /** @param array<string, mixed> $extra */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('decrement');
    }

    public function delete(): never
    {
        $this->refuse('delete');
    }

    public function forceDelete(): never
    {
        $this->refuse('forceDelete');
    }

    // Not inherited: `truncate` reaches the base builder through __call, so it
    // has to be declared here to be blocked.
    public function truncate(): never
    {
        $this->refuse('truncate');
    }

    private function refuse(string $operation): never
    {
        throw new LogicException(
            "The audit trail is append-only: {$operation}() is refused on ".$this->getModel()->getTable().'. Rows are inserted once and never changed.'
        );
    }
}
