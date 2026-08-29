# Story 15 — Protect referential integrity on category delete (Story: TM-18)

## Prerequisites

- **Story 14 (TM-17) is implemented.** Verified on 2026-08-26: `backend/app/Http/Controllers/Api/V1/CategoryController.php`, `backend/app/Policies/CategoryPolicy.php`, `backend/app/Http/Resources/V1/CategoryResource.php`, `backend/app/Http/Requests/Api/V1/StoreCategoryRequest.php`, `backend/app/Http/Requests/Api/V1/UpdateCategoryRequest.php` all exist, five routes are registered (`php artisan route:list --path=api/v1/categories` prints them), and the SPA has `frontend/src/api/categories.ts`, `frontend/src/stores/categories.ts`, `frontend/src/views/AdminCategoriesView.vue`, `frontend/src/components/CategoryFormDialog.vue`, `frontend/src/components/CategoryBadge.vue`. This story **wraps `destroy()`; it does not replace it** — see [`14-story-admin-crud-categories-TM-17.md`](14-story-admin-crud-categories-TM-17.md), which recorded the hand-off.

- **TM-21 (tickets and requesters schema) must be _implemented_ before this story can compile.** This is the hard blocker. Verified on 2026-08-26: `grep -rn "category_id" backend/app backend/database` returns nothing, `backend/app/Models/` holds only `Category`, `Priority`, `Status`, `User`, and `backend/database/migrations/` holds seven files, none of them `create_tickets_table`. There is no `Ticket` model to count and no `tickets.category_id` to reassign. **Every backend task below is unwritable until TM-21 lands.**

  Confirm before starting: `php artisan db:table tickets` prints the table and lists `category_id` and `deleted_at`.

  The column names this plan relies on come from **`tools/jira/backlog.json`**, story `E4-S1` — `tickets` carries `category_id`, all foreign keys are constrained, and `tickets` uses soft deletes. Nothing else about `tickets` is assumed.

- **TM-21 opens a data-integrity window that only this story closes.** TM-17 shipped `DELETE` unguarded because nothing could be orphaned yet. The moment TM-21 lands, an admin can soft-delete a category that owns tickets and MySQL will not stop them. Measured on `mysql:8.4` in `tm-mysql-test` during planning, against a parent/child pair shaped like `categories`/`tickets`:

  | Probe | Result |
  |---|---|
  | `UPDATE cats SET deleted_at = NOW()` on a parent with 3 children, FK `ON DELETE RESTRICT` | **succeeded** — a soft delete is an `UPDATE` and never trips the constraint |
  | `DELETE FROM cats WHERE id = 1` on the same row | `ERROR 1451 (23000): Cannot delete or update a parent row` |
  | `INSERT INTO tix (category_id) VALUES (1)` **after** the parent was soft-deleted | **succeeded** — the FK cannot see `deleted_at`, so a trashed category still accepts new tickets |

  **This story must therefore ship in the same sprint as TM-21, and immediately after it.** Sequencing it later leaves the window open in production data.

- **Story 06 (TM-8) and Story 13 (TM-16) implemented** — `User`, `UserRole`, `Category`, and the `SoftDeletes` trait on `Category` (`backend/app/Models/Category.php:16`) are all in place.

- **Docker services running:** repo root — `docker compose up -d`; all three containers `healthy`. The suite talks to `tm-mysql-test` on **3307** (`backend/phpunit.xml:36–42`).

- **Measured baselines, 2026-08-26.** Backend: `php artisan test` → **93 tests, 92 passing, 274 assertions**. The single red test is `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (expects `422`, gets `429`) — **TM-14's defect, out of scope here, do not fix it and do not let it hide a new failure.** Frontend: `npm test` → **24 tests across 6 files**.

---

## Story Goal

Deleting a category can never orphan a ticket.

1. `DELETE /api/v1/categories/{category}` on a category with **no** tickets stays exactly as TM-17 shipped it: a soft delete, `204`, empty body.
2. `DELETE` on a category that **does** have tickets is refused with `422`, a message naming the count, and the list of categories the admin can move them to.
3. Re-sending the same `DELETE` with `reassign_to` moves every ticket to that category and then soft-deletes the original — inside one transaction, so the two can never half-happen.
4. Every moved ticket gains an activity row recording who moved it, from which category, to which, and why.

**Not in scope:** the "restore instead of recreating" 422 that TM-17's plan asked for (`14-…-TM-17.md:778`); hard-deleting a category; guarding priority or status deletes (neither has a delete endpoint); the timeline UI that renders these activity rows (**TM-46**); making activity rows append-only at the model layer (**TM-48**); backfilling TM-17's missing tests (see the note below).

**Explicitly assumed, because the backlog cannot be satisfied without it — read this before starting.** Acceptance criterion 3 says the reassignment "logs an activity row on every affected ticket", but `ticket_activities` and `ActivityRecorder` belong to **TM-45**, which `tools/jira/backlog.json` puts in **sprint 4** while TM-18 is **sprint 2**. There is no way to meet criterion 3 in sprint 2 without the table. This plan therefore **pulls the audit-trail slice forward**: tasks 1–3 create `ticket_activities`, `TicketActivityEvent` and `ActivityRecorder` **to TM-45's own specification, taken verbatim from `backlog.json`'s `E4-S1`… `E7-S1` acceptance criteria**, so TM-45 extends them rather than migrating them. This moves roughly 2 points from TM-45 into TM-18; the 2-point estimate on TM-18 is wrong as a result. If the team would rather re-sequence TM-18 after TM-45 instead, tasks 1–3 drop out and nothing else in this plan changes — but the orphaning window above then stays open for two sprints.

**TM-17 landed its sources without its tests.** The suite grew from 90 to 93 between TM-16 and today, and all three new tests are in `RouteAuthorizationTest`. There is **no** `backend/tests/Feature/Categories/` directory and **no** `.spec.ts` for `api/categories.ts`, `stores/categories.ts`, `AdminCategoriesView.vue`, `CategoryFormDialog.vue` or `CategoryBadge.vue`. TM-17 owns that gap; **this story does not backfill it, and must not copy it** — every file below ships with a test.

---

## Product rules (from story)

### Current behaviour

`CategoryController::destroy()` (**53–59**) is three lines: authorize, `$category->delete()`, `204`. It takes no input, asks no questions, and cannot fail except on authorization.

```php
public function destroy(Category $category): Response
{
    $this->authorize('delete', $category);
    $category->delete();

    return response()->noContent();
}
```

### New behaviour

The same route and the same verb, with an **optional** `reassign_to`. No new route, no new route name — `backend/routes/api.php` is left byte-identical, which matters because `RouteAuthorizationTest`'s `ACCESS` manifest (**16**) turns red on any unclassified route.

| Tickets in the category | `reassign_to` | Result |
|---|---|---|
| 0 | absent | `204`, soft delete. Unchanged from TM-17. |
| 0 | present | `204`, soft delete. The field is ignored, not an error — a client that read a stale count must not be punished for it. |
| ≥ 1 | absent | `422` naming the count and listing the reassignment options. Nothing is written. |
| ≥ 1 | present and valid | Tickets move, activity rows are written, category is soft-deleted. `204`. |
| ≥ 1 | present and invalid | `422` under `errors.reassign_to`. Nothing is written. |

### `reassign_to` may arrive in the query string or the JSON body

Measured on Laravel **13.26.1** during planning: on a `DELETE`, `Request::input()` and `Request::all()` read the query string **and** the JSON body.

```
DELETE /api/v1/categories/3?reassign_to=7   → input('reassign_to') === '7'   (string)
DELETE /api/v1/categories/3  {"reassign_to":9} → input('reassign_to') === 9  (int)
```

So one set of validation rules covers both, and the SPA can use whichever it prefers. Read the value back with `$request->integer('reassign_to')` so the string form is normalised.

### Counting includes soft-deleted tickets

TM-21 gives `tickets` soft deletes and **TM-28** will use them. A trashed ticket that gets restored must still have a category, so a trashed ticket blocks the delete and gets reassigned like any other. **Count and update with `withTrashed()` everywhere.**

### The 422 body is a documented superset of Laravel's envelope

`docs/api-contract.md:10–11` says errors follow Laravel's default validation envelope "unless a story states otherwise". This story states otherwise: `message` and `errors` keep their exact shape and meaning, and two keys are **added** alongside them (`ticket_count`, `reassign_to_options`). Nothing is removed or renamed, so `frontend/src/api/errors.ts:5–10` (`validationErrors`) and `:11–21` (`errorMessage`) keep working untouched.

### The guard must hold a lock, not just run a query

Counting and then deleting in two statements is a race: a ticket created in between lands in a category that is about to be trashed, and the third probe above proves MySQL will let it. Measured fix, on `mysql:8.4`:

> Session 1: `START TRANSACTION; SELECT id FROM cats WHERE id = 2 FOR UPDATE;`
> Session 2, `innodb_lock_wait_timeout = 2`: `INSERT INTO tix (category_id) VALUES (2);` → **`ERROR 1205 (HY000): Lock wait timeout exceeded`**

InnoDB takes a shared lock on the parent row for every child insert's foreign-key check, and that conflicts with the exclusive lock `FOR UPDATE` holds. So `Category::query()->whereKey(…)->lockForUpdate()->first()` as the **first statement inside the transaction** blocks concurrent ticket creation into this category for the rest of it. Without it the guard is decorative.

### A FormRequest authorizes before it validates — and this route is already under test for it

`StoreCategoryRequest:13` carries `public function authorize(): bool { return Gate::allows('create', Category::class); }`. That line is not decoration: a `FormRequest` resolves and validates **before** the controller body runs, so without it an agent posting an empty body got `422` where `RouteAuthorizationTest::test_agent_refused_by_policy_admin_routes` (**43–50**) demands `403`. Reproduced during planning — the test failed exactly that way before the line was added, and passes 12/12 with it.

That test creates a **real** category and calls `DELETE /api/v1/categories/{id}` with an agent token. **`DestroyCategoryRequest` must therefore carry its own `authorize()`**, or this story turns that test red on contact.

---

## Context — Read These Files First

1. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — whole file, 60 lines. **Lines 53–59** are the method this story rewrites; **18–25** show the `authorize`-then-`validate` shape every method here uses. Note there is **no `DB::transaction`** anywhere in the file — this story adds the first one.
2. `backend/app/Http/Requests/Api/V1/StoreCategoryRequest.php` — **line 13** is the `authorize()` idiom `DestroyCategoryRequest` copies; **lines 30–33** show how a closure rule reports a message. Namespace is `Api\V1`, **not** `Api\V1\Admin`.
3. `backend/app/Http/Requests/Api/V1/UpdateCategoryRequest.php` — **line 19**, `$this->route('category')`, is how a request reaches the bound model.
4. `backend/app/Policies/CategoryPolicy.php` — **lines 30–33**, `delete()`. **Do not change it.** Ownership of "may this user delete categories at all" stays here; "would this delete orphan anything" is a validation concern, not an authorization one.
5. `backend/app/Models/Category.php` — 34 lines. `#[Fillable]` (**12**), `SoftDeletes` (**16**), and the `active()` / `ordered()` scopes (**24–33**) this story reuses for the options list. Task 5 adds one relation here and changes nothing else.
6. `backend/app/Models/Status.php` and `backend/database/migrations/2026_08_26_073219_create_statuses_table.php` — the repo's model and migration idiom: `#[Fillable]` as an attribute, `casts()` as a method, `Schema::create` with a closure. Match both.
7. `backend/app/Enums/StatusBucket.php` — 16 lines. The backed-enum idiom, including the `values(): array` helper. `TicketActivityEvent` copies it exactly.
8. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **line 16** (`ACCESS`), **43–50** (`test_agent_refused_by_policy_admin_routes`, which exercises `DELETE` with a real id and an agent token), **119–122** (`routesFor()` and its `$categoryId` parameter). This story must leave every one of them passing **without editing the file**.
9. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — **lines 57–77**, `update()`. The only existing `DB::transaction` in the codebase, and the only existing `lockForUpdate()` (**88**). Task 6's transaction follows this shape.
10. `docs/api-contract.md` — **lines 10–11** (the "unless a story states otherwise" clause this story invokes), **line 16**, **lines 39–43** (the five category rows; the `DELETE` row at **43** still says "Unguarded until TM-18").
11. `frontend/src/api/categories.ts` — **line 50**, `deleteCategory`. Task 10 widens this signature.
12. `frontend/src/stores/categories.ts` — **lines 55–58**, `remove()`. It has **no `try`/`catch`**, so a `422` from this story's guard would reject unhandled and the admin would see nothing happen. Task 11 fixes that.
13. `frontend/src/components/CategoryFormDialog.vue` — whole file, 64 lines. The dialog pattern (`defineProps` / `defineEmits` / `data-testid` / `validationErrors`) that `CategoryDeleteDialog.vue` copies.
14. Grep for `category_id` in `backend/` **before writing anything**. If it returns nothing, TM-21 has not landed and this story cannot start.
15. `tools/jira/backlog.json` — story `E7-S1` (TM-45). Its six acceptance criteria are the specification tasks 1–3 build to. Read them; do not improvise a different table.

---

## Backend Tasks

### 1 — The activity event enum

**Create file: `backend/app/Enums/TicketActivityEvent.php`**

```php
<?php

namespace App\Enums;

/**
 * Every kind of thing that can happen to a ticket. TM-45 owns this enum and
 * adds the rest of the cases (assigned, status changed, escalated, noted);
 * TM-18 needs exactly one and creates the file so the reassignment it performs
 * is auditable in sprint 2 rather than sprint 4. Add cases, never rename or
 * renumber them — the string is what lands in the `event` column.
 */
enum TicketActivityEvent: string
{
    case CategoryChanged = 'category_changed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`CategoryChanged` rather than a reassignment-specific name on purpose: **TM-27** (edit a ticket) changes a ticket's category too and must reuse this case. What distinguishes a forced move from an edit is `meta.reason`, set in task 3.

### 2 — The `ticket_activities` table

**Create file: `backend/database/migrations/<timestamp>_create_ticket_activities_table.php`**

Generate it with `php artisan make:migration create_ticket_activities_table`. **The timestamp must sort after TM-21's `create_tickets_table`** or the foreign key cannot be created; confirm with `php artisan migrate:status` before running it.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // Null means the system acted, not a person. TM-45's criterion 5.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->string('field', 50)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->json('meta')->nullable();
            // created_at only: an activity row is never updated. TM-48 will
            // enforce that at the model layer as a second line of defence.
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'created_at'], 'ticket_activities_timeline_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_activities');
    }
};
```

Every column, the composite index and the nullable `user_id` come straight from TM-45's acceptance criteria in `backlog.json`. Three choices TM-45 may revisit and this story does not defend:

- **`cascadeOnDelete()` on `ticket_id`.** A *soft*-deleted ticket keeps its activities either way — a soft delete is an `UPDATE`, and the first probe in Prerequisites shows that never touches a foreign key — so TM-48's criterion 3 is satisfied. `CASCADE` only fires on a hard delete, which no story performs.
- **`nullOnDelete()` on `user_id`.** Users are deactivated, never deleted (`UserPolicy::delete()` returns `false`), so this never fires today. It exists so a future hard delete cannot take history with it.
- **`string('event', 50)`, not an `ENUM` column.** `statuses.bucket` uses a real `ENUM` because its domain is fixed by TM-16. This one grows every sprint, and an `ENUM` would need a migration per new case.

**Do not add `updated_at`.** `$table->timestamps()` here would contradict TM-48.

### 3 — The model and the recorder

**Create file: `backend/app/Models/TicketActivity.php`**

```php
<?php

namespace App\Models;

use App\Enums\TicketActivityEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ticket_id', 'user_id', 'event', 'field', 'old_value', 'new_value', 'meta'])]
class TicketActivity extends Model
{
    /**
     * There is no updated_at column — an activity row is written once. Setting
     * UPDATED_AT to null keeps Eloquent managing created_at while leaving the
     * other half alone; $timestamps = false would stop managing both.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['event' => TicketActivityEvent::class, 'meta' => 'array'];
    }
}
```

**Create file: `backend/app/Services/ActivityRecorder.php`** — `backend/app/Services/` does not exist yet; create it.

```php
<?php

namespace App\Services;

use App\Enums\TicketActivityEvent;
use App\Models\TicketActivity;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only class that writes to ticket_activities. TM-45 widens this — more
 * events, a TicketService caller — but the rule and the constructor stay:
 * nothing else inserts into that table, ever.
 */
class ActivityRecorder
{
    private const CHUNK = 500;

    public function record(
        int $ticketId,
        TicketActivityEvent $event,
        ?int $userId = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        array $meta = [],
    ): void {
        $this->recordMany([$ticketId], $event, $userId, $field, $oldValue, $newValue, $meta);
    }

    /**
     * One row per ticket, written in bulk. A category can own tens of
     * thousands of tickets and this is called once per delete.
     *
     * @param  list<int>  $ticketIds
     */
    public function recordMany(
        array $ticketIds,
        TicketActivityEvent $event,
        ?int $userId = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        array $meta = [],
    ): void {
        if ($ticketIds === []) {
            return;
        }
        // TM-45 criterion 6: the activity and the change it describes share a
        // transaction, so the two can never disagree. Enforced rather than
        // documented, because a caller that forgets would look fine in tests.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivityRecorder must be called inside a database transaction.');
        }

        // insert() bypasses casts and timestamps, so meta is encoded and
        // created_at is stamped here.
        $now = now();
        $encodedMeta = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);

        foreach (array_chunk($ticketIds, self::CHUNK) as $chunk) {
            TicketActivity::insert(array_map(fn (int $ticketId): array => [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'event' => $event->value,
                'field' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'meta' => $encodedMeta,
                'created_at' => $now,
            ], $chunk));
        }
    }
}
```

`JSON_UNESCAPED_UNICODE` because both databases run utf8mb4 and a category named `الشبكة` must be readable in the `meta` column, not `ال...`.

### 4 — The destroy request

**Create file: `backend/app/Http/Requests/Api/V1/DestroyCategoryRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DestroyCategoryRequest extends FormRequest
{
    /**
     * A FormRequest validates before the controller body runs, so without this
     * an agent would get 422 where RouteAuthorizationTest demands 403. Same
     * reason StoreCategoryRequest:13 exists.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->route('category'));
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'reassign_to' => [
                'sometimes',
                'integer',
                Rule::notIn([$category?->getKey()]),
                Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reassign_to.not_in' => 'A category cannot be reassigned to itself.',
            'reassign_to.exists' => 'That category does not exist, is deactivated, or has been deleted.',
        ];
    }
}
```

Three constraints, each load-bearing:

- **`Rule::notIn`** — reassigning to the category being deleted would move every ticket onto a row that is trashed one statement later. Silent orphaning, which is the whole point of the story.
- **`whereNull('deleted_at')`** — `Rule::exists` uses the raw query builder, so the `SoftDeletes` global scope does **not** apply and a trashed category would otherwise pass.
- **`where('is_active', true)`** — the options list in task 7 offers active categories only, so accepting an inactive one would accept something never offered. An admin who wants a deactivated target reactivates it first.

**`sometimes`, not `nullable`** — an absent field is the "just tell me the count" call; an explicit `null` is a client bug and should be a `422`, not a silent count.

### 5 — The relation

**File: `backend/app/Models/Category.php`**

Add one method after `scopeOrdered()` (**30–33**) and the matching import. Change nothing else — in particular leave `#[Fillable]` (**12**) and `#[UsePolicy]` (**13**) exactly as they are.

```php
/**
 * Soft-deleted tickets are still tickets: TM-28 lets an admin restore one,
 * and a restored ticket must still have a category. Callers that count or
 * reassign must add withTrashed(); the relation itself stays conventional.
 *
 * @return HasMany<Ticket, $this>
 */
public function tickets(): HasMany
{
    return $this->hasMany(Ticket::class);
}
```

Imports: `use Illuminate\Database\Eloquent\Relations\HasMany;`. `Ticket` is `App\Models\Ticket`, so no import is needed — same namespace.

### 6 — The guard

**File: `backend/app/Http/Controllers/Api/V1/CategoryController.php`**

Replace `destroy()` (**53–59**). Leave `index`, `store`, `show` and `update` untouched.

```php
/**
 * Soft delete, guarded. A category with tickets is refused with a 422 that
 * names the count and offers somewhere to put them; re-sending with
 * reassign_to moves them and then deletes.
 *
 * Everything runs inside one transaction that opens by locking the category
 * row. InnoDB takes a shared lock on that row for every ticket insert's
 * foreign-key check, so the exclusive lock blocks a ticket from being created
 * into this category between the count and the delete — measured, and the
 * only reason the count can be trusted.
 */
public function destroy(DestroyCategoryRequest $request, Category $category, ActivityRecorder $recorder): Response|JsonResponse
{
    $this->authorize('delete', $category);
    $targetId = $request->has('reassign_to') ? $request->integer('reassign_to') : null;

    return DB::transaction(function () use ($request, $category, $targetId, $recorder): Response|JsonResponse {
        Category::query()->whereKey($category->getKey())->lockForUpdate()->first();

        $blocking = $category->tickets()->withTrashed()->count();

        if ($blocking > 0) {
            if ($targetId === null) {
                return $this->reassignmentRequired($category, $blocking);
            }
            $this->reassign($category, $targetId, $request->user()->getKey(), $recorder);
        }

        $category->delete();

        return response()->noContent();
    });
}
```

Add the two private helpers below it:

```php
/**
 * The 422. Laravel's message/errors envelope verbatim, plus two keys the SPA
 * needs to build the picker — documented in docs/api-contract.md rather than
 * left for a client to discover.
 */
private function reassignmentRequired(Category $category, int $blocking): JsonResponse
{
    $options = Category::query()->active()->whereKeyNot($category->getKey())->ordered()->get();

    $message = $options->isEmpty()
        ? sprintf('This category still has %d %s, and there is no other active category to move them to. Create one first.', $blocking, Str::plural('ticket', $blocking))
        : sprintf('This category still has %d %s. Choose another category to move them to, then delete again.', $blocking, Str::plural('ticket', $blocking));

    return response()->json([
        'message' => $message,
        'errors' => ['reassign_to' => [$message]],
        'ticket_count' => $blocking,
        'reassign_to_options' => CategoryResource::collection($options)->resolve(),
    ], 422);
}

/**
 * Move every ticket, trashed ones included, and record why on each. Chunked
 * because a busy category can own tens of thousands of rows and both the IN
 * list and the insert would otherwise be unbounded.
 */
private function reassign(Category $category, int $targetId, int $actorId, ActivityRecorder $recorder): void
{
    $target = Category::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

    $ticketIds = $category->tickets()->withTrashed()->pluck('id')->all();

    foreach (array_chunk($ticketIds, 500) as $chunk) {
        Ticket::withTrashed()->whereIn('id', $chunk)->update(['category_id' => $target->getKey()]);
    }

    $recorder->recordMany(
        $ticketIds,
        TicketActivityEvent::CategoryChanged,
        $actorId,
        'category_id',
        (string) $category->getKey(),
        (string) $target->getKey(),
        [
            'reason' => 'category_deleted',
            'from_name' => $category->name,
            'to_name' => $target->name,
        ],
    );
}
```

Imports to add at the top of the file: `App\Enums\TicketActivityEvent`, `App\Http\Requests\Api\V1\DestroyCategoryRequest`, `App\Models\Ticket`, `App\Services\ActivityRecorder`, `Illuminate\Support\Facades\DB`. `Str`, `JsonResponse`, `Response` and `CategoryResource` are already imported (**8, 10, 13, 14**).

Six details that are not negotiable:

- **`lockForUpdate()->first()` is the first statement in the transaction.** Move it and the race reopens.
- **`$category->tickets()->withTrashed()`** for both the count and the pluck. A trashed ticket blocks and gets moved.
- **`Ticket::withTrashed()->whereIn(...)->update(...)`**, not `$category->tickets()->update(...)`, because the relation's `category_id` constraint stops matching the moment the first chunk lands.
- **`$request->has('reassign_to')`, not `$request->filled(...)`.** `filled()` treats `0` as absent; `0` is a value the validator should reject, not one the controller should silently ignore.
- **The target is locked too.** Two admins deleting two categories into each other simultaneously deadlock otherwise; locking both rows makes MySQL detect it and roll one back cleanly.
- **`$recorder` is a method-injected dependency**, not `new ActivityRecorder()`, so a test can swap it. Laravel resolves controller-method dependencies alongside route parameters — `Category $category` still binds.

### 7 — The response shape, worked

For a category `3` ("Billing") holding 12 tickets, with "Hardware" (id 1) and "Network" (id 4) active:

```json
{
  "message": "This category still has 12 tickets. Choose another category to move them to, then delete again.",
  "errors": {
    "reassign_to": ["This category still has 12 tickets. Choose another category to move them to, then delete again."]
  },
  "ticket_count": 12,
  "reassign_to_options": [
    { "id": 1, "name": "Hardware", "slug": "hardware", "description": null, "color": "#EF4444", "is_active": true, "sort_order": 1, "created_at": "...", "updated_at": "..." },
    { "id": 4, "name": "Network", "slug": "network", "description": null, "color": "#3B82F6", "is_active": true, "sort_order": 4, "created_at": "...", "updated_at": "..." }
  ]
}
```

`Str::plural('ticket', 1)` returns `ticket` and `Str::plural('ticket', 12)` returns `tickets` — measured, so "1 ticket" reads correctly with no special case.

`CategoryResource::collection($options)->resolve()` returns a plain PHP array — measured; `AnonymousResourceCollection` inherits `resolve()` from `JsonResource`. **Do not** call `->toArray()` on it, and do not hand-roll the option shape: reusing `CategoryResource` means the picker gets the same `color` the badge already renders.

### 8 — The contract

**File: `docs/api-contract.md`**

Replace the `DELETE` row (**43**) — it currently reads "Soft delete. Unguarded until TM-18.":

| `DELETE` | `/api/v1/categories/{category}` | Soft delete, refused with `422` if tickets would be orphaned. Accepts `reassign_to`. | admin bearer (CategoryPolicy) | TM-18 |

Then add a `### DELETE /api/v1/categories/{category}` section after the categories section, documenting: the optional `reassign_to` in either the query string or the JSON body; the `204` on success; the `422` body with its two extra keys and a worked example; that the count includes soft-deleted tickets; that the target must be an existing, active, non-deleted category and not the one being deleted; and that the whole thing is one transaction, so a failure leaves both the tickets and the category untouched.

Amend the conventions bullet at **lines 10–11** so the superset is stated where a client will look for it:

> - Requests and responses are JSON; errors follow Laravel's default validation envelope unless a story states otherwise. `DELETE /api/v1/categories/{category}` **adds** `ticket_count` and `reassign_to_options` alongside `message` and `errors`; it removes and renames nothing.

**File: `docs/erd.md`**

Add `ticket_activities` to the `erDiagram` block and a row to the table-notes table (**the format is at lines 57–62**):

| `ticket_activities` | Append-only audit trail; `user_id` null means the system acted. Table created by TM-18 for the category-reassignment trail; **TM-45 owns it** and adds the remaining event types. | TM-18 / TM-45 |

**Do not touch the `categories` row** — this story adds no column to it.

### 9 — What must not change

- **`backend/routes/api.php`** — byte-identical. No new route, no new name, so `RouteAuthorizationTest`'s manifest (**16**) needs no edit.
- **`backend/tests/Feature/Authorization/RouteAuthorizationTest.php`** — do not edit. `test_agent_refused_by_policy_admin_routes` (**43–50**) already covers `DELETE` with a real category id and an agent token, and it must keep passing on the strength of task 4's `authorize()` alone.
- **`backend/app/Policies/CategoryPolicy.php`** — do not edit.
- **`backend/database/seeders/CategorySeeder.php`** — do not edit. Its `withTrashed()` behaviour is TM-16's and this story does not change what a trashed category means.

---

## Frontend Tasks

This story is labelled `backend`, and the reassignment **picker UI** is not in any acceptance criterion. But TM-17's delete button breaks the moment the guard ships — `frontend/src/stores/categories.ts:55–58` is `async function remove(id) { await deleteCategory(id); await load() }` with no `catch`, so a `422` rejects unhandled and the admin sees the row simply not disappear, with no message. Tasks 10 and 11 are the minimum that keeps that screen honest and satisfies criterion 2 end to end. Nothing else in `frontend/` changes.

### 10 — The API module

**File: `frontend/src/api/categories.ts`**

Widen `deleteCategory` (**50**) and export the 422 shape:

```ts
export interface CategoryDeleteBlocked {
  message: string
  ticket_count: number
  reassign_to_options: Category[]
}
export async function deleteCategory(
  id: number,
  reassignTo?: number,
): Promise<void> {
  await client.delete(`/categories/${id}`, {
    data: reassignTo === undefined ? undefined : { reassign_to: reassignTo },
  })
}
```

`reassignTo` is optional, so every existing call site (`stores/categories.ts:55–58`) compiles unchanged. axios sends a `DELETE` body through `config.data`; the backend reads query string and body alike (measured), so either would work — the body is chosen because it matches `createCategory`/`updateCategory` on the same module.

Add a reader for the extra keys next to `frontend/src/api/errors.ts`'s existing helpers — **in `categories.ts`, not `errors.ts`**, because it is specific to this endpoint and `errors.ts` is shared:

```ts
export function deleteBlockedBy(error: unknown): CategoryDeleteBlocked | null {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return null
  const d = error.response.data as Partial<CategoryDeleteBlocked> | undefined
  if (typeof d?.ticket_count !== 'number' || !Array.isArray(d.reassign_to_options))
    return null
  return {
    message: d.message ?? '',
    ticket_count: d.ticket_count,
    reassign_to_options: d.reassign_to_options,
  }
}
```

The `typeof` / `Array.isArray` pair is what separates this 422 from an ordinary validation 422 (a rejected `reassign_to`), which carries neither key and must fall through to `validationErrors()`.

### 11 — The store and the dialog

**File: `frontend/src/stores/categories.ts`** — `remove()` (**55–58**) takes the optional target and stops swallowing failures:

```ts
  async function remove(id: number, reassignTo?: number) {
    await deleteCategory(id, reassignTo)
    await load()
  }
```

It still rethrows; the caller decides. Do **not** add a `try`/`catch` here — `load()` already owns `error`, and catching here would hide the 422 from the dialog.

**Create file: `frontend/src/components/CategoryDeleteDialog.vue`** — copy the shape of `CategoryFormDialog.vue` (`<script setup lang="ts">`, `defineProps`, `defineEmits<{deleted:[];close:[]}>()`, a `data-testid` on every interactive element).

It opens on the `422`, shows `ticket_count` and the message, renders `reassign_to_options` as a `<select>` (using `CategoryBadge` for each option's colour is optional; a plain `<option>` is enough), and on confirm calls `store.remove(id, chosenId)`. When `reassign_to_options` is empty it shows the message and offers only Cancel — there is nowhere to move the tickets to. Required test ids: `category-delete-count`, `category-delete-target`, `category-delete-confirm`, `category-delete-cancel`, `category-delete-error`.

**File: `frontend/src/views/AdminCategoriesView.vue`** — `remove()` (**line 23**) currently does `if(window.confirm('Delete category?')) void store.remove(c.id)`. Wrap it so a blocked delete opens the dialog instead of vanishing:

```ts
async function remove(c: Category) {
  if (!window.confirm('Delete category?')) return
  try {
    await store.remove(c.id)
  } catch (e) {
    const blocked = deleteBlockedBy(e)
    if (blocked) blocking.value = { category: c, ...blocked }
    else store.error = errorMessage(e)
  }
}
```

and render `<CategoryDeleteDialog v-if="blocking" ... />` next to the existing `<CategoryFormDialog>`.

---

## Edge Cases & Failure Modes

- **A ticket is created into the category between the count and the delete.** Blocked. The transaction's first statement is `Category::query()->whereKey(…)->lockForUpdate()->first()` (task 6); InnoDB's foreign-key check takes a shared lock on that row for every ticket insert, which the exclusive lock rejects — measured as `ERROR 1205` under a 2-second `innodb_lock_wait_timeout`. The inserting request waits for the delete to commit and then fails its own foreign-key check against a trashed-but-present row, or succeeds against a category that survived the 422. Never both.

- **A ticket already in the category is soft-deleted (TM-28).** Still blocks, still gets reassigned. Both the count and the pluck use `withTrashed()` (task 6). Without it, restoring a ticket later would resurrect a row pointing at a deleted category.

- **`reassign_to` names the category being deleted.** `422` under `errors.reassign_to`, message "A category cannot be reassigned to itself." — `Rule::notIn` in task 4. This is the one invalid value that would otherwise *look* like it worked: the update succeeds, then the same row is trashed, and every ticket is orphaned.

- **`reassign_to` names a soft-deleted category.** `422`. `Rule::exists` runs on the raw query builder, so the `SoftDeletes` global scope does not apply — hence the explicit `whereNull('deleted_at')` in task 4. Getting this wrong is silent, not loud.

- **`reassign_to` names a deactivated category.** `422`. `->where('is_active', true)` in task 4, matching what `reassignmentRequired()` offers.

- **`reassign_to` is `0`, `"abc"`, `null`, or an array.** `422` from `integer` / `exists`. The controller reads it with `$request->has(...)` rather than `filled(...)` precisely so `0` reaches the validator instead of being read as "absent".

- **The category has tickets and there is no other active category.** `422`, `reassign_to_options: []`, and the message changes to "…and there is no other active category to move them to. Create one first." (task 7). The frontend dialog shows Cancel only (task 11). The admin is not stuck — they create or reactivate a category and retry.

- **An agent calls `DELETE`.** `403`, before any query runs, from `DestroyCategoryRequest::authorize()` (task 4). Without that method the FormRequest would validate first and answer `422`, and `RouteAuthorizationTest::test_agent_refused_by_policy_admin_routes` (**43–50**) would fail — reproduced during planning on the `store` route before `StoreCategoryRequest:13` was added.

- **An unknown or already-deleted category id.** `404`, for agents and admins alike — measured today: `DELETE /api/v1/categories/999999` returns `404` to both. `SubstituteBindings` resolves before the policy and before the FormRequest, and category existence is not a secret. A second `DELETE` on the same id is therefore `404`, unchanged from TM-17.

- **Two admins delete two categories into each other at the same time.** Both `lockForUpdate()` calls in task 6 (source and target) mean MySQL sees the cycle and rolls one transaction back with `ERROR 1213` / `SQLSTATE 40001`. Laravel surfaces that as a `500`; the loser retries and gets a clean answer. Locking only the source would instead let both proceed and produce a category that is trashed while holding tickets.

- **The activity insert fails after the tickets have moved.** Impossible to observe: the update, the insert and the soft delete share one `DB::transaction` (task 6), and `ActivityRecorder::recordMany()` throws `LogicException` outright if `DB::transactionLevel() === 0`. A failure rolls all three back.

- **A category with tens of thousands of tickets.** The id list is plucked once and both the `whereIn` update and the activity insert run in chunks of 500 (tasks 3 and 6). The single long transaction still holds row locks on every ticket it touches; that is inherent to the atomicity criterion 3 demands, and it is an admin action on a bounded set, not a hot path.

- **A category name containing Arabic or emoji lands in `meta`.** Stored readable, not `\u…` escaped — `json_encode(..., JSON_UNESCAPED_UNICODE)` in task 3, and both containers run `--character-set-server=utf8mb4`.

- **`reassign_to` sent while the category has zero tickets.** `204`, field ignored. A client that read a stale count and pre-selected a target must not be punished for it; see the behaviour table in Product rules.

- **The SPA's delete button before task 11.** A `422` rejects unhandled inside `stores/categories.ts:55–58` and the admin sees nothing at all — no message, no row removed. This is the regression tasks 10 and 11 exist to prevent; **do not ship the backend without them.**

---

## Test Plan

Run backend tests from `backend/`, frontend from `frontend/`. Backend feature tests use `RefreshDatabase` against `tm-mysql-test` on **3307** — never SQLite.

### Backend

1. **Create `backend/tests/Feature/Categories/DeleteCategoryTest.php`** — the story's core. Follow `tests/Feature/Policies/UserPolicyTest.php` for setup shape and `Category::create([...])` for fixtures (there is no `CategoryFactory` yet — that is TM-59).
   - `test_empty_category_soft_deletes` — `204`; `Category::count()` drops by one; `Category::withTrashed()->count()` is unchanged; `deleted_at` is set.
   - `test_category_with_tickets_is_refused` — three tickets; `422`; `assertJsonPath('ticket_count', 3)`; the message contains "3 tickets"; `errors.reassign_to` is present; the category is **still live**; no `ticket_activities` row exists.
   - `test_refusal_pluralises_one_ticket` — one ticket; the message contains "1 ticket" and **not** "1 tickets".
   - `test_refusal_lists_active_alternatives` — asserts `reassign_to_options` contains every other active category, **excludes** the category being deleted, **excludes** deactivated ones, and that each entry carries `id`, `name` and `color`.
   - `test_refusal_with_no_alternatives_says_so` — only one category exists and it has tickets; `reassign_to_options` is `[]` and the message contains "no other active category".
   - `test_soft_deleted_tickets_block_the_delete` — the only ticket is soft-deleted; still `422` with `ticket_count` 1.
   - `test_reassignment_moves_tickets_and_deletes` — `204`; every ticket's `category_id` is the target; the source is trashed; the target is untouched.
   - `test_reassignment_moves_soft_deleted_tickets_too` — a trashed ticket's `category_id` moves as well.
   - `test_reassign_to_self_is_refused` — `422` under `errors.reassign_to`, message "A category cannot be reassigned to itself."; nothing moved; the category is still live.
   - `test_reassign_to_deleted_category_is_refused` and `test_reassign_to_inactive_category_is_refused` — both `422`; nothing moved.
   - `test_reassign_to_unknown_id_is_refused` — `422`, not `404`.
   - `test_reassign_to_is_ignored_when_no_tickets` — `204` and the target is untouched.
   - `test_reassign_to_accepts_a_query_string` — same call with `?reassign_to=<id>` and no body; `204`. Guards the measured `input()` merge.
   - `test_failure_rolls_everything_back` — force the delete to fail after the move (bind a fake `ActivityRecorder` that throws) and assert the tickets kept their original `category_id` and the category is still live.
   - `test_agent_is_forbidden` — agent token, real category id, `403`, and **no** validation envelope in the body.
   - `test_unknown_category_is_not_found` — `404` for admin and agent alike.
2. **Create `backend/tests/Feature/Categories/CategoryReassignmentActivityTest.php`** — criterion 3 in isolation.
   - `test_one_activity_row_per_moved_ticket` — five tickets, one reassignment, exactly five `ticket_activities` rows.
   - `test_activity_row_records_who_what_and_why` — `event` is `category_changed`, `field` is `category_id`, `old_value` and `new_value` are the two ids as strings, `user_id` is the acting admin, `meta.reason` is `category_deleted`, `meta.from_name` and `meta.to_name` are the two names.
   - `test_activity_meta_keeps_unicode_readable` — a category named `الشبكة`; read the raw column with `DB::table('ticket_activities')->value('meta')` and assert it contains the Arabic text, not `ا`.
   - `test_no_activity_rows_when_the_delete_is_refused` — the `422` path writes nothing.
   - `test_activity_created_at_is_set_and_updated_at_absent` — `created_at` is non-null; `Schema::hasColumn('ticket_activities', 'updated_at')` is `false`.
3. **Create `backend/tests/Unit/Services/ActivityRecorderTest.php`** — unit, no HTTP.
   - `test_recording_outside_a_transaction_throws` — expects `LogicException`.
   - `test_empty_ticket_list_writes_nothing`.
   - `test_bulk_insert_chunks_beyond_five_hundred` — 501 ids inside a transaction produce 501 rows.
4. **Create `backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php`** — follow `tests/Feature/Database/UsersTableSchemaTest.php` exactly. Assert every column from task 2 exists with the right nullability, that `ticket_activities_timeline_index` exists over `(ticket_id, created_at)`, that `updated_at` does not exist, and that the two foreign keys point at `tickets` and `users`.
5. **Create `backend/tests/Unit/Enums/TicketActivityEventTest.php`** — mirror `tests/Unit/Enums/UserRoleTest.php`. Assert `TicketActivityEvent::CategoryChanged->value === 'category_changed'` and that `values()` returns it.
6. **Do not modify** `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`. It must stay green untouched; if it goes red, task 4's `authorize()` is missing or wrong.
7. **Do not touch** `tests/Feature/Auth/PasswordThrottleTest.php`. It is red on arrival (TM-14) and stays red.

### Frontend

8. **Create `frontend/src/api/categories.spec.ts`** — `deleteCategory(3)` sends no body; `deleteCategory(3, 7)` sends `{reassign_to:7}`; `deleteBlockedBy` returns the payload for a 422 carrying `ticket_count` and `reassign_to_options`, and `null` for a plain validation 422, a 403, a 500 and a non-axios error. Follow `frontend/src/api/auth.spec.ts` for the axios mock.
9. **Create `frontend/src/stores/categories.spec.ts`** — restricted to `remove()`: it forwards the optional target to the API, calls `load()` on success, and **rethrows** on failure. (The rest of this store is TM-17's untested surface and stays that way here.)
10. **Create `frontend/src/components/CategoryDeleteDialog.spec.ts`** — renders the count and the options; confirming calls `store.remove` with the chosen id; an empty options list hides the select and the confirm button; a rejected confirm renders `category-delete-error`. Follow `frontend/src/views/HealthView.spec.ts` for mounting with Pinia.

Expected totals: **+27 backend tests** (93 → 120, with the one TM-14 failure still red) and **+3 frontend spec files**.

---

## Migration / Rollback

One new table, no change to an existing one. `php artisan migrate` creates `ticket_activities`; `php artisan migrate:rollback --step=1` drops it.

- **Half-applied state.** The migration is a single `Schema::create`, so it either exists or it does not. If it fails, it fails on the `ticket_id` foreign key, which means TM-21 has not run — check `php artisan migrate:status`.
- **Rolling back after the guard has shipped.** Dropping `ticket_activities` while `ActivityRecorder` is still deployed makes every reassignment throw. Roll the code back first, then the migration.
- **Rolling back the guard alone** (revert task 6, keep the table) is safe and returns `destroy()` to TM-17's unguarded behaviour — with the orphaning window from Prerequisites reopened.
- **Data written by this story is not reversible.** Once tickets have been reassigned and the source category trashed, `migrate:rollback` does not put them back. Restoring the category (`Category::withTrashed()->find($id)->restore()`) leaves the tickets where they were moved; the activity rows say where they came from.

---

## Verification Steps

1. **Services up:** repo root — `docker compose up -d && docker compose ps`; all three containers `healthy`.
2. **TM-21 present:** `backend/` — `php artisan db:table tickets` lists `category_id` and `deleted_at`. **If this fails, stop; the story cannot be implemented.**
3. **Migration applies:** `backend/` — `php artisan migrate` runs the new file, `php artisan db:table ticket_activities` lists all nine columns, and `php artisan migrate:rollback --step=1 && php artisan migrate` round-trips cleanly.
4. **Backend builds:** `backend/` — `php artisan route:list --path=api/v1/categories` still prints exactly **5** routes with unchanged names, and `git diff --stat backend/routes/api.php` is empty.
5. **Backend tests:** `backend/` — `composer test`. Expect **120 tests, 119 passing**, the single failure being `PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (`422` vs `429`). Any other failure is this story's.
6. **Regression:** `backend/` — `php artisan test --filter=RouteAuthorizationTest` → 12 passing, with the file unmodified (`git diff --stat backend/tests/Feature/Authorization/RouteAuthorizationTest.php` empty).
7. **Guard by hand:** `backend/` — `php artisan serve`, log in as the seeded admin, `DELETE /api/v1/categories/{id}` on a category with tickets. Expect `422`, a `ticket_count`, and a populated `reassign_to_options`. Re-send with `{"reassign_to": <another id>}` → `204`. Then `php artisan tinker --execute="echo App\Models\TicketActivity::count();"` prints the number of tickets that moved.
8. **Query-string form:** same call as `DELETE /api/v1/categories/{id}?reassign_to=<id>` with no body → `204`.
9. **Formatting:** `backend/` — `./vendor/bin/pint --test` clean.
10. **Frontend typecheck:** `frontend/` — `npx vue-tsc -b` clean; `npm run build` succeeds.
11. **Frontend tests:** `frontend/` — `npm test`. Expect **6 → 9 files** and the new specs passing.
12. **Frontend lint and format:** `frontend/` — `npm run lint` and `npm run format:check` clean.
13. **Frontend runs:** `frontend/` — `npm run dev`, open `/admin/categories` as an admin, click Delete on a category with tickets. Expect the dialog with the count and the picker, and the row to disappear after confirming. Click Delete on an empty category — it disappears with no dialog.
14. **Docs:** `git diff docs/` shows the `DELETE` row, the new endpoint section and the conventions amendment in `api-contract.md`, and the `ticket_activities` entity plus table-note row in `erd.md`.

---

## Done Criteria

- [ ] `DELETE /api/v1/categories/{category}` on a category with tickets returns **`422`** with `ticket_count` equal to the number of tickets, including soft-deleted ones, and a message naming that count with correct singular/plural.
- [ ] The same `422` carries `reassign_to_options`: every other **active**, non-deleted category, in `ordered()` order, each rendered through `CategoryResource`; `[]` with an explanatory message when there are none.
- [ ] Re-sending the `DELETE` with a valid `reassign_to` — in the JSON body **or** the query string — moves every ticket, including soft-deleted ones, and then soft-deletes the category, returning `204`.
- [ ] `reassign_to` is rejected with `422` when it is the category being deleted, unknown, soft-deleted, deactivated, or not an integer. No ticket moves and no category is deleted on any of those paths.
- [ ] The move, the activity rows and the soft delete run inside one `DB::transaction`, opened by `lockForUpdate()` on the category row; a failure at any point leaves the tickets and the category exactly as they were, proven by `test_failure_rolls_everything_back`.
- [ ] Exactly one `ticket_activities` row per moved ticket, recording `event=category_changed`, `field=category_id`, both ids, the acting admin's `user_id`, and `meta` with `reason`, `from_name` and `to_name` — unicode readable in the raw column.
- [ ] `ActivityRecorder` is the only class that inserts into `ticket_activities`, and it throws `LogicException` when called outside a transaction.
- [ ] A category with no tickets still soft-deletes cleanly and returns `204`, exactly as TM-17 shipped it.
- [ ] `backend/routes/api.php` and `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` are unmodified, and `test_agent_refused_by_policy_admin_routes` still passes because `DestroyCategoryRequest::authorize()` answers `403` before validation.
- [ ] The admin categories screen surfaces the refusal: a dialog showing the count and the picker, and a working Cancel when there is nowhere to move the tickets. No unhandled promise rejection on the delete button.
- [ ] `composer test` is **120 tests, 119 passing**, the only failure being TM-14's `PasswordThrottleTest`; `npm test` is green across 9 files; `./vendor/bin/pint --test`, `npm run lint` and `npm run format:check` are clean.
- [ ] `docs/api-contract.md` documents the endpoint, the two extra response keys and the amended conventions bullet; `docs/erd.md` carries `ticket_activities` with TM-45 named as its long-term owner.
- [ ] The overview records the scope transfer (TM-18 creates `ticket_activities`, `TicketActivityEvent` and `ActivityRecorder`; **TM-45 extends rather than creates**) and the hard ordering constraint (TM-18 ships immediately after TM-21, in the same sprint).

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 16.**
