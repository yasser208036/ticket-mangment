# Story 18 — Create a ticket (Story: TM-22)

## Prerequisites

Every blocker this story had is now cleared. All three landed during the same working day, so **audit before you start** — the sources below were read on 2026-08-26 and the details this plan depends on are cited by line.

- **Story 17 (TM-21) is implemented.** `backend/app/Models/Ticket.php`, `Requester.php`, `TicketActivity.php`, `backend/app/Services/TicketReferenceGenerator.php` and the four migrations (`…084623_create_requesters_table` through `…084626_create_ticket_activities_table`) all exist. Confirm with `php artisan db:table tickets`.
- **Story 15 (TM-18) is implemented.** `backend/app/Services/ActivityRecorder.php`, `backend/app/Enums/TicketActivityEvent.php` and the guarded `CategoryController::destroy()` are in place.
- **Story 16 (TM-19) is implemented.** `PriorityController`, `StatusController`, `PriorityResource`, `StatusResource`, the `priorities.index` / `statuses.index` routes (`backend/routes/api.php:41–42`), `frontend/src/api/priorities.ts`, `statuses.ts` and `frontend/src/stores/masterData.ts` all exist. **This story's category and priority dropdowns read that store and issue no request of their own** — Story 16's second acceptance criterion.
- **Docker services running:** repo root — `docker compose up -d`; all three containers `healthy`. The suite runs against `tm-mysql-test` on **3307**.

### Measured baselines, 2026-08-26 — **two** backend tests are already red

`php artisan test` → **101 tests, 99 passing, 291 assertions**. `npm test` → **24 tests across 6 files**.

1. `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` — expects `422`, gets `429`. **TM-14's defect.** Out of scope.
2. `Tests\Feature\Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` — expects `LogicException`, nothing is thrown. **TM-21's defect**, and its cause matters to this story more than the failure does. That class uses `RefreshDatabase` (`TicketReferenceTest.php:14`), which holds an open transaction for the whole test, so `DB::transactionLevel()` is **1** and the guard at `TicketReferenceGenerator.php:12–14` can never fire. The fix is `DatabaseTruncation` on that one class; **it belongs to TM-21, not here.**

**Read consequence 2 twice before writing the controller.** The same fact means the `DB::transactionLevel() === 0` guards in *both* services are inert under the entire test suite: a `TicketController::store()` that forgot to open a transaction would still pass every test that calls it. **Atomicity here cannot be proven by the guard; it has to be proven by forcing a failure mid-operation and asserting nothing was written.** That does work under `RefreshDatabase`, because Laravel implements a nested `DB::transaction()` as a savepoint — see the Test Plan.

### Three pieces of Stories 16 and 21 shipped without their tests, and this story does not backfill them

The backend suite went from 93 to 101 across three stories that planned for roughly 60 new tests between them. There is no `TicketsTableSchemaTest`, no `TicketFullTextSearchTest`, no `TicketRelationsTest`, no `tests/Feature/MasterData/` directory, no `tests/Feature/Categories/` directory, and no `.spec.ts` for `stores/masterData.ts`, `api/priorities.ts`, `api/statuses.ts` or anything Story 15 touched. `docs/api-contract.md` still has no rows for `/priorities` or `/statuses` and still claims at **line 27** that "Category and ticket policies arrive with their models in TM-17 and TM-22".

Those gaps belong to TM-17, TM-18, TM-19 and TM-21. **This story does not close them, and must not copy them: every file it creates ships with a test.** The one exception is that sentence at line 27 — task 8 rewrites it, because this story is the half of it that comes true.

---

## Story Goal

`POST /api/v1/tickets`, and a form that can reach it.

1. One endpoint, validated entirely through a `FormRequest`, returning `201` and the created ticket resource with its requester, category, priority, status and creator.
2. A requester matched by email or created alongside the ticket — **in the same transaction**, so a failure anywhere leaves neither behind.
3. Status and priority default to their `is_default` rows when the client does not supply them.
4. `created_by` comes from the bearer token and cannot be reached from the request body, guarded three independent ways.
5. One `created` activity row per ticket, written inside the same transaction as the ticket.
6. A new-ticket form that validates before it submits, disables its button while in flight, and **cannot** be double-submitted — which needs two guards, not one.

**Not in scope:** the ticket list (**TM-23**), filters and sorting (**TM-24**), search (**TM-25**), the detail page (**TM-26**), editing (**TM-27**), soft delete (**TM-28**), assignment (**TM-31**), status transitions (**TM-38**), the ticket-created email (**TM-53**), the timeline that renders the activity row (**TM-46**), factories and the demo seeder (**TM-59**), and backfilling the missing tests listed above.

---

## Product rules (from story)

### `ActivityRecorder` has no single-ticket method, and its `$attributes` array has no defaults

`ActivityRecorder::recordMany(array $ticketIds, TicketActivityEvent $event, array $attributes)` (`ActivityRecorder.php:12`) reads `$attributes['user_id']`, `['field']`, `['old_value']`, `['new_value']` and `['meta']` **unconditionally** (**23–25**). Omit any one of the five and PHP 8 raises `Undefined array key`, and `json_encode(null)` would write the string `"null"` into `meta`.

Task 2 adds a `record()` wrapper that fills all five defaults. **Do not change `recordMany`'s signature** — `CategoryController::reassignTickets()` calls it with exactly those five keys, and Story 15 is already merged.

### `TicketActivityEvent` has exactly one case

`TicketActivityEvent.php:7` is `case CategoryChanged = 'category_changed';`. Task 1 adds `Created = 'created'`. **Add cases, never rename them** — the string is what is already in the `event` column.

### `Requester::firstOrCreate()` is safe inside this story's transaction, and that is not obvious

Two agents filing tickets for the same brand-new requester email at the same time both miss on the `SELECT` and both `INSERT`, and the loser hits `1062` on `requesters_email_unique`. On most databases that would poison the surrounding transaction.

Verified in this Laravel version: `Builder::firstOrCreate()` (**`Builder.php:710–717`**) delegates to `createOrFirst()` (**728–735**), which wraps the insert in `withSavepointIfNeeded()` (**2001–2006**) — and that helper opens a **nested transaction, i.e. a savepoint, whenever `transactionLevel() > 0`**. So the failed insert rolls back to the savepoint only, `UniqueConstraintViolationException` is caught, and the row is re-read with `useWritePdo()`. The outer transaction survives and the ticket is created against the requester the other request just made.

`firstOrCreate` is therefore correct here. **A hand-rolled `first()` then `create()` is not** — it has no savepoint and would abort the whole create.

### An existing requester is matched, never rewritten

Criterion 2 says "matched by email". So the `name`, `phone` and `company` in the payload are used **only when the requester does not already exist** — they are the `$values` argument of `firstOrCreate`, not an update. An agent who types a different name for a known email does not silently rewrite that contact record.

The consequence is real and should be recorded rather than hidden: **no story in the backlog edits a requester**, so a wrong name stays wrong until someone opens tinker. The created ticket's response includes the matched requester, so the agent at least sees which contact was used.

### Zero defaults is legal in the schema, so "the default status" can be absent

TM-16 enforces "at most one default" with a unique index over a generated column that is `1` for the flagged row and `NULL` for every other. **Zero defaults is deliberately allowed** — the seeders rely on it to clear one default before setting another.

So `Status::where('is_default', true)` can legitimately return nothing, and `status_id` is `NOT NULL`. That is a **deployment** error, not a client error: it means `php artisan db:seed` never ran. Task 6 throws a `LogicException` naming the fix rather than a `422` that blames the caller for a server misconfiguration. In production `app.debug` is false, so the message does not reach the client.

### `created_by` is guarded three ways, and `assigned_to` is prohibited outright

Criterion 4 says `created_by` "can never be set from the request body". Three independent guards, because one is a single edit away from being undone:

1. It is absent from `Ticket`'s `#[Fillable]` (`Ticket.php:10`) — already true, from TM-21.
2. `StoreTicketRequest` marks it `prohibited`, so sending it is a `422` rather than a silent drop.
3. The controller assigns it as a property from `$request->user()`.

`reference` gets the same three. **`assigned_to` is `prohibited` too**: it *is* fillable (TM-21 put it there for TM-31), and assignment has its own endpoint with its own validation and its own activity row (`E5-S1`). Letting it through here would create an assigned ticket with no `assigned` activity row.

`status_id` and `priority_id` **are** accepted, because criterion 3's "when not supplied" says they may be supplied.

### `tickets.store` needs a new access level in the route manifest

`RouteAuthorizationTest::ACCESS` (**line 16**) has five levels: `public`, `self`, `admin`, `staff`, `admin-policy`. None fits.

`staff` is wrong: `test_agent_reaches_staff_routes` (**52–58**) asserts `assertOk()`, and `POST /api/v1/tickets` with no body is a `422`. Classifying it `staff` and then widening that test to loop `routesFor('staff')` — which Story 16's plan asked for and which was not done — would turn it red.

Task 7 adds **`staff-write`**: any active staff member may call it, so the assertion is "an agent is **not** `403`", mirroring `test_admin_not_refused_by_admin_routes` rather than the `assertOk()` shape.

### `masterData` shipped without `activeCategories` and without a default-priority lookup

`frontend/src/stores/masterData.ts` is 16 lines and exposes `categories`, `priorities`, `statuses`, `error`, `refresh`, `ensureLoaded`, `clear`, `categoryBadge`, `priorityById`, `statusById`. Story 16's plan specified `activeCategories` and `defaultPriority`; **neither was implemented.**

This story cannot build its dropdowns without them — TM-17's fifth criterion is that deactivated categories disappear from the new-ticket dropdown while still rendering on existing tickets, and a priority select with nothing preselected is worse than one that starts on the default. Task 11 adds both computeds **to `masterData`, where Story 16 already put them in its plan**, not to the view. Nothing else in that store changes.

### `description` is a `TEXT` column, and `TEXT` is bytes while `max:` is characters

Measured on `mysql:8.4.11`, utf8mb4, `STRICT_ALL_TABLES`:

| Column | Value | Result |
|---|---|---|
| `varchar(255)` | 255 Arabic characters (510 bytes) | **accepted** — `VARCHAR(n)` is *n characters* |
| `varchar(255)` | 256 Arabic characters | `ERROR 1406 Data too long` |
| `text` | 32,767 Arabic characters = 65,534 bytes | **accepted** |
| `text` | 32,768 Arabic characters = 65,536 bytes | `ERROR 1406 Data too long` |
| `text` | 16,384 emoji = 65,536 bytes | `ERROR 1406 Data too long` |

Laravel's `max:` rule on a string counts **characters** (`Str::length`, i.e. `mb_strlen`). So `'description' => ['max:65000']` would accept 65,000 emoji — 260,000 bytes — and MySQL would answer `ERROR 1406`, which reaches the SPA as a **500**, not the `422` criterion 1 promises.

The safe character cap is **16,000**: at the worst case of 4 bytes per character that is 64,000 bytes, comfortably inside 65,535. `subject` keeps `max:255`, which is exactly right because `VARCHAR` counts characters.

### Double-submit needs two guards

Criterion 6 says the form "cannot double-submit". `:disabled="submitting"` on the button — the pattern at `LoginView.vue:53` — is necessary and **not sufficient**: pressing Enter in a text input submits the form without going through the button at all. So `submit()` also opens with `if (submitting.value) return`. Both, or the criterion is unmet.

### There is nowhere to redirect to yet

`TM-26` owns the ticket detail page and `TM-23` the list; neither exists. On success the form shows the created reference and offers **Create another**, which resets the fields. **TM-26 changes this to a redirect to the new ticket** — recorded in the overview so it is not forgotten.

---

## Context — Read These Files First

1. `backend/app/Services/ActivityRecorder.php` — whole file, 31 lines. **Line 12** is the signature task 2 wraps; **17–19** the transaction guard; **23–25** the five array keys that have no defaults.
2. `backend/app/Services/TicketReferenceGenerator.php` — whole file, 29 lines. `next()` at **10**, the guard at **12–14**. Call it inside the transaction and assign the result; it does not touch `tickets`.
3. `backend/app/Models/Ticket.php` — 54 lines. `#[Fillable]` at **10** (note `reference` and `created_by` are absent, and `assigned_to` is present), `casts()` at **15–18**, the seven `belongsTo` relations at **20–53**. Task 5's eager-load list uses those exact method names.
4. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — `destroy()` and `reassignTickets()`. **The only existing `DB::transaction` + `ActivityRecorder` call site**, and the shape task 6 follows. Note it calls `recordMany` with all five attribute keys spelled out.
5. `backend/app/Http/Requests/Api/V1/Admin/StoreUserRequest.php` — whole file, 23 lines. The `rules()` idiom, including the `/** @return array<string, list<mixed>|string> */` docblock.
6. `backend/app/Http/Requests/Api/V1/DestroyCategoryRequest.php` — the `authorize(): bool { return Gate::allows(...); }` line. **A `FormRequest` authorizes before it validates**, so without it an agent gets `422` where the manifest expects `403`. This story's request needs the same.
7. `backend/app/Http/Resources/V1/PriorityResource.php` and `StatusResource.php` — the two resources `TicketResource` nests. `StatusResource` unwraps the backed enum with `$this->bucket->value`.
8. `backend/app/Http/Resources/V1/UserResource.php` — **read it, then do not use it for `creator` or `assignee`.** It exposes `email`, and `GET /api/v1/admin/users` is admin-only; nesting it here would hand every agent the staff directory through a ticket. Task 3 nests `['id', 'name']` instead.
9. `backend/routes/api.php` — the `['auth:sanctum', 'active']` group opens at **31**; `priorities.index` and `statuses.index` are **41–42**; the `admin` sub-group opens at **43**. The new route goes between **42** and **43**. **Never write `/api/v1` into a path.**
10. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `ACCESS` at **16**, `test_agent_reaches_staff_routes` at **52–58** (three hard-coded URLs — **do not** convert it to a loop here, that is Story 16's outstanding task), `test_unauthenticated_non_public_refused` at **83–90**, `routesFor()` at **121–124**.
11. `frontend/src/stores/masterData.ts` — whole file, 16 lines. Task 11 adds two computeds and changes nothing else. Note `clear()`, not `reset()`.
12. `frontend/src/views/LoginView.vue` — whole file, 88 lines. **The form idiom**: `submitting` ref at **14**, `submit()` at **16–27** with its `try/catch/finally`, `:disabled="submitting"` at **53**, `data-testid` on every control, scoped styles at **60–88**. Task 12 copies all of it.
13. `frontend/src/stores/users.ts` — **55–58**, `create()`. The store-action shape: call the API module, then refresh.
14. `frontend/src/api/users.ts` — **37–45**, `createUser`. The `{ data: T }` unwrap task 9 copies.
15. `frontend/src/router/index.ts` — the `routes` array at **22–49** and the catch-all at **48**. The new route goes before the catch-all.
16. `frontend/src/App.vue` — the header at **18–32**. One `RouterLink` is added, with no `v-if="auth.isAdmin"`: agents create tickets.

---

## Backend Tasks

### 1 — The event

**File: `backend/app/Enums/TicketActivityEvent.php`**

Add one case above `CategoryChanged` (**line 7**), keeping the cases alphabetical by value is not the convention here — chronological by when a ticket meets them reads better:

```php
case Created = 'created';
case CategoryChanged = 'category_changed';
```

Change nothing else. `values()` (**9–12**) picks the new case up automatically.

### 2 — A single-ticket recorder

**File: `backend/app/Services/ActivityRecorder.php`**

Add above `recordMany()` (**12**). **Do not touch `recordMany` itself** — `CategoryController::reassignTickets()` already calls it.

```php
/**
 * One ticket, one row. recordMany() reads all five attribute keys without a
 * default (23–25), so every caller would otherwise have to spell out four
 * nulls it does not care about.
 *
 * @param  array<string, mixed>  $attributes
 */
public function record(int $ticketId, TicketActivityEvent $event, array $attributes = []): void
{
    $this->recordMany([$ticketId], $event, $attributes + [
        'user_id' => null,
        'field' => null,
        'old_value' => null,
        'new_value' => null,
        'meta' => [],
    ]);
}
```

`+` on arrays keeps the left-hand operand's keys, so anything the caller supplies wins and the rest fall back. **Not `array_merge`** — same result here, but `+` makes "defaults only fill gaps" the visible intent.

### 3 — The resources

**Create file: `backend/app/Http/Resources/V1/RequesterResource.php`**

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequesterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
        ];
    }
}
```

**Create file: `backend/app/Http/Resources/V1/TicketResource.php`**

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'description' => $this->description,
            'requester' => RequesterResource::make($this->whenLoaded('requester')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'priority' => PriorityResource::make($this->whenLoaded('priority')),
            'status' => StatusResource::make($this->whenLoaded('status')),
            // Deliberately not UserResource: that exposes email, and the staff
            // directory is admin-only. An agent may see who is on a ticket,
            // not how to reach them.
            'assignee' => $this->whenLoaded('assignee', fn () => ['id' => $this->assignee->id, 'name' => $this->assignee->name]),
            'creator' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'escalation_level' => $this->escalation_level,
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'first_responded_at' => $this->first_responded_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

`whenLoaded` everywhere so **TM-23** can vary the eager-load list per endpoint without a second resource class. The lifecycle and escalation fields are all null on create; they are here so **TM-26** inherits the shape rather than changing it.

`deleted_at` and `escalation_reason` are **not** exposed. A soft-deleted ticket never reaches a response, and the reason belongs to the escalation activity row (**TM-41**).

### 4 — The policy

**Create file: `backend/app/Policies/TicketPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\User;

class TicketPolicy
{
    /**
     * Every active staff member may file a ticket. Reaching this method means
     * the route group's auth:sanctum and active middleware already passed, so
     * there is nothing left to check. viewAny/view/update/delete arrive with
     * their endpoints in TM-23, TM-26, TM-27 and TM-28.
     */
    public function create(User $user): bool
    {
        return true;
    }
}
```

**File: `backend/app/Models/Ticket.php`** — add the attribute above the class, matching `Category.php:14`:

```php
use App\Policies\TicketPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

#[Fillable([...])]          // unchanged, from TM-21
#[UsePolicy(TicketPolicy::class)]
class Ticket extends Model
```

Change nothing else in that file — in particular leave `#[Fillable]` (**10**) exactly as TM-21 wrote it.

### 5 — The request

**Create file: `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    /**
     * A FormRequest validates before the controller body runs, so without
     * this an unauthorized caller would get 422 where the route manifest
     * expects 403 — the same reason StoreCategoryRequest carries one.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', Ticket::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('requester.email'))) {
            $this->merge(['requester' => [...(array) $this->input('requester'), 'email' => mb_strtolower(trim($email))]]);
        }
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'requester' => ['required', 'array'],
            'requester.name' => ['required', 'string', 'max:255'],
            'requester.email' => ['required', 'string', 'email', 'max:255'],
            'requester.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'requester.company' => ['sometimes', 'nullable', 'string', 'max:255'],

            'subject' => ['required', 'string', 'max:255'],
            // 16000 CHARACTERS, not bytes. `description` is a TEXT column, so
            // MySQL's limit is 65535 BYTES, while Laravel's max: counts
            // characters. 16000 * 4 bytes/char = 64000, inside the limit for
            // any input including emoji. Measured — see the plan.
            'description' => ['required', 'string', 'max:16000'],

            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'priority_id' => ['sometimes', 'integer', Rule::exists('priorities', 'id')],
            'status_id' => ['sometimes', 'integer', Rule::exists('statuses', 'id')],

            // Criterion 4, guard two of three. `prohibited` rather than silent
            // omission, so a client that tries gets told.
            'reference' => ['prohibited'],
            'created_by' => ['prohibited'],
            // Assignment has its own endpoint, its own validation and its own
            // activity row (TM-31). Letting it through here would produce an
            // assigned ticket with no `assigned` activity.
            'assigned_to' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'That category does not exist or is no longer active.',
            'assigned_to.prohibited' => 'Assign the ticket after creating it.',
        ];
    }
}
```

`whereNull('deleted_at')` is not optional: `Rule::exists` runs on the raw query builder, so the `SoftDeletes` global scope does **not** apply and a trashed category would otherwise pass. `where('is_active', true)` matches TM-17's fifth criterion — a deactivated category is not offered, so it must not be accepted.

Lower-casing the email in `prepareForValidation` is what makes criterion 2's matching work: `requesters_email_unique` is a `utf8mb4_unicode_ci` index and therefore already case-insensitive, but `firstOrCreate`'s `where('email', …)` would still create a second row for `Ann@x.test` if the stored value were `ann@x.test` on a case-sensitive collation later. Normalising at the edge makes the match independent of the collation.

### 6 — The controller

**Create file: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTicketRequest;
use App\Http\Resources\V1\TicketResource;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Services\ActivityRecorder;
use App\Services\TicketReferenceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

class TicketController extends Controller
{
    /**
     * The requester lookup-or-create, the reference allocation, the ticket
     * insert and the activity row share one transaction. All four or none:
     * a burnt reference or a requester with no ticket are both worse than a
     * failed request.
     */
    public function store(
        StoreTicketRequest $request,
        TicketReferenceGenerator $references,
        ActivityRecorder $recorder,
    ): JsonResponse {
        $this->authorize('create', Ticket::class);
        $actorId = $request->user()->getKey();

        $ticket = DB::transaction(function () use ($request, $references, $recorder, $actorId): Ticket {
            $requesterInput = $request->validated('requester');

            // firstOrCreate, not first()-then-create(): inside a transaction
            // Laravel wraps the insert in a savepoint (Builder.php:2001–2006),
            // so a concurrent request winning the same new email rolls back
            // only the savepoint and the row is re-read. The values array is
            // used ONLY on create — an existing requester is matched, never
            // rewritten, per criterion 2.
            $requester = Requester::firstOrCreate(
                ['email' => $requesterInput['email']],
                Arr::only($requesterInput, ['name', 'phone', 'company']),
            );

            $ticket = new Ticket($request->safe()->only(['subject', 'description', 'category_id']));
            $ticket->requester_id = $requester->getKey();
            $ticket->priority_id = $request->has('priority_id')
                ? $request->integer('priority_id')
                : $this->defaultKey(Priority::query(), 'priority');
            $ticket->status_id = $request->has('status_id')
                ? $request->integer('status_id')
                : $this->defaultKey(Status::query(), 'status');
            // Criterion 4, guard three of three: from the token, never the body.
            $ticket->created_by = $actorId;
            $ticket->reference = $references->next();
            $ticket->save();

            $recorder->record($ticket->getKey(), TicketActivityEvent::Created, [
                'user_id' => $actorId,
                'meta' => ['reference' => $ticket->reference],
            ]);

            return $ticket;
        });

        return TicketResource::make(
            $ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator'])
        )->response()->setStatusCode(201);
    }

    /**
     * TM-16's schema allows zero defaults on purpose — the unique index is
     * over a generated column, so clearing one before setting another is
     * legal. A missing default therefore means the seeders never ran, which
     * is a deployment fault and not the caller's, so it is not a 422.
     *
     * @param  Builder<Priority|Status>  $query
     */
    private function defaultKey(Builder $query, string $label): int
    {
        $key = $query->where('is_default', true)->value('id');

        if ($key === null) {
            throw new LogicException("No default {$label} is configured. Run `php artisan db:seed` to restore master data.");
        }

        return (int) $key;
    }
}
```

Five details that are not negotiable:

- **`$references->next()` is called inside the transaction.** It throws otherwise (`TicketReferenceGenerator.php:12–14`), and TM-21 measured that the sequence number is only returned on rollback while the caller's transaction holds the row lock.
- **`$recorder->record()` is inside it too**, for the same reason and because criterion 5 says every ticket gets a row — an activity written after a commit could be lost.
- **`$request->has('priority_id')`, not `filled()`.** `filled()` treats `0` as absent; `0` should reach the validator and fail `exists`, not be silently replaced by the default.
- **`$request->safe()->only([...])`** for the three mass-assigned fields, so nothing else in the body can reach `fill()` even if `#[Fillable]` grows later.
- **`load()` after the transaction, not `with()` before.** The ticket is built, not queried; eager loading happens once on the way out, and TM-23 will pick a different list for its index.

### 7 — The route and the manifest

**File: `backend/routes/api.php`**

Add the import after `HealthController` and before `PriorityController`:

```php
use App\Http\Controllers\Api\V1\TicketController;
```

Add the route inside the `['auth:sanctum', 'active']` group, after `statuses.index` (**42**) and above the `admin` sub-group (**43**):

```php
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
```

`[TicketController::class, 'store']`, not an invokable — **TM-23** adds `index` and **TM-26** `show` to the same class.

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add one entry to `ACCESS` (**16**) at a **new** level:

```php
// Any active staff member may POST this. Not `staff`, because
// test_agent_reaches_staff_routes asserts 200 and an empty POST is a 422.
'tickets.store' => 'staff-write',
```

Add one test, and extend one:

- `test_agent_is_not_refused_by_staff_write_routes` — an agent token against every `staff-write` route asserts the status is **not** `403`, mirroring `test_admin_not_refused_by_admin_routes`.
- Extend `test_unauthenticated_non_public_refused` (**83–90**) with `$this->postJson('/api/v1/tickets')->assertUnauthorized();`.

**Do not** convert `test_agent_reaches_staff_routes` (**52–58**) to a `routesFor('staff')` loop. Story 16's plan asked for that and it was not done; it is still TM-19's, and doing it here would mix two stories' diffs.

### 8 — The contract

**File: `docs/api-contract.md`**

Add one row to the endpoints table, after the category rows:

| `POST` | `/api/v1/tickets` | File a ticket. Matches or creates the requester, defaults status and priority, writes a `created` activity row. | bearer (TicketPolicy) | TM-22 |

Replace the second half of the Authorization paragraph at **27–28** — "Category and ticket policies arrive with their models in TM-17 and TM-22" is now false in both halves:

> `CategoryPolicy` defines `viewAny`, `view`, `create`, `update` and `delete`: any active staff member may read categories, only admins may write. `TicketPolicy` defines `create`, which every active staff member passes; its read and write methods arrive with their endpoints in TM-23, TM-26, TM-27 and TM-28. `/api/v1/priorities` and `/api/v1/statuses` have no policy — they are read-only to every active staff member.

Add a `### POST /api/v1/tickets` section documenting: the nested `requester` object; that `subject` is capped at 255 characters and `description` at 16,000 **characters** because the column's limit is 65,535 **bytes**; that `category_id` must be an existing, active, non-deleted category; that `priority_id` and `status_id` are optional and fall back to the `is_default` row; that `reference`, `created_by` and `assigned_to` are `prohibited` and return `422`; the `201` response shape; and that an existing requester is **matched by email and never updated**.

**Do not touch `docs/erd.md`** — this story adds no column and no table.

---

## Frontend Tasks

### 9 — The API module

**Create file: `frontend/src/api/tickets.ts`** — follow `api/users.ts:37–45` for the `{ data: T }` unwrap.

```ts
import client from './client'
import type { Category } from './categories'
import type { Priority } from './priorities'
import type { Status } from './statuses'

export interface Requester {
  id: number
  name: string
  email: string
  phone: string | null
  company: string | null
}

export interface TicketStaff {
  id: number
  name: string
}

export interface Ticket {
  id: number
  reference: string
  subject: string
  description: string
  requester: Requester
  category: Category
  priority: Priority
  status: Status
  assignee: TicketStaff | null
  creator: TicketStaff | null
  escalation_level: number
  escalated_at: string | null
  first_responded_at: string | null
  resolved_at: string | null
  closed_at: string | null
  created_at: string
  updated_at: string
}

export interface CreateTicketPayload {
  requester: { name: string; email: string; phone?: string | null; company?: string | null }
  subject: string
  description: string
  category_id: number
  priority_id?: number
}

export async function createTicket(payload: CreateTicketPayload): Promise<Ticket> {
  const { data } = await client.post<{ data: Ticket }>('/tickets', payload)
  return data.data
}
```

`status_id` is deliberately absent from `CreateTicketPayload`: the form does not offer a status, so a new ticket always opens on the default. **TM-38** owns status changes.

### 10 — The store

**Create file: `frontend/src/stores/tickets.ts`** — small on purpose; **TM-23** grows it with the list, filters and pagination.

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { createTicket } from '../api/tickets'
import type { CreateTicketPayload, Ticket } from '../api/tickets'

export const useTicketsStore = defineStore('tickets', () => {
  const creating = ref(false)

  // Rethrows: the view owns the 422 field errors and the message, exactly as
  // CategoryFormDialog does. `creating` is here rather than in the view so
  // TM-23's list can disable actions while a create is in flight.
  async function create(payload: CreateTicketPayload): Promise<Ticket> {
    creating.value = true
    try {
      return await createTicket(payload)
    } finally {
      creating.value = false
    }
  }

  return { creating, create }
})
```

### 11 — Two computeds `masterData` was supposed to have

**File: `frontend/src/stores/masterData.ts`**

Story 16's plan specified both and shipped neither. Add them and change nothing else — not `ensureLoaded`, not `clear`, not the three lookups.

```ts
const activeCategories = computed(() => categories.value.filter((entry) => entry.is_active))
const defaultPriority = computed(() => priorities.value.find((entry) => entry.is_default) ?? null)
```

Add both to the returned object, and add `computed` to the `vue` import on **line 1**.

`activeCategories` is what makes TM-17's fifth criterion true: a deactivated category disappears from this form's dropdown while `categoryBadge()` still renders it on tickets that already carry it. **The form must read `activeCategories`, never `categories`.**

### 12 — The form

**Create file: `frontend/src/views/NewTicketView.vue`** — copy `LoginView.vue`'s structure: `<script setup lang="ts">`, refs, one `submit()`, `data-testid` on every control, scoped styles.

Behaviour, all of it criterion 6:

- `onMounted(() => void masterData.ensureLoaded())`, and render a loading line while `masterData.categories.length === 0`. The guard already prefetches (`guards.ts:36`); this covers a direct page load that races it.
- Fields: `requester.name`, `requester.email`, `requester.phone`, `requester.company`, `subject`, `description`, `category_id` (a `<select>` over **`masterData.activeCategories`**), `priority_id` (a `<select>` over `masterData.priorities`, preselected from `masterData.defaultPriority`).
- **Client-side validation before the request**, into a local `errors` record: `requester.name`, `requester.email`, `subject`, `description` and `category_id` all required; email matched against a simple `/.+@.+\..+/`; `subject` at most 255 characters and `description` at most 16,000, mirroring the server's caps so the common case never round-trips.
- **`submit()` opens with `if (submitting.value) return`** — the Enter-key path does not go through the button.
- The button is `:disabled="submitting"` and reads `Filing…` while in flight.
- Server `422`s land in the same `errors` record via `validationErrors(e)` from `api/errors.ts`; anything else becomes a message via `errorMessage(e)`. Nested keys arrive as `requester.email`, so index the record by the dotted key rather than flattening it.
- On success, hide the form and show `Ticket <reference> created.` with a **Create another** button that clears every field and shows the form again.

Required test ids: `new-ticket-form`, `new-ticket-requester-name`, `new-ticket-requester-email`, `new-ticket-requester-phone`, `new-ticket-requester-company`, `new-ticket-subject`, `new-ticket-description`, `new-ticket-category`, `new-ticket-priority`, `new-ticket-submit`, `new-ticket-error`, `new-ticket-error-<field>`, `new-ticket-created`, `new-ticket-another`.

**File: `frontend/src/router/index.ts`** — import the view alongside the others (**4–9**) and add the route before the catch-all (**48**):

```ts
      { path: '/tickets/new', name: 'new-ticket', component: NewTicketView },
```

**No `meta: { role: 'admin' }`.** Agents file tickets; that is the whole point of the story.

**File: `frontend/src/App.vue`** — add one link in the header (**18–32**), before the Categories link and **without** a `v-if`:

```html
    <RouterLink :to="{ name: 'new-ticket' }" data-testid="nav-new-ticket"
      >New ticket</RouterLink
    >
```

---

## Edge Cases & Failure Modes

- **The requester's email already exists.** Matched, and the ticket attaches to the existing contact. The `name`, `phone` and `company` in the payload are **ignored** — they are `firstOrCreate`'s `$values`, used only on insert (task 6). The response returns the matched requester so the agent can see whose record was used.

- **Two agents file for the same brand-new email simultaneously.** One inserts, the other hits `1062`, and `createOrFirst` rolls back to its savepoint and re-reads the row with the write PDO (`Builder.php:728–735`, `2001–2006`). Both tickets are created against the same requester. **The outer transaction survives** — this is the only reason `firstOrCreate` is safe here and a hand-rolled `first()`-then-`create()` is not.

- **The reference allocation succeeds and the ticket insert then fails.** Everything rolls back and the number is returned, not burnt — TM-21 measured `next_number` going back to its previous value. This holds only because `next()` runs inside the caller's transaction.

- **No `is_default` status or priority exists.** `LogicException` naming `php artisan db:seed` (task 6). TM-16's schema permits zero defaults deliberately, so this is reachable on a database that was migrated but never seeded — and it is a deployment fault, not a `422`.

- **`category_id` names a deactivated or soft-deleted category.** `422` on `category_id` with "That category does not exist or is no longer active." `Rule::exists` bypasses the `SoftDeletes` global scope, which is why `whereNull('deleted_at')` is written out (task 5).

- **The body carries `created_by`, `reference` or `assigned_to`.** `422` from `prohibited`, before anything is written. Even if that rule were deleted, `reference` and `created_by` are absent from `Ticket`'s `#[Fillable]` (`Ticket.php:10`) and the controller uses `safe()->only([...])` — three guards for criterion 4, two for `assigned_to`.

- **A 20,000-character Arabic description.** `422` on `description`, from `max:16000`. Without that cap MySQL answers `ERROR 1406 Data too long` and the SPA sees a **500**: measured, `TEXT` holds 65,535 **bytes** while `max:` counts **characters**, and 32,768 Arabic characters (65,536 bytes) is already over.

- **A 255-character Arabic subject.** Accepted. `VARCHAR(255)` counts characters, not bytes — measured at 255 characters / 510 bytes.

- **An unauthenticated caller.** `401` from `auth:sanctum`, asserted by the line added to `test_unauthenticated_non_public_refused`.

- **An agent, and an inactive account.** Agent: `200`-family, never `403` — asserted by the new `staff-write` test. Inactive: `401` from the `active` middleware, which also revokes the token.

- **The form is submitted twice by pressing Enter twice quickly.** One request. `submit()` returns immediately on the second call because `submitting` is still true (task 12) — the `:disabled` attribute alone does not cover the Enter path, which is why both guards exist.

- **The form is opened by direct URL before the guard's prefetch resolves.** `onMounted` awaits `ensureLoaded()` and the selects render empty until it settles; the loading line covers the gap. `ensureLoaded()` is single-flight, so this does not double-fetch.

- **The master-data request failed and the category list is empty.** `masterData.error` is set; the form shows it and the submit button stays disabled, because `category_id` is required and there is nothing to pick.

- **A category deactivated after the form was opened.** The client offers it (stale list) and the server answers `422` on `category_id`. Correct: the server is the authority, and the message tells the agent to reload.

- **The `created` activity row.** Exactly one per ticket, `event = 'created'`, `user_id` = the filing agent, `field`/`old_value`/`new_value` all null, `meta.reference` set. `record()`'s defaults (task 2) are what keep the three nulls from being `Undefined array key` notices.

---

## Test Plan

Backend from `backend/`, frontend from `frontend/`. Backend feature tests use `RefreshDatabase` against `tm-mysql-test` on **3307**. Fixtures use `Model::create([...])` and the TM-16 seeders; there is still no `TicketFactory` (**TM-59**).

**Read this before writing the atomicity tests.** `RefreshDatabase` holds an open transaction, so `DB::transactionLevel()` is never 0 inside a test and the guards in both services cannot fire — that is exactly why `TicketReferenceTest::test_calling_outside_a_transaction_throws` is red today. A `store()` that forgot `DB::transaction()` would pass every naive test. Atomicity is therefore tested by **forcing a failure part-way through and asserting nothing survived**, which works correctly under `RefreshDatabase` because Laravel implements the nested transaction as a savepoint.

### Backend

1. **Create `backend/tests/Feature/Tickets/CreateTicketTest.php`** — the core, 12 tests.
   - `test_agent_creates_a_ticket` — `201`; `assertJsonPath('data.reference', …)` matches `/^TKT-\d{4}-\d{6}$/`; the response nests `requester`, `category`, `priority`, `status` and `creator`.
   - `test_creator_is_the_authenticated_user` — `data.creator.id` is the caller and `tickets.created_by` matches, with no `created_by` in the body.
   - `test_created_by_in_the_body_is_rejected` — `422` under `errors.created_by`; no ticket row is written.
   - `test_reference_and_assigned_to_in_the_body_are_rejected` — `422` on each.
   - `test_status_and_priority_default_when_omitted` — the ticket lands on the `is_default` status (`new`) and priority (`medium`), per `StatusSeeder` and `PrioritySeeder`.
   - `test_supplied_status_and_priority_are_honoured`.
   - `test_missing_required_fields_are_rejected` — `422` naming `requester.name`, `requester.email`, `subject`, `description`, `category_id`.
   - `test_description_over_the_character_cap_is_rejected` — 16,001 characters gives `422` on `description`, **not** a 500.
   - `test_long_arabic_subject_is_accepted` — 255 Arabic characters gives `201`, guarding the character-vs-byte distinction from the other side.
   - `test_inactive_category_is_rejected` and `test_soft_deleted_category_is_rejected` — both `422` on `category_id`.
   - `test_unknown_priority_is_rejected` — `422`.
2. **Create `backend/tests/Feature/Tickets/CreateTicketRequesterTest.php`** — criterion 2, 5 tests.
   - `test_new_requester_is_created` — one `requesters` row with the posted name, phone and company.
   - `test_existing_requester_is_matched_by_email` — a second ticket for the same email creates **no** second requester and both tickets share `requester_id`.
   - `test_existing_requester_is_not_rewritten` — posting a different name for a known email leaves the stored name unchanged and returns the stored one.
   - `test_email_is_matched_case_insensitively` — `Ann@X.test` matches an existing `ann@x.test`.
   - `test_requester_and_ticket_share_a_transaction` — bind a `TicketReferenceGenerator` double that throws, POST, and assert **no** `requesters` row and **no** `tickets` row exist. This is the test that actually proves criterion 2's "same transaction"; without it the transaction could be missing entirely and everything else would still pass.
3. **Create `backend/tests/Feature/Tickets/CreateTicketActivityTest.php`** — criterion 5, 4 tests.
   - `test_one_created_activity_row_per_ticket` — exactly one row, `event` is `created`.
   - `test_activity_records_the_actor_and_reference` — `user_id` is the caller; `meta.reference` equals the ticket's reference; `field`, `old_value` and `new_value` are all null.
   - `test_no_activity_row_when_validation_fails` — a `422` writes nothing.
   - `test_activity_is_rolled_back_with_the_ticket` — the throwing-generator double again: zero `ticket_activities` rows.
4. **Create `backend/tests/Unit/Services/ActivityRecorderRecordTest.php`** — 2 tests for task 2's wrapper, inside `DB::transaction`.
   - `test_record_fills_missing_attributes` — passing only `user_id` writes a row with three nulls and `meta` of `[]`, and raises no PHP notice.
   - `test_caller_attributes_win_over_defaults`.
5. **Create `backend/tests/Feature/Policies/TicketPolicyTest.php`** — 2 tests: an agent and an admin both pass `create`.
6. **Edit `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`** per task 7 — one new test method plus one line in an existing one. **Do not** rewrite `test_agent_reaches_staff_routes`.
7. **Create `backend/tests/Unit/Enums/TicketActivityEventTest.php`** — 1 test: `Created->value === 'created'` and `values()` contains both cases. (TM-18 shipped this enum without a test; this story adds one covering the case it introduces.)
8. **Do not touch** `PasswordThrottleTest` or `TicketReferenceTest`. Both are red on arrival and both belong to other stories.

### Frontend

9. **Create `frontend/src/api/tickets.spec.ts`** — 2 tests using the `client.defaults.adapter` capture from `api/auth.spec.ts:9–30`: `createTicket` POSTs to `/tickets` with the nested `requester` object intact, and unwraps `{ data }`.
10. **Create `frontend/src/stores/masterData.spec.ts`** — 3 tests, restricted to task 11: `activeCategories` excludes inactive rows while `categories` keeps them; `defaultPriority` returns the `is_default` row; `defaultPriority` is `null` when none is flagged. **The rest of this store is TM-19's untested surface and stays that way here.**
11. **Create `frontend/src/views/NewTicketView.spec.ts`** — 7 tests, mounting with `createPinia()` and `vi.mock('../api/tickets')`, following `views/HealthView.spec.ts`.
   - `renders only active categories in the dropdown`.
   - `preselects the default priority`.
   - `blocks submit and shows field errors when required fields are empty` — no request is made.
   - `rejects a description over 16000 characters client side` — no request is made.
   - `disables the button while in flight`.
   - `cannot be double-submitted` — call `submit()` twice without awaiting between; `createTicket` is called **once**. Assert this by invoking the handler directly rather than clicking, so the disabled attribute is not what makes it pass.
   - `shows the reference on success and clears the form on Create another`.
12. **Create `frontend/src/stores/tickets.spec.ts`** — 2 tests: `creating` is true during the call and false after; a rejected create rethrows and still resets `creating`.

Expected totals: **backend 101 → 127 tests** (26 new; the two red ones stay red), **frontend 24 → roughly 38 tests across 10 files**.

---

## Verification Steps

1. **Services up:** repo root — `docker compose up -d && docker compose ps`; all three `healthy`.
2. **Seeded data present:** `backend/` — `php artisan migrate:fresh --seed`, then `php artisan tinker --execute="echo App\Models\Category::count(), ' ', App\Models\Priority::where('is_default',true)->count(), ' ', App\Models\Status::where('is_default',true)->count();"` prints `6 1 1`.
3. **Route registered:** `backend/` — `php artisan route:list --path=api/v1/tickets` shows one `POST` named `tickets.store` carrying `auth:sanctum` and `active` and **not** `admin`.
4. **Backend tests:** `backend/` — `composer test`. Expect **127 tests, 125 passing**, the two failures being `PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` and `TicketReferenceTest::test_calling_outside_a_transaction_throws`. **Any third failure is this story's.**
5. **The atomicity test really bites:** `backend/` — temporarily delete the `DB::transaction(...)` wrapper in `store()` (call the closure body inline), run `php artisan test --filter=CreateTicketRequesterTest`, and confirm `test_requester_and_ticket_share_a_transaction` **fails**. Revert. Without this check the transaction could be absent and the suite would still be green.
6. **Create one by hand:** `backend/` — `php artisan serve`; with an **agent** bearer token `POST /api/v1/tickets` with a nested requester, a subject, a description and a `category_id`. Expect `201`, a `TKT-2026-000001`-shaped reference, and `status`/`priority` populated from the defaults. Then `php artisan tinker --execute="echo App\Models\TicketActivity::where('event','created')->count();"` prints `1`.
7. **Requester matching:** POST a second ticket with the same email and a different name. Expect `201`, `App\Models\Requester::count()` still `1`, and the response carrying the **original** name.
8. **Prohibited fields:** POST with `"created_by": 1` → `422` under `errors.created_by`; the same for `reference` and `assigned_to`.
9. **Backend formatting:** `backend/` — `./vendor/bin/pint --test` clean.
10. **Frontend typecheck and build:** `frontend/` — `npx vue-tsc -b` clean; `npm run build` succeeds.
11. **Frontend tests:** `frontend/` — `npm test`. Expect **10 files** passing.
12. **Frontend lint and format:** `frontend/` — `npm run lint` and `npm run format:check` clean.
13. **The form runs:** `frontend/` — `npm run dev`, sign in **as an agent**, click *New ticket*. The category dropdown lists only active categories, the priority dropdown starts on Medium, submitting with an empty subject shows a field error and makes no request (check the Network tab), and a valid submit shows `Ticket TKT-…-…… created.` Click *Create another* and confirm every field is cleared.
14. **Deactivate a category, then reload the form** and confirm it is gone from the dropdown while a ticket already carrying it still renders its badge.
15. **Docs:** `git diff docs/api-contract.md` shows the endpoint row, the new section and the rewritten Authorization paragraph. `git diff docs/erd.md` is empty.

---

## Done Criteria

- [ ] `POST /api/v1/tickets` validates every field through `StoreTicketRequest` and returns **`201`** with the ticket nesting `requester`, `category`, `priority`, `status` and `creator`.
- [ ] An existing requester is **matched by email** — case-insensitively — and **not** rewritten; a new one is created, and both happen inside the same transaction as the ticket, proven by `test_requester_and_ticket_share_a_transaction` failing when the transaction is removed.
- [ ] `status_id` and `priority_id` fall back to the `is_default` rows when omitted, and a missing default raises a `LogicException` naming `php artisan db:seed` rather than a `422`.
- [ ] `created_by` comes from the bearer token and is guarded three ways — absent from `#[Fillable]`, `prohibited` in the request, set as a property — with `reference` guarded the same and `assigned_to` `prohibited`.
- [ ] Exactly one `ticket_activities` row per ticket, `event = 'created'`, `user_id` = the filing agent, `meta.reference` = the ticket's reference, and zero rows when the request fails.
- [ ] `ActivityRecorder::record()` exists with defaults for all five attribute keys, and `recordMany()`'s signature is **unchanged** so `CategoryController` still compiles.
- [ ] `description` is capped at **16,000 characters** and rejects overlong input with a `422`, never MySQL's `ERROR 1406` as a 500; a 255-character Arabic subject is accepted.
- [ ] `category_id` must name an existing, **active**, non-soft-deleted category.
- [ ] `tickets.store` is classified `staff-write` in `RouteAuthorizationTest`, an agent is never `403`, an unauthenticated caller is `401`, and `test_agent_reaches_staff_routes` is **unmodified**.
- [ ] `masterData` exposes `activeCategories` and `defaultPriority`; the form's category dropdown reads `activeCategories` and its priority dropdown starts on the default. Neither dropdown issues its own request.
- [ ] The form validates required fields, the email format and both length caps **before** submitting; the button is disabled while in flight; and `submit()` returns early when already submitting, so the Enter key cannot double-submit.
- [ ] On success the form shows the created reference and *Create another* clears every field. (**TM-26** replaces this with a redirect to the detail page.)
- [ ] `composer test` is **127 tests, 125 passing** — the only failures being TM-14's `PasswordThrottleTest` and TM-21's `TicketReferenceTest`; `npm test` is green across 10 files; `./vendor/bin/pint --test`, `npx vue-tsc -b`, `npm run lint` and `npm run format:check` are clean.
- [ ] `docs/api-contract.md` documents the endpoint and no longer claims ticket policies are still to come; `docs/erd.md` is untouched.
- [ ] The overview records the four hand-offs: **TM-23** grows `TicketController` and `stores/tickets.ts`, **TM-26** replaces the success panel with a redirect and extends `TicketResource`, **TM-31** owns `assigned_to`, and **TM-38** owns `status_id` after creation.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 19.**
