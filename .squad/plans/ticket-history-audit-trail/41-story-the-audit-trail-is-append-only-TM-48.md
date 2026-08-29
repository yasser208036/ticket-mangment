# Story 41 — The audit trail is append-only (Story: TM-48)

## Prerequisites

- **Story 38 (TM-45) must land first, and this story finishes a job its plan explicitly left open.** Story 38's test 21 (`SingleWriterTest::test_only_the_recorder_writes_to_ticket_activities`) carries this comment in its own plan: *"Write it as a tripwire and say so in a comment: it is a heuristic over source text, not a proof. **The structural guarantee — the model refusing updates and deletes — is TM-48**, and this test is what keeps the rule honest until then."* **This story is that guarantee.** Verify Story 38 landed: `ls backend/tests/Feature/Activity/SingleWriterTest.php backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php`.
- **Stories 39 (TM-46) and 40 (TM-47) should land first**, because AC1 and AC4 are assertions about the route table and both add routes to it. Story 39 adds `GET /tickets/{ticket}/activities`; Story 40 adds `POST /tickets/{ticket}/notes` **and its own test 14**, which asserts `PATCH`/`PUT`/`DELETE` on the notes path return `405` or `404`. **Task 5 generalises that one test into a scan of the whole route table; do not leave two overlapping copies** — see the decision.
- **`ActivityRecorder` writes through `TicketActivity::insert()` (`ActivityRecorder.php:37`), and `insert` is in Eloquent's `$passthru` list** (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:106–138`, `'insert'` at **125**), so it is forwarded to the base query builder by `__call` (**2280**) and **never touches a custom Eloquent builder**. Verified while planning. This is what makes task 1 safe: the only legitimate writer keeps working, untouched.
- **AC3 is largely already asserted by Story 38, and this story must not duplicate it.** Story 38's test 6 (`test_deleting_a_ticket_cascades`) already asserts both halves — a hard delete removes the rows, a soft delete does not. **`grep -n "test_deleting_a_ticket_cascades" backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php` before writing anything.** Task 4 adds the *behavioural* half AC3 actually asks for (the rows are still **readable** after a soft delete) and task 6 **edits** Story 38's test rather than copying it — see the decision.
- **Story 24 (TM-28) already measured the hole this story closes, and left it as a convention.** Its plan, **line 44**: *"After `$ticket->forceDelete()` on a second ticket, the activity count went **1 → 0** — the `cascadeOnDelete` foreign key at `create_ticket_activities_table.php:13` fires on a real `DELETE`. **So `forceDelete()` silently erases the audit trail this story exists to preserve. Never call it.**"* **"Never call it" is a convention, not evidence-grade.** Task 3 makes it enforced; the decision below explains why that is this story's business and what it costs.
- **Laravel `^13.17`** (`backend/composer.json:11`). `#[UseEloquentBuilder]` exists (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/UseEloquentBuilder.php`, `public string $builderClass`) and is resolved by reflection in `Model::resolveCustomBuilderClass()` (**Model.php:1947–1955**), called from `newEloquentBuilder()` (**1931–1940**). **This is the house idiom** — `TicketActivity` already uses `#[Fillable]`, `Ticket` uses `#[Fillable]` and `#[UsePolicy]`, and CLAUDE.md states models use attributes rather than properties.
- **There is no PHPStan or Larastan** — `require-dev` is faker, pail, pao, pint, mockery, collision, phpunit. Generic docblocks are documentation here, not enforcement; write them anyway to match `Requester::tickets()`'s habit.
- **There is still no `TicketFactory`** (`backend/database/factories/` holds only `UserFactory.php`; TM-59 owns the rest). Tests reuse the `makeTicket()` helper shape from Story 39's and 40's test plans.
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No migration, no schema change, no new dependency, and no frontend file.**

---

## What this story does not build, and who owns it

| Deferred | Owner |
|---|---|
| Changing `ticket_activities.ticket_id` from `cascadeOnDelete` to `restrictOnDelete` | **Nobody — recommended, not done.** See the FK decision; it is a migration and a request for a ruling |
| A restore or purge endpoint for tickets | **Nobody.** Story 24 declined both; a purge story must settle the FK first |
| Amendable or retractable notes | **Nobody.** Story 40 raised it: the honest shape is a superseding second row, never a mutation |
| Timeline filtering and append-on-demand | **TM-49** (E7-S5) |
| Any new event case | Stories 23, 24, 26, 27, 29, 32, 34, 35 — each appends its own |
| Row-level database permissions, a MySQL trigger, or an `ON UPDATE` guard | **Nobody.** Out of scope, and see the FK decision for why the application layer is the right altitude here |
| Blocking `DB::table('ticket_activities')->update(...)` | **Not closeable at the model layer** — named honestly in the decision, covered by Story 38's tripwire |

---

## Story Goal

The audit trail stops being append-only by convention and becomes append-only by construction.

1. **One** custom Eloquent builder makes every Eloquent write path other than `insert` throw — model `save()`, `update()`, `delete()`, `destroy()`, their `Quietly` variants, `withoutEvents()`, mass builder updates, relation deletes and `truncate()`.
2. The one legitimate writer, `ActivityRecorder`, is **untouched** — because `insert` is passthru, which is verified rather than hoped.
3. `Ticket::forceDelete()` throws, so the one path that could silently erase a ticket's whole trail becomes a loud error instead of a `1 → 0` row count.
4. A feature test proves **no route** in the whole table can mutate or remove an activity, and that an existing row is byte-identical after every mutating verb has been thrown at it.
5. The residual hole — raw `DB::table()` writes — is **named**, not papered over, and the FK cascade is put in front of the backlog owner with Story 24's measurement attached.

**No frontend changes required.** The story's labels are `backend` and `testing`, no criterion mentions the SPA, and the timeline is read-only already.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `$activity->update([...])` or `save()` with a dirty attribute | Succeeds silently | **`LogicException`** |
| `$activity->save()` with **nothing** dirty | No-op | **Still a no-op** — `performUpdate` skips the write when `$dirty` is empty. See the edge cases |
| `$activity->delete()`, `TicketActivity::destroy($id)` | Succeeds | **`LogicException`** |
| `updateQuietly()`, `deleteQuietly()`, `withoutEvents(…)` | Succeeds | **`LogicException`** — the guard is in the builder, not in an event |
| `TicketActivity::query()->update([...])` / `->delete()` | Succeeds | **`LogicException`** |
| `$ticket->activities()->delete()` | Succeeds | **`LogicException`** |
| `TicketActivity::truncate()` | Succeeds | **`LogicException`** |
| `TicketActivity::insert([...])` — the recorder | Succeeds | **Unchanged.** `insert` is passthru |
| `DB::table('ticket_activities')->update([...])` | Succeeds | **Still succeeds.** Not reachable from any route; covered by Story 38's tripwire |
| `$ticket->delete()` (soft) | Rows preserved | **Unchanged**, and now asserted behaviourally: the timeline still reads them |
| `$ticket->forceDelete()` | **Silently erases the whole trail** (measured 1 → 0) | **`LogicException`** |
| Any `PATCH`/`PUT`/`DELETE` against an activity path | No such route | **No such route**, asserted by a scan of the whole route table |

---

## Context — Read These Files First

1. `backend/app/Models/TicketActivity.php` — 18 lines. `#[Fillable]` at **9**, `UPDATED_AT = null` at **12**, `casts()` at **14–17**, plus whatever Stories 38 and 40 added below (`user()` and the `$touches` warning comment). **Task 2 adds one attribute and one import.**
2. `backend/app/Services/ActivityRecorder.php` — **`TicketActivity::insert($chunk)` at 37 is the call task 1 must not break.** Also `LogicException` at **8** and the guard message at **27** — **the exception class and message style task 1 copies.** `TicketReferenceGenerator.php:13` is the second instance of that idiom.
3. `backend/app/Models/Ticket.php` — `use SoftDeletes;` at **16**, `casts()` at **18–21**, seven `BelongsTo` relations at **23–56**, plus `activities()` from Story 38. **Task 3 adds one method.**
4. `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php` — **the whole reason this design works.** `$passthru` at **106–138** with `'insert'` at **125** and `'insertorignore'` at **128**; `__call`'s passthru branch at **2280**. The methods task 1 overrides, with their **exact signatures**: `update(array $values)` at **1280**, `upsert(array $values, $uniqueBy, $update = null)` at **1293**, `increment($column, $amount = 1, array $extra = [])` at **1347**, `decrement($column, $amount = 1, array $extra = [])` at **1362**, `delete()` at **1518**, `forceDelete()` at **1534**.
5. `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php` — **read these four regions before writing task 1**, because they are why one class is enough:
   - `performUpdate(Builder $query)` at **1496–1526**. **Line 1518 is `$this->setKeysForSaveQuery($query)->update($dirty);`** — a call on the *Eloquent* builder, so the custom builder catches every model-instance update. **Line 1517's `if (count($dirty) > 0)` is the caveat**: a clean `save()` never reaches it.
   - `performDeleteOnModel()` at **1827–1832**. **Line 1829 is `$this->setKeysForSaveQuery($this->newModelQuery())->delete();`** — same story for deletes.
   - `update(array $attributes = [], array $options = [])` at **1153–1160** — `fill()` then `save()`, so it funnels through `performUpdate`.
   - `destroy($ids)` at **1698** — loads models and calls `delete()` on each.
   - `newEloquentBuilder($query)` at **1931–1940** and `resolveCustomBuilderClass()` at **1947–1955** — the reflection that reads `#[UseEloquentBuilder]`.
6. `vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletes.php` — `forceDelete()` at **51–67**: it sets `$this->forceDeleting = true` and then calls `$this->delete()`. `forceDeleteQuietly()` at **73–76** is `withoutEvents(fn () => $this->forceDelete())`, and `forceDestroy($ids)` (from **79**) loops calling `$model->forceDelete()`. **So overriding the public `forceDelete()` once covers all three** — task 3 depends on this.
7. `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php` — **line 13**, `foreignId('ticket_id')->constrained()->cascadeOnDelete()`. **This file is not edited.** Compare **`create_tickets_table.php:19–22`**, where every master-data FK is `restrictOnDelete()` — the house pattern for "this must not vanish", and the basis of the FK recommendation.
8. `backend/tests/Feature/Activity/SingleWriterTest.php` (Story 38's) — **read the comment on test 21.** It names this story as its successor and it stays in place; see the decision.
9. `backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php` (Story 38's) — **`test_deleting_a_ticket_cascades`.** It calls `forceDelete()` on a ticket to prove the DB cascade. **Task 3 breaks it.** Task 6 fixes it, and the decision explains why the fix is an improvement rather than a workaround.
10. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `self::ACCESS` at **16**, `test_every_api_route_is_classified` at **18–25** looping `Route::getRoutes()` and filtering on `str_starts_with($route->uri(), 'api/v1')`. **This is the precedent task 5 follows** for iterating the route table, and the repo's established shape for a structural test.
11. [`38-story-activity-table-and-a-single-recorder-TM-45.md`](38-story-activity-table-and-a-single-recorder-TM-45.md) — **test 6 and test 21 in its Test Plan**, and its `docs/erd.md` closing note (*"`ticket_activities` has no `updated_at`: rows are never modified"*), which task 8 upgrades from a statement of intent to a statement of enforcement.
12. [`../ticket-creation-tracking/24-story-soft-delete-a-ticket-TM-28.md`](../ticket-creation-tracking/24-story-soft-delete-a-ticket-TM-28.md) — **line 22** (*"**Never call `forceDelete()`**"*), **line 44** (the measured `1 → 0`), **lines 86–87** (the comment it puts in the controller), and **lines 307–308**. All four say the same thing in prose. **Task 3 is that prose made mechanical.**
13. [`40-story-internal-notes-on-a-ticket-TM-47.md`](40-story-internal-notes-on-a-ticket-TM-47.md) — its **test 14** (`test_no_route_can_amend_or_remove_a_note`) and its permanence decision, which already promises the user *"Notes are internal and permanent."* **This story is what makes that promise true**, and task 5 absorbs test 14.

---

## Decision — one custom Eloquent builder, not model overrides and not model events

Three mechanisms could implement AC2. **Only one covers every Eloquent path.**

- **Model events** (`static::updating(fn () => throw …)`) are the obvious choice and the weakest. `saveQuietly()`, `updateQuietly()`, `deleteQuietly()` and `Model::withoutEvents()` all bypass them by design, and a mass `TicketActivity::query()->update([...])` never fires them at all. An audit guard that a documented one-word method call disables is not evidence-grade.
- **Overriding the model's `performUpdate()` and `performDeleteOnModel()`** catches the quiet variants but **not** mass builder writes or `$ticket->activities()->delete()`.
- **A custom Eloquent builder catches all of it, because both model funnels call the builder.** `performUpdate` ends at `$this->setKeysForSaveQuery($query)->update($dirty)` (**Model.php:1518**) and `performDeleteOnModel` at `…->delete()` (**1829**) — both on the *Eloquent* builder, which is ours. So one class covers `save()`, `update()`, `delete()`, `destroy()`, every `Quietly` variant, `withoutEvents()`, `TicketActivity::query()->update()`, `->delete()`, `upsert()`, `increment()`, `decrement()`, `truncate()` **and** relation deletes — which use the related model's builder.

**And it leaves the recorder alone, verifiably.** `insert` is in `$passthru` (**Builder.php:125**), so `TicketActivity::insert($chunk)` is forwarded by `__call` (**2280**) straight to the base query builder and never sees an overridden method. **This is checked, not assumed** — verification step 3 re-checks it, and test 1 fails if it ever stops being true.

**`#[UseEloquentBuilder(AppendOnlyBuilder::class)]`, not `protected static $builder`.** `Model::resolveCustomBuilderClass()` (**1947–1955**) reads the attribute by reflection, and CLAUDE.md is explicit that this project's models use attributes rather than properties — `#[Fillable]` and `#[UsePolicy]` are already on these two models.

## Decision — the honest limit: raw `DB::table()` writes are not closeable here, and that is stated

`DB::table('ticket_activities')->update([...])` and `->delete()` never touch Eloquent. **No model-layer guard can stop them, and this story does not pretend otherwise.**

What actually covers that path, and why it is enough for now:

- **Nothing in `backend/app/` does it**, and Story 38's test 21 fails the build with a filename if anything starts to — it already scans for `DB::table('ticket_activities')` and `DB::table("ticket_activities")` by name.
- **No route can reach it.** Task 5 proves no endpoint mutates an activity, and there is no raw-SQL endpoint.
- **The only remaining actor is an operator at a console**, which no application-layer mechanism constrains.

**Put the limit in the class docblock, in `docs/api-contract.md` and in the PR.** A guard whose gaps are documented is worth more than one whose gaps are discovered. **Story 38's test 21 stays exactly where it is** — its comment says the structural guarantee is TM-48's, which is now true, but the tripwire is what covers the one path the guarantee cannot.

## Decision — `Ticket::forceDelete()` throws, and this story owns that

AC3 reads *"Soft-deleting a ticket preserves all of its activity rows."* **Taken literally it asks for a test of something nothing threatens**: a soft delete is an `UPDATE` to `deleted_at` and has never touched `ticket_activities`. Story 38's test 6 already asserts it.

**The thing that can violate AC3's purpose is a hard delete, and today it does so silently.** `ticket_id` is `cascadeOnDelete` (`create_ticket_activities_table.php:13`), and Story 24 **measured** the consequence: *"the activity count went 1 → 0"* (its plan, line 44). Its answer was prose — *"Never call `forceDelete()`"*, repeated in four places across one plan file. For a story whose "so that" is *"the history can be trusted as evidence"*, an unenforced convention guarding the only evidence-destroying path is the largest gap in the epic.

So task 3 overrides `forceDelete()` on `Ticket` to throw. **It is proportionate**: one method, no migration, and `SoftDeletes::forceDeleteQuietly()` (**SoftDeletes.php:73**) and `forceDestroy()` both route through it, so one override closes all three. **It costs nothing operationally**: there is no purge endpoint, `TicketPolicy::delete` gates a soft delete only, and Story 24's controller already never calls it.

**The one real cost, stated plainly: it breaks Story 38's test 6**, which calls `forceDelete()` to prove the cascade. **Task 6 rewrites that test to delete through `DB::table('tickets')->where('id', …)->delete()`.** That is not a workaround — **a database-level cascade should be proven by a database-level delete.** Asserting a foreign-key behaviour through an application method that this story has just forbidden would be testing the wrong layer. Record the edit in the PR: **Story 38's plan file is read-only, but its test is code, and this story owns the change.**

## Decision — the FK cascade is recommended, not changed

The complete fix is `restrictOnDelete()` on `ticket_activities.ticket_id`, which would make the **database** refuse to erase a trail no matter who asks — operator, tinker session or raw SQL. **This story does not do it**, and the reason is scope rather than doubt:

- **It is a migration**, and none of TM-48's four criteria mentions schema. Every other story in this epic ships without one.
- **It changes behaviour for a future purge story**, which would then have to state explicitly what happens to a purged ticket's trail — which is exactly the conversation that should happen, in that story, not implicitly here.

**Recommend it in the PR, with Story 24's measurement attached:**

> `ticket_activities.ticket_id` is `ON DELETE CASCADE`. TM-28's plan measured that a hard delete takes a ticket's activity rows with it, 1 → 0. We closed the application path (`Ticket::forceDelete()` now throws) but **the database will still cascade for anyone who bypasses Eloquent.** The complete fix is `restrictOnDelete()`, which is a migration and would force any future purge story to decide what happens to the trail. Compare `tickets`' own FKs, which are all `restrictOnDelete` for exactly this reason. **We recommend it as its own story and did not take it here.**

## Decision — task 5 absorbs Story 40's test 14 rather than sitting beside it

Story 40's test 14 asserts `PATCH`/`PUT`/`DELETE` on `/tickets/{id}/notes` return `405` or `404`. **That is a narrower version of AC4**, written early because Story 40 shipped the endpoint TM-48's first criterion is about.

Task 5's test covers every activity path and scans the whole route table. **Delete Story 40's test 14** and note the replacement in the PR. Two tests asserting the same rule at different breadths is the shape that rots: the narrow one keeps passing after the broad one is weakened, and nobody notices. **If Story 40 has not landed, there is nothing to delete** — check with `grep -rn "test_no_route_can_amend_or_remove_a_note" backend/tests/`.

---

## Backend Tasks

### 1 — The builder

**Create file: `backend/app/Models/Builders/AppendOnlyBuilder.php`**

`app/Models/Builders/` is new; it is the conventional Laravel location for a model's query builder.

```php
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
 * reach it, nothing in app/ does it, and TM-45's SingleWriterTest fails the
 * build with a filename if that changes.
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
```

**Match the exact parent signatures** (`Builder.php:1280`, **1293**, **1347**, **1362**, **1518**, **1534**) or PHP raises a fatal signature error at class load. **Do not add type hints the parent does not have** on `$uniqueBy`, `$update`, `$column` or `$amount`.

**Do not override `insert`, `insertOrIgnore`, `insertUsing` or `create`.** The first three are passthru and the recorder needs `insert`; `create` is a legitimate append that no current code uses but nothing about this story forbids.

### 2 — Attach it to the model

**File: `backend/app/Models/TicketActivity.php`**

Add the two imports and one attribute above the class, beside the existing `#[Fillable]` (**9**):

```php
use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;

#[Fillable([...])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class TicketActivity extends Model
```

**Keep `#[Fillable]` where it is** and keep the `$touches` warning comment Story 40 added. **Do not add `SoftDeletes`** — a soft-deletable audit row is a deletable audit row with extra steps.

### 3 — Close the one path that erases a whole trail

**File: `backend/app/Models/Ticket.php`**

Add after `casts()` (**ends at 21**), with `use LogicException;`:

```php
    /**
     * Refused outright. `ticket_activities.ticket_id` is ON DELETE CASCADE
     * (create_ticket_activities_table.php:13), so a hard delete takes the
     * ticket's entire audit trail with it -- TM-28 measured 1 -> 0 and answered
     * it with a comment. This is that comment enforced.
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
```

`SoftDeletes::forceDeleteQuietly()` (**SoftDeletes.php:73**) and `forceDestroy()` both call this method, so **one override closes all three**. **Do not also override `delete()`** — the soft delete is the supported path and Story 24's endpoint depends on it.

### 4 — Document the guarantee

**File: `docs/api-contract.md`**

Add to the `### GET /api/v1/tickets/{ticket}/activities` section Story 39 wrote:

```
**The trail is append-only, enforced.** `TicketActivity` uses a custom Eloquent
builder that refuses `update`, `upsert`, `increment`, `decrement`, `delete`,
`forceDelete` and `truncate`; only `insert` is permitted, which is the single
path `ActivityRecorder` uses. There is no endpoint that mutates or removes an
activity, and a feature test scans the whole route table to keep it that way.
`Ticket::forceDelete()` is refused for the same reason -- the `ticket_id`
foreign key is `ON DELETE CASCADE`, so a hard delete would erase the trail.

**The limit, stated rather than implied:** a raw
`DB::table('ticket_activities')->update(...)` bypasses Eloquent and no
model-layer guard stops it. No route reaches it and nothing in `backend/app/`
does it -- a test fails the build with a filename if that changes -- but an
operator with database access is not constrained by application code.
```

**File: `docs/erd.md`**

Story 38's closing note reads *"`ticket_activities` has no `updated_at`: rows are never modified."* Extend that sentence so it records enforcement rather than intent:

```
Never modified is enforced, not assumed: the model uses an append-only Eloquent
builder that refuses every write but `insert` (TM-48), and `Ticket::forceDelete()`
is refused because `ticket_id` is `ON DELETE CASCADE`. Changing that foreign key
to `RESTRICT` is the complete fix and is recommended as its own story.
```

**No other change to either file**, and **no change to the migration.**

### 5 — The route test

**Create file: `backend/tests/Feature/Activity/ActivityRouteImmutabilityTest.php`** — see the Test Plan. This is AC1 and AC4, and it **replaces** Story 40's test 14.

### 6 — Repair Story 38's cascade test

**File: `backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php`**

`test_deleting_a_ticket_cascades` calls `$ticket->forceDelete()`, which task 3 now refuses. **Replace that one call with a database-level delete:**

```php
        DB::table('tickets')->where('id', $ticket->getKey())->delete();
```

**Assert nothing else differently.** The test's subject is the foreign key's `ON DELETE CASCADE`, which is a database behaviour and belongs behind a database-level delete — the application method it used before is now forbidden precisely because it triggers this cascade. **Leave the soft-delete half of that test exactly as it is**; it is AC3's schema-level assertion and task 7 adds the behavioural one rather than replacing it.

**Story 38's plan file is read-only. Its test file is not.** Record this edit in the PR.

### 7 — The model and soft-delete tests

**Create file: `backend/tests/Feature/Activity/AppendOnlyTest.php`** — see the Test Plan.

---

## Edge Cases & Failure Modes

- **`$activity->save()` with nothing dirty does not throw.** `Model::performUpdate` guards the write with `if (count($dirty) > 0)` (**Model.php:1517**), so a clean save fires `updating`/`updated` and writes nothing. **This is correct** — nothing was mutated — but it is a trap for the test author: **test 2 must change an attribute before saving**, or it passes for the wrong reason. Stated in a comment in the test.
- **`$activity->touch()`** would be an update, and it throws — but `TicketActivity::UPDATED_AT` is `null` (`TicketActivity.php:12`) and `usesTimestamps()` on a model with no `updated_at` means `touch()` has nothing to set. Either way no row changes. **Not a hole**; recorded so nobody reads a non-throwing `touch()` as a gap.
- **`DB::table('ticket_activities')->update(...)` still works.** The named limit. Covered by Story 38's test 21 and by task 5 proving no route reaches it. **Documented in the class docblock, the API contract and the PR** — never silently.
- **`RefreshDatabase` and `migrate:fresh` still work.** Both operate at the schema level (transactions, `DROP TABLE`), not through the model, so nothing in the suite or the reset path touches `AppendOnlyBuilder`. **If this were wrong, every test in the suite would fail at once** — verification step 5 is the check.
- **`Model::destroy($ids)`** (**Model.php:1698**) loads models and calls `delete()` on each → `performDeleteOnModel` → the builder → throws on the **first** row. Rows before it in the loop are unaffected because none was deleted. Test 4.
- **`$ticket->activities()->delete()`** — a relation write. `HasMany` builds its query from the related model's builder, so it is ours and it throws. **This is the path model-event guards would have missed entirely.** Test 5.
- **`TicketActivity::query()->update([...])`** — a mass Eloquent write that fires **no** model events at all. It throws because the guard is in the builder. **The single strongest argument for this design**; test 6.
- **`updateQuietly()`, `deleteQuietly()`, `withoutEvents(fn () => $activity->delete())`** — all throw. Test 7 covers all three in one place, because each is a documented one-call bypass of the obvious alternative implementation.
- **A user is deleted while their activities exist.** `user_id` is `nullOnDelete` (`…create_ticket_activities_table.php:14`), which is a **database-driven `UPDATE`** on `ticket_activities` and does **not** pass through Eloquent. **It still works, and it should**: Story 38's test 5 asserts the row survives with a null actor, and history outliving its author is the designed behaviour. **This story must not break it** — test 8 re-asserts it after the guard lands. **Do not "fix" this**: the alternative is a foreign key that blocks deleting a user, which TM-12 already declined in favour of deactivation.
- **A soft-deleted ticket's activities.** Rows untouched; still readable through `Ticket::withTrashed()->find($id)->activities`. **The endpoint returns `404`** because route model binding excludes trashed rows — that is Story 39's documented behaviour, not a loss of data. Test 9 asserts both halves so the distinction is on record.
- **`$ticket->forceDelete()`, `forceDeleteQuietly()`, `Ticket::forceDestroy([$id])`** — all three throw, from one override. Test 10.
- **`$ticket->restore()` after a soft delete** — untouched by this story and still works. The activity rows were never gone. Test 11.
- **A new activity row for an already-soft-deleted ticket** — still inserts, because `insert` is permitted and the FK points at a row that still exists. Story 24's `deleted` event depends on this. Test 12.
- **A future story needing to correct a wrong activity row.** There is no path, by design. **The answer is a superseding row**, which is what Story 40 already told users about notes. Recorded in the API contract so the next person finds the reason rather than the gap.
- **A signature drift in a future Laravel minor.** If `Builder::update()`'s signature changes, `AppendOnlyBuilder` fails at class load with a fatal error — **loudly, on the first test**, not silently. Preferable to a guard that quietly stops applying; noted in the PR.

---

## Test Plan

### Backend — `backend/tests/Feature/Activity/AppendOnlyTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Rows are created through `ActivityRecorder` inside `DB::transaction`, using the `makeTicket()` helper from Stories 39 and 40 — **there is still no `TicketFactory`**. Each test asserts the exception **and** that the row is unchanged afterwards, because a guard that throws after writing is worse than none.

1. `test_the_recorder_still_writes` — **the regression that protects the whole design.** Record two rows through `ActivityRecorder`; assert both exist with their `meta` intact. **This is what fails if `insert` ever stops being passthru or if `AppendOnlyBuilder` accidentally overrides it.** Run it first.
2. `test_saving_a_changed_activity_throws` — load a row, **change `$activity->field`**, `save()` → `LogicException`; then assert the raw column is unchanged. **Comment that the attribute must be dirtied**: `performUpdate` skips the write when `$dirty` is empty (`Model.php:1517`), so a clean `save()` would pass for the wrong reason.
3. `test_updating_an_activity_throws` — `$activity->update(['event' => 'created'])` → `LogicException`, row unchanged.
4. `test_deleting_an_activity_throws` — `$activity->delete()` → `LogicException`; then `TicketActivity::destroy($id)` → `LogicException`; row count unchanged both times.
5. `test_deleting_through_the_relation_throws` — `$ticket->activities()->delete()` → `LogicException`, row count unchanged. **The path a model-event guard would have missed.**
6. `test_a_mass_builder_update_throws` — `TicketActivity::query()->whereKey($id)->update(['event' => 'created'])` → `LogicException`, row unchanged. **This fires no model events; it is the case that decides the design.**
7. `test_the_quiet_and_eventless_bypasses_also_throw` — `updateQuietly(['field' => 'x'])`, `deleteQuietly()`, and `TicketActivity::withoutEvents(fn () => $activity->delete())` → **all three** `LogicException`. **The test that proves this is not an event listener.**
8. `test_upsert_increment_decrement_and_truncate_all_throw` — each on `TicketActivity::query()` → `LogicException`. `truncate` matters most: it is not inherited and reaches the base builder through `__call` unless declared.
9. `test_deleting_a_user_still_nulls_the_actor` — insert with a real `user_id`, delete the user, assert the row **survives** with `user_id` null. **The database-driven update that must keep working**; re-asserts Story 38's test 5 after the guard lands.
10. `test_a_soft_deleted_ticket_keeps_and_still_exposes_its_activities` — **AC3, behaviourally.** Record two rows, `$ticket->delete()`, then assert: the rows still exist by count; `Ticket::withTrashed()->find($id)->activities` returns **both**; and `GET /api/v1/tickets/{id}/activities` returns **`404`** (route model binding excludes trashed rows — Story 39's documented behaviour, not data loss). Story 38's test 6 covers the schema half; **this is the half AC3 actually asks for.**
11. `test_force_deleting_a_ticket_throws` — `$ticket->forceDelete()`, `$ticket->forceDeleteQuietly()` and `Ticket::forceDestroy([$id])` → **all three** `LogicException`, and the activity rows still exist. **The 1 → 0 erasure TM-28 measured, now refused.**
12. `test_restoring_a_soft_deleted_ticket_keeps_its_activities` — delete, restore, assert both rows still readable through the relation.
13. `test_a_new_activity_can_be_recorded_for_a_soft_deleted_ticket` — soft-delete, then record a `deleted` row; assert it inserts. **Story 24's own event depends on this**; the guard must not have made appends conditional.

### Backend — `backend/tests/Feature/Activity/ActivityRouteImmutabilityTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Structural first, behavioural second — following `RouteAuthorizationTest`'s precedent of asserting shape (**18–25**).

14. `test_no_route_mutates_an_activity` — **AC1.** Iterate `Route::getRoutes()`; for every route whose `uri()` starts with `api/v1`, assert that **if** the URI contains `activit` or `note`, its `methods()` intersect `['PATCH','PUT','DELETE']` is **empty**. Fail naming the route. Covers today's two routes and every one added later.
15. `test_no_route_is_named_for_an_activity_mutation` — assert no route name matches `/activit(y|ies)\.(update|destroy|delete|edit)/` or `/notes\.(update|destroy|delete)/`. Cheap, and it catches a route added under a different URI shape.
16. `test_every_mutating_verb_against_every_activity_path_is_refused` — **AC4, behaviourally, and this replaces Story 40's test 14.** Record one activity, snapshot **every column** via `DB::table('ticket_activities')->where('id', $id)->first()`. Then, with an **admin** token, fire `PATCH`, `PUT` and `DELETE` at each of: `/api/v1/tickets/{ticket}/activities`, `/api/v1/tickets/{ticket}/activities/{activityId}`, `/api/v1/tickets/{ticket}/notes`, `/api/v1/tickets/{ticket}/notes/{activityId}`, `/api/v1/activities/{activityId}`. Assert every response is `404` or `405` — **never `2xx`** — and then assert the snapshot is **byte-identical** and the row count unchanged. An admin token because a `403` would prove nothing about the route's existence.
17. `test_the_only_activity_route_is_a_get` — assert exactly one `api/v1` route has `activities` in its URI and its methods are `['GET','HEAD']`. Fails loudly if TM-49 adds a second, which is the moment to re-read AC1.

### Backend — modified

18. `backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php` — task 6: `test_deleting_a_ticket_cascades` deletes through `DB::table('tickets')->where('id', …)->delete()` instead of `forceDelete()`. **The soft-delete half is unchanged.**
19. `backend/tests/Feature/Activity/TicketNotesTest.php` — **delete `test_no_route_can_amend_or_remove_a_note`** (Story 40's test 14), now covered at full breadth by test 16. If Story 40 has not landed there is nothing to remove; check with `grep -rn "test_no_route_can_amend_or_remove_a_note" backend/tests/`.
20. `backend/tests/Feature/Activity/SingleWriterTest.php` — **not modified.** Its tripwire covers the raw `DB::table()` path this story cannot. **Update only its comment** if it claims TM-48 will supersede it: the structural guarantee has landed, and the tripwire still owns the gap.

### Frontend

**No frontend tests, and no frontend files.** No criterion mentions the SPA; the timeline is read-only and unchanged.

---

## Verification Steps

1. **Services:** `docker compose ps` → all three healthy, `tm-mysql-test` on **3307**.
2. **Confirm the prerequisites landed:** `ls backend/tests/Feature/Activity/SingleWriterTest.php backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php backend/app/Http/Resources/V1/TicketActivityResource.php` → all present.
3. **Re-check the passthru claim the whole design rests on, rather than trusting this plan:** `grep -n "'insert'," backend/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php` → a hit inside the `$passthru` array around **125**. If `insert` is not there, **stop** — `ActivityRecorder` would break and the design needs revisiting.
4. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
5. **Backend tests:** from `backend/`, `composer test`. Expect **+17 tests** (20 added, 1 removed, 2 modified in place) and exactly **one** failure — `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user`, TM-14's. **A wall of unrelated failures means `RefreshDatabase` is routing through the model** — read the edge case and re-check task 2.
6. **Prove test 1 earns its place — it protects the entire design.** Add `public function insert(array $values): never { $this->refuse('insert'); }` to `AppendOnlyBuilder`, run `--filter=test_the_recorder_still_writes`, confirm it **fails**. Restore. *This is the mistake a future maintainer will make while "tightening" the guard.*
7. **Prove test 6 earns its place — it is why this is a builder and not an event listener.** Replace `AppendOnlyBuilder` with `static::updating(fn () => throw new LogicException(...))` in `TicketActivity::booted()`, run the whole `AppendOnlyTest`, and confirm tests **6, 7 and 5** fail while 2, 3 and 4 pass. **Restore.** Record the result in the PR: it is the evidence for the decision.
8. **Prove test 2 is not passing for the wrong reason:** remove the attribute change before `save()`, confirm the test **fails to see an exception** — because `performUpdate` skips a clean save. Restore.
9. **Prove test 11 earns its place:** remove `Ticket::forceDelete()`, run `--filter=test_force_deleting_a_ticket_throws`, confirm it fails **and** that the activity rows are gone — the `1 → 0` TM-28 measured. Restore.
10. **Prove test 16 earns its place:** temporarily add `Route::delete('/tickets/{ticket}/activities/{activity}', fn () => response()->noContent())->name('tickets.activities.destroy');` to `routes/api.php`, run `--filter=ActivityRouteImmutability`, and confirm **tests 14, 15, 16 and 17 all fail**. Remove the route. **Also add `'tickets.activities.destroy' => 'staff'` to `RouteAuthorizationTest::ACCESS` for the duration or that suite fails too** — and remove it again.
11. **By hand.** `php artisan serve` in `backend/`:
    - `php artisan tinker --execute="\$a = App\Models\TicketActivity::query()->latest('id')->first(); \$a->field = 'x'; \$a->save();"` → **`LogicException`** naming `update()` and `ticket_activities`.
    - `php artisan tinker --execute="App\Models\TicketActivity::query()->update(['event' => 'created']);"` → **`LogicException`**.
    - `php artisan tinker --execute="\$t = App\Models\Ticket::query()->latest('id')->first(); \$t->forceDelete();"` → **`LogicException`** about the cascade.
    - `php artisan tinker --execute="\$t = App\Models\Ticket::query()->latest('id')->first(); \$t->delete(); dump(App\Models\Ticket::withTrashed()->find(\$t->id)->activities->count());"` → the count is **unchanged**. Then `UPDATE tickets SET deleted_at = NULL WHERE id = <id>;` to restore.
    - File a ticket through `POST /api/v1/tickets` → **succeeds**, and one `created` row appears. *The recorder is unaffected; this is the check that matters most.*
    - `curl -s -o /dev/null -w '%{http_code}\n' -X DELETE -H "Authorization: Bearer <token>" http://localhost:8000/api/v1/tickets/1/activities/1` → `404` or `405`.
12. **Regression:** `git status` shows **no file under `backend/database/`** and no change to `ActivityRecorder.php`, `TicketActivityResource.php`, `routes/api.php`, any policy, any controller, any seeder, `composer.json`, or **any file under `frontend/`**. The application files touched are exactly: `AppendOnlyBuilder.php` (new), `TicketActivity.php`, `Ticket.php`, `docs/api-contract.md`, `docs/erd.md`.

---

## Done Criteria

- [ ] **One** class, `App\Models\Builders\AppendOnlyBuilder`, refuses `update`, `upsert`, `increment`, `decrement`, `delete`, `forceDelete` and `truncate` with a `LogicException` — attached to `TicketActivity` by **`#[UseEloquentBuilder(...)]`**, matching the project's attribute idiom rather than a `protected static` property.
- [ ] Every Eloquent write path is covered by that one class and **proven** so: `save()` on a dirtied model, `update()`, `delete()`, `destroy()`, `updateQuietly()`, `deleteQuietly()`, `withoutEvents()`, a mass `query()->update()`, a relation `delete()`, and `truncate()` — **ten paths, ten tests.**
- [ ] **A test proves this is not an event listener.** Swapping the builder for `static::updating(…)` makes the mass-update, relation-delete and quiet-bypass tests fail while the naive ones pass — **run, and the result recorded in the PR** as the evidence for the design.
- [ ] **`ActivityRecorder` is untouched and a test says so.** `insert` is in Eloquent's `$passthru` (verified at `Builder.php:125`, re-checked in verification step 3), and `test_the_recorder_still_writes` fails if anyone "tightens" the guard by overriding `insert`.
- [ ] **`Ticket::forceDelete()` throws**, closing the `1 → 0` erasure TM-28 measured and turning four paragraphs of "never call this" into one enforced method. `forceDeleteQuietly()` and `forceDestroy()` are covered by the same override, and all three are tested.
- [ ] **Story 38's `test_deleting_a_ticket_cascades` is repaired, not deleted** — it now proves the database-level cascade with a database-level `DELETE`, which is the right layer for a foreign-key assertion. **The edit is recorded in the PR** because Story 38's plan file is read-only while its test is code.
- [ ] **AC3 is asserted behaviourally, not just at the schema level.** A soft-deleted ticket's rows still exist, are still readable through `withTrashed()->activities`, and the endpoint's `404` is pinned as Story 39's route-binding behaviour rather than data loss. A new row can still be recorded for a soft-deleted ticket, which Story 24's `deleted` event needs.
- [ ] **AC1 and AC4 are asserted by a scan of the whole route table**, not by naming today's endpoints: no `api/v1` route matching an activity or note path carries `PATCH`/`PUT`/`DELETE`, no route name matches an activity-mutation pattern, exactly one `activities` route exists and it is a `GET`, and an **admin** token firing every mutating verb at five plausible URLs leaves the row **byte-identical**.
- [ ] **Story 40's narrower test 14 is deleted, with the replacement named in the PR.** Two tests asserting one rule at different breadths is the shape that rots.
- [ ] **The residual hole is named in three places** — the class docblock, `docs/api-contract.md` and the PR: a raw `DB::table('ticket_activities')->update(...)` bypasses Eloquent and no model-layer guard stops it. **Story 38's `SingleWriterTest` stays in place** as the tripwire that owns that gap; only its comment is updated.
- [ ] **The FK cascade is recommended to the backlog owner, with TM-28's measurement attached**, and **not changed here** — it is a migration, no criterion mentions schema, and it would force a future purge story to decide what happens to the trail. The PR carries the recommendation in writing.
- [ ] `docs/erd.md`'s "rows are never modified" note is upgraded from intent to enforcement, naming the builder and the `forceDelete` refusal.
- [ ] **No migration, no schema change, no new dependency, no route, no controller, no policy, no seeder change, and no file under `frontend/`.** The application files touched are exactly five, enumerated in the PR.
- [ ] `pint --test` clean; **+17 net backend tests**; exactly **one** pre-existing failure remains (`PasswordThrottleTest`, TM-14), and **`composer test` does not produce a wall of unrelated failures** — which would mean `RefreshDatabase` is routing through the model.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 42 (TM-49, timeline stays usable on long-running tickets).**
