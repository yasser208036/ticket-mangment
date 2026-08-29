# Story 33 — Resolving requires a resolution note (Story: TM-39)

## Prerequisites

- **Story 32 (TM-38) — PLANNED, NOT IMPLEMENTED, and every file this story edits is a file Story 32 creates.** Verified on disk while planning: `backend/app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php`, `frontend/src/components/TicketStatusDialog.vue` and the `tickets.status` route **do not exist**; `TicketController` has **no `changeStatus()`**; `TicketActivityEvent` has **no `StatusChanged` case**; `frontend/src/api/tickets.ts` has **no `changeTicketStatus`**. This plan is written as a **diff against Story 32's tasks 1, 2, 3, 7, 8, 9 and 11** — read [`32-story-change-a-tickets-status-TM-38.md`](32-story-change-a-tickets-status-TM-38.md) in full before this file. **Gate: do not start until `php artisan test --filter=TicketStatusTest` passes.**
- **Story 31 (TM-37) — PLANNED, NOT IMPLEMENTED.** `TicketWorkflow`, `StatusTransition` and `status_transitions` do not exist. This story **does not touch any of them**: the graph decides *whether* a move is legal, this story decides *what a legal move does to the clock*. Story 31's seeded graph is nevertheless load-bearing here — the only edges out of a terminal status are `resolved → closed`, `resolved → reopened` and `closed → reopened`, which is why the clearing rule in task 3 can be stated in terms of `is_terminal` instead of hard-coding `reopened`.
- **Story 34 (TM-40, reopen) is not planned yet, and this story takes one of its acceptance criteria.** TM-40's second criterion is *"Reopening clears `resolved_at` and `closed_at`"* — the same rule as this story's AC2. **It is implemented here**, because `resolved_at` cannot ship correctly without it: a reopened ticket still advertising a resolution date is simply wrong, and leaving the clearing to a later story means shipping a known-bad column. **TM-40 keeps the reopen *reason*, the reopened *badge* and the reopen *count*, and must not re-implement the clearing.** Recorded in the feature overview so TM-40's planner sees it.
- **Story 36 (TM-46, ticket timeline) does not exist and is not planned.** `GET /api/v1/tickets/{ticket}/activities` is E7-S2, sprint 4, and there is no timeline component anywhere in `frontend/src/`. **AC3's "rendered prominently in the timeline" is therefore satisfied on the ticket detail page, not in a timeline** — see the decision section. The storage half of AC3 is delivered in full here, so TM-46 renders the same row inline without a schema change.
- **Measured baseline, 2026-08-26, from `backend/`.** `composer test` → **101 tests, 289 assertions, 98 passing, 3 failing**; `./vendor/bin/pint --test` exits `0`. **Story 32 is expected to take that to 2 failures** by adopting `tickets.store` into `RouteAuthorizationTest::ACCESS`. **Re-measure after Story 32 lands** — this story's baseline is Story 32's *end* state, not this one.
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No new composer or npm dependency, and no migration.** `resolved_at`, `closed_at` and `first_responded_at` are already nullable timestamp columns (`backend/database/migrations/2026_08_26_084625_create_tickets_table.php:29–31`), already cast to `datetime` (`backend/app/Models/Ticket.php:20`), and already in the API response (`backend/app/Http/Resources/V1/TicketResource.php:24–26`) and on the detail page (`frontend/src/views/TicketDetailView.vue:11`). **This story fills columns that have been sitting empty since TM-21.**

---

## Story Goal

Resolving a ticket stops being a free action. The agent says how it was fixed, the system records when, and reopening honestly withdraws both.

1. Moving a ticket **into Resolved** requires a `resolution` note of at least **10** characters; without it the move is a `422` and nothing changes.
2. `resolved_at` is stamped on that move, `closed_at` is stamped on a move into Closed, and **both are cleared by any move into a non-terminal status** — which is how a reopen withdraws them.
3. `first_responded_at` is stamped the **first** time a ticket leaves New, and never overwritten.
4. The note is stored on the `status_changed` activity row under `meta.resolution`, and `GET /api/v1/tickets/{ticket}` surfaces the ticket's **current** resolution — note, author and time — as `data.resolution`.
5. The ticket detail page renders that block prominently, and the status dialog grows a required note field that appears only when the selected move is into Resolved.

**Not in scope, and each belongs to a named story.** **No reopen reason, no reopened badge, no reopen counter** — TM-40's first, third and fourth criteria; only its *clearing* clause is taken, and the reason field stays `prohibited`. **No timeline** — `GET /tickets/{ticket}/activities`, the prose rendering, the per-event icons and the system-vs-user styling are **TM-46** (E7-S2). **No internal notes** (TM-47). **No new activity event** — the note rides on `status_changed`, which Story 32 introduced. **No new column on `tickets`** and no migration; see the decision below. **No escalation** (TM-41). **No SLA, no first-response *target*, no reporting on any of the three timestamps** — this story fills them, E9 measures them. **No change to `TicketWorkflow`, `status_transitions`, `TicketPolicy` or the seeded graph.**

---

## Context — Read These Files First

1. [`32-story-change-a-tickets-status-TM-38.md`](32-story-change-a-tickets-status-TM-38.md) — **tasks 1, 2, 3, 7, 8, 9, 11 and test 15.** Task 1's `'resolution' => ['prohibited']` and test 15 `test_no_timestamp_is_stamped` are the two things this story is expected to delete. Story 32 says so in writing: *"TM-39 is the story that deletes this test and replaces it."* **Do not leave either in place.**
2. [`31-story-transition-table-and-workflow-guard-TM-37.md`](31-story-transition-table-and-workflow-guard-TM-37.md) — the **decision table of 14 seeded edges**. Read it to confirm the claim task 3 rests on: the only outgoing edges from `resolved` are `closed` and `reopened`, and from `closed` only `reopened`. **If that graph is edited, task 3's rule still holds** — it keys on `is_terminal`, not on slugs.
3. `backend/database/seeders/StatusSeeder.php:12–20` — the seven slugs and, critically, `is_terminal`: **`true` for `resolved` and `closed` only** (**17–18**). Task 3's clearing rule reads that column.
4. `backend/app/Models/Status.php` — `#[Fillable]` at **11**, `casts()` at **15–18** (`is_terminal => 'boolean'`), `scopeOrdered()` at **20–24**. Task 1 adds three constants to this file and nothing else.
5. `backend/app/Models/Ticket.php` — `casts()` at **18–21** already casts all three timestamps to `datetime`. **None of the three is in `#[Fillable]` (line 12), and none should be** — task 3 assigns them directly, so no mass-assignment path can ever set them.
6. `backend/app/Models/TicketActivity.php` — **17 lines.** `#[Fillable]` at **9**, `UPDATED_AT = null` at **12**, `casts()` at **14–17** (`meta => 'array'`). **There is no `user()` relation** — task 5 adds one.
7. `backend/app/Services/ActivityRecorder.php:13–19` — `record()` fills the five defaults and takes `meta` as a plain array; it `json_encode`s with `JSON_UNESCAPED_UNICODE` at **34**, which is why an Arabic resolution note round-trips unchanged.
8. `backend/app/Http/Resources/V1/TicketResource.php` — **lines 23–28.** `resolved_at`, `first_responded_at` and `closed_at` are already emitted at **24–26**; `escalation_reason` (**27**), `can` (**28**) and Story 32's `allowed_transitions` are gated on `$request->routeIs('tickets.show')`. Task 6 adds a fourth gated key beside them.
9. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php:25–47` — the `rules()` / `messages()` idiom, including `'assigned_to' => ['prohibited']` at **39** and its message at **46**. Task 2 rewrites Story 32's equivalent line into a conditional.
10. `frontend/src/api/statuses.ts` — **4 lines.** `Status` carries `slug` and `is_terminal` (**line 3**), which is what lets the dialog decide whether to show the note field **without another request**. Task 8 adds one exported constant here.
11. `frontend/src/views/NewTicketView.vue:12–14` — the client-side `validate()` precedent: a plain object of field messages, cleared before each submit, with the server's `validationErrors()` merged in on failure (**14**). Task 10's note field follows it.
12. `frontend/src/views/TicketDetailView.vue:11` — `ticket-resolved`, `ticket-closed` and `ticket-first-responded` are **already rendered** with `relativeAge()`. They have shown nothing since TM-21 because the columns were never written. Task 11 adds one block and changes nothing about those three.
13. `frontend/src/components/CategoryFormDialog.vue` and `frontend/src/components/UserFormDialog.vue:20–36` — the `errors` / `message` pair and the `validationErrors()`-then-`errorMessage()` order. Task 10 extends Story 32's dialog with one conditional field using the same two refs.
14. `docs/api-contract.md` — Story 32 adds the `### POST /api/v1/tickets/{ticket}/status` subsection and the `## Status workflow` section. Task 7 edits **both**, and adds nothing new to the endpoints table.

---

## Product rules (from story)

| Situation | Behaviour after Story 32 | New behaviour |
|---|---|---|
| Move into **Resolved** without `resolution` | `422` — the field is `prohibited` | `422` `required`, message names the note |
| `resolution` shorter than 10 characters | `422` `prohibited` | `422` `min`, message says why |
| `resolution` on any **other** move | `422` `prohibited` naming TM-39 | **Still `422` `prohibited`** — the message now names Resolved instead of a story |
| Move into Resolved | `resolved_at` stays null | `resolved_at = now()`, note stored on the activity row |
| Move into Closed | `closed_at` stays null | `closed_at = now()`; `resolved_at` **kept** |
| Move into any non-terminal status | Both stay null | **Both cleared** — this is how a reopen withdraws them |
| First move out of New | `first_responded_at` stays null | `first_responded_at = now()` |
| Any later move | — | `first_responded_at` **never overwritten** |
| `GET /tickets/{ticket}` on a resolved ticket | No resolution anywhere | `data.resolution` = `{ note, at, by }` |
| `GET /tickets/{ticket}` on a reopened ticket | — | `data.resolution` is **`null`** — the stale note is not shown |
| The status dialog | One select | Select **plus** a required note textarea, only when the choice is Resolved |
| `reason` field | `422` naming TM-40 | **Unchanged** — still TM-40's |

---

## Decision — the note lives on the activity row, not on `tickets`

AC3 says *"stored on the activity row"*, and this story takes that literally: `meta.resolution` on the `status_changed` row, **no `tickets.resolution` column and no migration**.

- **A column would be a second source of truth.** A ticket resolved, reopened and resolved again has two notes and one truth; the activity trail already orders them and the column would have to be kept in sync with it on every transition, including the clearing.
- **"The current resolution" is already well-defined without a column.** It is the note attached to the move that produced the ticket's current `resolved_at`. When `resolved_at` is null there is no current resolution, which makes AC2 and AC3 the *same* rule seen from two sides — and makes the read in task 6 free on every unresolved ticket.
- **TM-46 gets it for nothing.** The timeline reads `ticket_activities` anyway; the note is already on the row it will render.

**Do not add a `resolution` column "for convenience" later** without deciding what it means after a reopen.

## Decision — AC3's "rendered prominently in the timeline" is rendered on the detail page

There is no timeline. `GET /api/v1/tickets/{ticket}/activities` is **TM-46** (E7-S2, sprint 4) and no timeline component exists in `frontend/src/`. Rather than build half of TM-46 or defer AC3 entirely:

- **The storage half ships in full** — `meta.resolution`, the exact key TM-46 will read.
- **The rendering half ships as a dedicated `ticket-resolution` block on the detail page**, not as a timeline entry. That satisfies the intent — the next person to open the ticket sees how it was fixed, prominently — with a component that is correct on its own terms and that TM-46 does not have to unpick.
- **This is the same split Story 29 (TM-34) made** for its activity `meta.reason`, with one difference stated deliberately: TM-34 shipped storage only, because a reassignment reason is a history fact. A resolution is a **current-state** fact about the ticket, so it earns a place on the page that shows current state.

**TM-46 renders the same row inline in chronological position. It does not remove this block** — one is "how this ticket stands", the other is "what happened when".

## Decision — minimum length is 10 characters, maximum 5000

- **10** rejects `ok`, `done`, `fixed`, `resolved` (8) and `no issue` (8) — the whole point of AC1 — while accepting a real short answer like `Replaced RAM` (12) or `Cleared the print queue.`
- **5000** caps a note that lands in a JSON column; `description` is capped at 16000 (`StoreTicketRequest.php:34`), and a resolution is a summary, not a transcript.
- **Both numbers live in `ChangeTicketStatusRequest` only**, and the SPA mirrors the minimum as a courtesy check. **The server is the authority**; test 24 proves a client that skips its own check still gets a `422`.

## Decision — three slug constants on `Status`, and no `requires_note` column

Task 3 has to know which statuses mean *resolved*, *closed* and *new*. Adding a `requires_note` / `stamps_resolved_at` column to `statuses` was rejected: `tickets` already has `resolved_at`, `closed_at` and `first_responded_at` as **columns**, so the schema committed to those three slugs being product vocabulary back in TM-21. A configuration column would make the *note* configurable while the *timestamps* stayed hard-coded — worse than either extreme.

Instead the three strings appear **once**, as `Status::SLUG_NEW`, `Status::SLUG_RESOLVED`, `Status::SLUG_CLOSED`. **The graph stays fully configurable** — which moves are legal is still data — and only the meaning of three specific statuses is code. Say so in the PR.

---

## Backend Tasks

### 1 — Slug constants on `Status`

**File: `backend/app/Models/Status.php`**

Add above `casts()` (**line 15**):

```php
    /**
     * The three slugs the schema already commits to: `tickets` carries
     * resolved_at, closed_at and first_responded_at as columns. Which moves are
     * legal stays configurable in status_transitions; what these three statuses
     * *mean* is code, and it is written down exactly once, here.
     */
    public const SLUG_NEW = 'new';

    public const SLUG_RESOLVED = 'resolved';

    public const SLUG_CLOSED = 'closed';
```

**`SLUG_` prefixed, not `Status::NEW`** — `new` is a PHP keyword and the prefixed form reads unambiguously at the call site. **No other file may contain the literal `'resolved'`, `'closed'` or `'new'` as a status slug after this story** — grep for them as part of verification step 6.

### 2 — Make `resolution` conditional in the request

**File: `backend/app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php`** *(Story 32's task 1)*

Add `use App\Models\Status;` and replace the flat `'resolution' => ['prohibited']` rule with:

```php
    public function rules(): array
    {
        // One extra query, deliberately: the note is required by the *target*
        // status, and only the id is in the body. Rule::exists below still
        // proves the id, so a null slug here simply means "not resolving" and
        // the exists rule produces the error.
        $resolving = Status::query()->whereKey($this->integer('status_id'))->value('slug') === Status::SLUG_RESOLVED;

        return [
            'status_id' => ['required', 'integer', Rule::exists('statuses', 'id')],
            'resolution' => $resolving
                ? ['required', 'string', 'min:10', 'max:5000']
                : ['prohibited'],
            'reason' => ['prohibited'],
        ];
    }
```

And in `messages()`, replace the `resolution.prohibited` entry and add two:

```php
            'resolution.required' => 'Say how the ticket was resolved before resolving it.',
            'resolution.min' => 'The resolution note must be at least 10 characters.',
            'resolution.prohibited' => 'A resolution note belongs only on a move into Resolved.',
```

**Leave `reason.prohibited` naming TM-40 exactly as Story 32 wrote it.** Reopening is still not implemented and a premature client should still be told which story owns it.

`trim` is **not** applied: Laravel's `TrimStrings` middleware already trims every input, so `"          "` reaches validation as `""` and fails `required`. Test 4 pins that rather than trusting it.

### 3 — The timestamp service

**Create file: `backend/app/Services/TicketTimestamps.php`**

```php
<?php

namespace App\Services;

use App\Models\Status;
use App\Models\Ticket;

class TicketTimestamps
{
    /**
     * Apply the lifecycle clock to an in-memory ticket. Mutates and does not
     * save: the caller is inside a transaction and saves once, so the status
     * and its timestamps can never disagree.
     */
    public function apply(Ticket $ticket, Status $from, Status $to): void
    {
        // "The first time a ticket leaves New." No seeded edge leads back into
        // New, so the null guard is belt and braces -- keep it anyway: it is
        // what makes the rule true rather than accidentally true.
        if ($from->slug === Status::SLUG_NEW && $ticket->first_responded_at === null) {
            $ticket->first_responded_at = now();
        }

        if (! $to->is_terminal) {
            // Any move back into an active status withdraws both stamps. Stated
            // in terms of is_terminal rather than the `reopened` slug so an
            // edited graph cannot leave a reopened ticket advertising a
            // resolution date. On a ticket that was never resolved this is a
            // no-op: both are already null.
            $ticket->resolved_at = null;
            $ticket->closed_at = null;

            return;
        }

        if ($to->slug === Status::SLUG_RESOLVED) {
            $ticket->resolved_at = now();
        }

        // Closing keeps resolved_at: the ticket really was resolved on that day.
        if ($to->slug === Status::SLUG_CLOSED) {
            $ticket->closed_at = now();
        }
    }
}
```

Four things not to re-derive:

- **It mutates and does not save.** The single `save()` in the controller keeps the status and its timestamps in one statement, and the whole thing in one transaction.
- **No `DB::transactionLevel()` guard**, matching `TicketWorkflow` and unlike `TicketReferenceGenerator` — it touches no database.
- **`resolved_at` is re-stamped on a second resolve.** A ticket resolved, reopened and resolved again gets the **new** date, which is why the read in task 6 must take the latest matching activity row and not the first.
- **Registered nowhere.** Laravel resolves the concrete class, as it does for `ActivityRecorder` and `TicketWorkflow`.

### 4 — Wire it into the status endpoint

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`** *(Story 32's task 2)*

Add `use App\Services\TicketTimestamps;`, take it as a fifth parameter on `changeStatus()`, and make three edits **inside** the existing `DB::transaction` closure, all after `assertCanTransition()` and before `save()`:

```php
            $workflow->assertCanTransition($ticket, $target, $actor);

            $ticket->status_id = $target->getKey();
            $timestamps->apply($ticket, $from, $target);
            $ticket->save();

            $recorder->record($ticket->getKey(), TicketActivityEvent::StatusChanged, [
                'user_id' => $actor->getKey(),
                'field' => 'status_id',
                'old_value' => (string) $from->getKey(),
                'new_value' => (string) $target->getKey(),
                'meta' => array_filter([
                    'from_name' => $from->name,
                    'to_name' => $target->name,
                    // Absent, not null, when there is no note -- so `meta->resolution`
                    // in task 6's query means "this row resolved the ticket".
                    'resolution' => $request->validated('resolution'),
                ], fn ($value): bool => $value !== null),
            ]);
```

- **`apply()` goes after the status assignment and before `save()`.** Before the assignment it would still work today, but the ordering makes the read "here is the new state, now stamp it" obvious.
- **`array_filter` with an explicit `!== null` callback**, not the default truthy filter — a default `array_filter` would also drop a status name of `"0"`.
- **`$from` is still the pre-move status read after `refresh()`**, exactly as Story 32 specifies. Nothing about that ordering changes.

### 5 — A `user()` relation on the activity model

**File: `backend/app/Models/TicketActivity.php`**

Add after `casts()` (**ends at 17**):

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

with `use Illuminate\Database\Eloquent\Relations\BelongsTo;`. `user_id` is nullable (`…_create_ticket_activities_table.php:14`) and a null actor is the system, so **every consumer must handle a null `user`** — task 6 does.

**Add nothing else to this model.** TM-48 (E7-S4) makes it append-only; a relation is a read and does not conflict.

### 6 — `data.resolution` on the detail response

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Add `use App\Enums\TicketActivityEvent;` and `use App\Models\TicketActivity;`, then one key immediately after Story 32's `allowed_transitions`:

```php
            'resolution' => $this->when($request->routeIs('tickets.show'), fn () => $this->currentResolution()),
```

and a private method on the resource:

```php
    /** @return array{note: string, at: string|null, by: array{id: int, name: string}|null}|null */
    private function currentResolution(): ?array
    {
        // resolved_at is the index into the trail: it is stamped by the move
        // that carried the note and cleared by any move back into an active
        // status, so a reopened ticket costs zero queries and shows no stale
        // note. `latest('id')` and not `first()` -- a ticket can be resolved
        // more than once.
        if ($this->resolved_at === null) {
            return null;
        }

        $activity = TicketActivity::query()
            ->with('user')
            ->where('ticket_id', $this->id)
            ->where('event', TicketActivityEvent::StatusChanged)
            ->whereNotNull('meta->resolution')
            ->latest('id')
            ->first();

        if ($activity === null) {
            return null;
        }

        return [
            'note' => $activity->meta['resolution'],
            'at' => $this->resolved_at?->toIso8601String(),
            'by' => $activity->user === null ? null : ['id' => $activity->user->id, 'name' => $activity->user->name],
        ];
    }
```

- **`by` is `null` for a system actor**, never a fabricated name — `user_id` is nullable and TM-45's criterion is *"A null `user_id` means the actor was the system."*
- **Only `id` and `name`**, matching how `assignee` and `creator` are narrowed at **19–20**. Nesting `UserResource` would leak the staff directory — Story 18's constraint.
- **`at` comes from `tickets.resolved_at`, not from the activity's `created_at`.** They are written in the same transaction, and the ticket column is the one the rest of the payload already reports at **25**; taking both from the same place makes them impossible to disagree.
- **Two extra queries, and only on a resolved ticket** (the row, then the eager-loaded user). Zero on every unresolved one. Test 20 pins both numbers.

### 7 — Documentation

**File: `docs/api-contract.md`**

In the `## Status workflow` section Story 31 added, append after the edge table:

```markdown
Moving into Resolved requires a `resolution` note of 10–5000 characters; the note
is stored as `meta.resolution` on the `status_changed` activity row and is
rejected on any other move. Moving into Resolved stamps `resolved_at`, moving
into Closed stamps `closed_at` and keeps `resolved_at`, and **any** move into a
non-terminal status clears both — which is how a reopen withdraws them.
`first_responded_at` is stamped the first time a ticket leaves New and is never
overwritten.
```

In the `### POST /api/v1/tickets/{ticket}/status` subsection Story 32 added, replace the sentence *"A `resolution` or `reason` field returns `422` naming TM-39 or TM-40"* with:

```markdown
A `resolution` is required on a move into Resolved and rejected on every other
move; a `reason` field returns `422` naming TM-40. The `status_changed` row
carries `meta.resolution` when the move resolved the ticket, and no timestamp is
reported in this response beyond the ticket's own columns.
```

And in the `### GET`-side description of the detail response (the `## Status workflow` paragraph about `allowed_transitions`), add:

```markdown
`data.resolution` is the ticket's current resolution — `{ note, at, by }`, with
`by` null when the system acted — or `null` when the ticket is not currently
resolved. It is read from the activity trail, not from a column on `tickets`.
```

**No new row in the endpoints table** — this story adds no endpoint.

---

## Frontend Tasks

### 8 — The slug constant and the API call

**File: `frontend/src/api/statuses.ts`**

Add after the `Status` interface (**line 3**):

```ts
export const RESOLVED_SLUG = 'resolved'
```

**One constant, not three** — the SPA only needs to recognise Resolved. `closed` and `new` are backend-only concerns here, and inventing unused constants invites someone to branch on them.

**File: `frontend/src/api/tickets.ts`** *(Story 32's task 7)*

Extend `TicketDetail` with one more field:

```ts
export interface TicketResolution { note: string; at: string | null; by: TicketStaff | null }
```

…and add `resolution: TicketResolution | null` to `TicketDetail`. **`TicketStaff` already exists** at line 6 and is the same `{ id, name }` shape the server sends.

Widen Story 32's call with an optional third argument:

```ts
export async function changeTicketStatus(id: number, statusId: number, resolution?: string): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/status`, { status_id: statusId, ...(resolution === undefined ? {} : { resolution }) }); return data.data }
```

**Spread, not `resolution: resolution ?? null`.** The field is `prohibited` on every non-resolving move and a literal `null` in the body would be a `422`; the key must be **absent**.

### 9 — The store action

**File: `frontend/src/stores/tickets.ts`** *(Story 32's task 8)*

Widen the signature and pass the note straight through:

```ts
async function changeStatus(id: number, statusId: number, resolution?: string): Promise<void> {
```

`await changeTicketStatus(id, statusId, resolution)`. **Everything else in that action is unchanged**, including the `loadTicket(id)` re-read and both of Story 32's comments — the re-read is what makes the new `resolution` block and the stamped timestamps appear.

### 10 — The note field in the status dialog

**File: `frontend/src/components/TicketStatusDialog.vue`** *(Story 32's task 9)*

Add one computed, one ref and one field. Import `RESOLVED_SLUG` from `../api/statuses`.

```ts
const resolution = ref('')
// The slug is already on every entry of allowed_transitions -- no second
// request, and no need for the master-data store the dialog deliberately
// does not import.
const resolving = computed(() => props.ticket.allowed_transitions.find((status) => status.id === selected.value)?.slug === RESOLVED_SLUG)
const noteTooShort = computed(() => resolving.value && resolution.value.trim().length < 10)
```

`confirm()` passes it only when it applies:

```ts
    await store.changeStatus(props.ticket.id, selected.value, resolving.value ? resolution.value : undefined)
```

| Element | `data-testid` | Notes |
|---|---|---|
| Note textarea | `ticket-status-resolution` | `v-if="resolving"`, `v-model="resolution"`, placeholder **"How was this resolved?"** |
| Note hint | `ticket-status-resolution-hint` | `v-if="noteTooShort"` — **"At least 10 characters."** |
| Note server error | `ticket-status-resolution-error` | `v-if="errors.resolution"`, renders `errors.resolution[0]` |
| Confirm | `ticket-status-confirm` | `:disabled` gains `|| noteTooShort` |

- **The client check is a courtesy, not the rule.** The server owns 10–5000; test 24 sends a short note past a stubbed client check and asserts the `422` still lands in `ticket-status-resolution-error`.
- **`resolution` is not cleared when the selection changes.** An agent who picks Resolved, types a note, glances at Pending and comes back should still have their note. It is simply not sent when `resolving` is false.
- **Nothing else in Story 32's dialog changes** — same `errors` / `error` refs, same two-step read, same "stays open on failure".

### 11 — The resolution block on the detail page

**File: `frontend/src/views/TicketDetailView.vue`** *(Story 32's task 11)*

Add inside the `ticket-detail` article on line **11**, **above** the `<CategoryBadge>` so it reads before the metadata:

```html
<section v-if="store.current.resolution" data-testid="ticket-resolution"><h3>Resolution</h3><p data-testid="ticket-resolution-note">{{ store.current.resolution.note }}</p><p data-testid="ticket-resolution-by">{{ store.current.resolution.by?.name || 'System' }}</p><p v-if="store.current.resolution.at" :title="store.current.resolution.at">{{ relativeAge(store.current.resolution.at) }}</p></section>
```

- **`|| 'System'`** for a null actor, matching TM-45's *"A null `user_id` means the actor was the system, and the UI renders it as such."*
- **`{{ }}` interpolation, never `v-html`.** The note is agent-authored free text stored verbatim; Vue escapes it. TM-47's third criterion makes the same rule explicit for notes.
- **`ticket-resolved`, `ticket-closed` and `ticket-first-responded` on line 11 are not touched.** They have rendered `relativeAge()` since TM-26 and start showing real values the moment task 3 lands — **that is AC2, AC4 and AC5's UI half, and it costs zero new markup.** Verification step 9 checks all three appear.

---

## Edge Cases & Failure Modes

- **Resolving with no `resolution` key** → `422` `Say how the ticket was resolved before resolving it.` under `errors.resolution`; the status does not move and no activity row is written, because the form request fails before the controller runs. Test 3.
- **Resolving with `"   "`** → `TrimStrings` rewrites it to `""` before validation, so it fails `required`, not `min`. **Measured behaviour of the framework, pinned by test 4** so nobody adds a redundant `trim` in `prepareForValidation`.
- **Resolving with 9 characters** → `422` `The resolution note must be at least 10 characters.` Test 5.
- **A 5001-character note** → `422` `max`. Test 6.
- **A note containing `<script>` or Arabic** → stored **verbatim**; `ActivityRecorder` encodes with `JSON_UNESCAPED_UNICODE` (`ActivityRecorder.php:34`) and both MySQL containers are `utf8mb4`. Escaping on the way in would corrupt a legitimate note — the same rule Story 29 records for its reason. **The escape belongs at render**, and Vue's `{{ }}` does it. Test 7 round-trips both.
- **A `resolution` on a non-resolving move** → `422` `A resolution note belongs only on a move into Resolved.` **The client never sends it** (task 10 passes `undefined`), so this fires only on a hand-rolled request. Test 8.
- **A `reason` on any move** → still `422` naming **TM-40**. Unchanged from Story 32. Test 9.
- **Resolving a ticket that is already resolved** → unreachable: `assertCanTransition()` rejects a same-status move first, with `This ticket is already Resolved.` The note is never read. Test 10.
- **`resolved → closed`** → `closed_at` stamped, `resolved_at` **kept**, and `data.resolution` still returned — a closed ticket still says how it was fixed. Test 12.
- **`closed → reopened`** → both cleared in the same statement as the status, and `data.resolution` becomes `null` **without deleting the activity row**. The history keeps the old note; the ticket stops claiming it. Test 13.
- **Resolve → reopen → resolve** → `resolved_at` is the **second** date and `data.resolution` is the **second** note. `latest('id')` in task 6 is what makes that true; **swap it for `first()` and test 14 fails.**
- **`new → pending`** → `first_responded_at` stamped. "Leaving New" is any outgoing edge, not specifically `new → open`. Test 16.
- **A second move after leaving New** → `first_responded_at` unchanged. Captured before and compared after. Test 17.
- **A ticket created directly into a non-New status** (`POST /tickets` accepts `status_id`, `StoreTicketRequest.php:37`) → `first_responded_at` stays null until it *moves*, because nothing about creation is a transition. **Consequence recorded rather than hidden**: such a ticket can reach Resolved with a null `first_responded_at`. Test 18 pins it as intentional; **do not "fix" it in `store()`.**
- **A resolved ticket whose activity row was written before this story** → `resolved_at` is null on every such ticket (the column has never been written), so `currentResolution()` returns `null` without a query. **No backfill is needed and none is performed.**
- **A `status_changed` row with `meta` null** → `whereNotNull('meta->resolution')` excludes it; MySQL evaluates the JSON path on a NULL column to NULL. No 500 on a mixed trail.
- **The resolving agent's account is deleted** → `ticket_activities.user_id` becomes null via `ON DELETE SET NULL` (`…_create_ticket_activities_table.php:14`) and the block renders **"System"**. Unreachable through the API today (`UserPolicy::delete()` is false for everyone), but the null path is real and tested. Test 21.
- **Query cost** → **+2 on a resolved ticket's detail, +0 on every other response.** Together with Story 32's `allowed_transitions` (+2 always) that is +4 on the detail of a resolved ticket. Test 20 pins both.
- **Two agents resolve at once** → they serialise on Story 32's `lockForUpdate()`; the second is re-checked against Resolved and gets `This ticket is already Resolved.` Only one note is ever stored. No change here.

---

## Test Plan

**Every test below is added to files Story 32 created.** Two of Story 32's tests are **replaced, not left alongside**:

- `TicketStatusTest::test_resolution_and_reason_are_prohibited` → **split**. `reason` keeps its assertion (test 9); the `resolution` half is superseded by tests 3–8.
- `TicketStatusTest::test_no_timestamp_is_stamped` → **deleted.** Story 32 wrote it as a scope fence and named this story as its executioner. Tests 11–18 are the replacement.

### Backend — `backend/tests/Feature/Tickets/TicketStatusTest.php` (modified)

1. `test_resolving_with_a_note_succeeds` — **AC1.** `in-progress → resolved` with a 20-character note → `200`.
2. `test_the_note_is_stored_on_the_activity_row` — **AC3's storage half.** The `status_changed` row's `meta.resolution` is the note verbatim; `meta.from_name` / `meta.to_name` are still present.
3. `test_resolving_without_a_note_is_rejected` — `422`, `assertJsonPath('errors.resolution.0', 'Say how the ticket was resolved before resolving it.')`, **status unchanged, no activity row, `resolved_at` still null.**
4. `test_a_whitespace_only_note_is_rejected_as_required` — `"   "` → `422` with the **`required`** message, not the `min` one. Pins `TrimStrings`.
5. `test_a_short_note_is_rejected` — 9 characters → `422`, `The resolution note must be at least 10 characters.`
6. `test_an_overlong_note_is_rejected` — 5001 characters → `422` `max`.
7. `test_a_note_is_stored_verbatim` — one note containing `<script>alert(1)</script>` and one in Arabic; both read back byte-identical from `meta.resolution`. **utf8mb4 and no sanitising.**
8. `test_a_note_on_a_non_resolving_move_is_rejected` — `new → open` with a note → `422`, `A resolution note belongs only on a move into Resolved.`
9. `test_reason_is_still_prohibited` — **Story 32's surviving half.** `422` naming TM-40.
10. `test_resolving_an_already_resolved_ticket_is_rejected_before_the_note` — same-status move with a valid note → `422` `This ticket is already Resolved.`, **not** a resolution error.
11. `test_resolving_stamps_resolved_at` — **AC2.** Non-null after, and within a second of `now()`.
12. `test_closing_stamps_closed_at_and_keeps_resolved_at` — **AC4.** As an admin (the `resolved → closed` edge is admin-only): `closed_at` set, `resolved_at` **unchanged**, and `data.resolution` still returned by `show()`.
13. `test_reopening_clears_both_timestamps` — **AC2's second half, and TM-40's clause.** `closed → reopened`: both null, `data.resolution` is `null`, and the `status_changed` row carrying the note **still exists**.
14. `test_a_second_resolution_supersedes_the_first` — resolve, reopen, resolve with a different note. `data.resolution.note` is the **second**; there are **two** rows with `meta.resolution`. **Swap `latest('id')` for `first()` in task 6 and confirm this fails.**
15. `test_moving_to_a_non_terminal_status_clears_nothing_it_should_not` — `new → open` on a never-resolved ticket: both stay null, no error. The no-op branch.
16. `test_leaving_new_stamps_first_responded_at` — **AC5.** Via `new → pending`, **not** `new → open`, so the test proves "leaving New" and not one specific edge.
17. `test_first_responded_at_is_never_overwritten` — capture after the first move, make two more, assert byte-identical.
18. `test_a_ticket_created_outside_new_has_no_first_response_until_it_moves` — create with `status_id` = Open, resolve it, assert `first_responded_at` is **still null**. **Pins the consequence as intentional.**
19. `test_the_timestamps_are_atomic_with_the_status` — bind a throwing `ActivityRecorder`; assert the exception propagates **and** `status_id`, `resolved_at` and `first_responded_at` are all unchanged. Extends Story 32's test 14 to the three columns.
20. `test_resolution_query_cost` — `GET /tickets/{id}` on an **unresolved** ticket costs exactly Story 32's pinned number; on a **resolved** one, exactly **two more**. Both figures in the PR description.
21. `test_a_system_authored_resolution_renders_as_null_by` — null the activity's `user_id` directly, then `show()` → `data.resolution.by` is `null` and `data.resolution.note` is intact.
22. `test_resolution_is_absent_from_the_status_response` — `assertJsonMissingPath('data.resolution')` on the `POST …/status` response, beside Story 32's `can` / `allowed_transitions` assertions. Guards the `routeIs('tickets.show')` gate.
23. `test_an_unresolved_ticket_reports_a_null_resolution` — `show()` on a New ticket → `data.resolution` is **`null`**, and the key is **present**, so the SPA's type is honest.

### Frontend — `frontend/src/components/TicketStatusDialog.spec.ts` (Story 32's file; modified)

24. Selecting Resolved reveals `ticket-status-resolution`; selecting any other option hides it. **Driven by the fixture's `allowed_transitions` slugs**, not by an id the test invents.
25. `ticket-status-confirm` is disabled while the note is shorter than 10 characters and enabled at 10, with `ticket-status-resolution-hint` visible below the threshold and gone above it.
26. Confirming a resolving move calls `changeTicketStatus(id, resolvedId, note)`; confirming a **non**-resolving move calls it with **`undefined`** as the third argument. **Assert the third argument explicitly** — sending `null` would be a `422`.
27. A `422` of `{"errors": {"resolution": ["The resolution note must be at least 10 characters."]}}` renders that sentence in `ticket-status-resolution-error` and keeps the dialog mounted. **The server wins even when the client check was bypassed.**
28. Typing a note, switching the selection to a non-resolving option and switching back keeps the note. Pins the deliberate non-clearing.

### Frontend — `frontend/src/views/TicketDetailView.spec.ts` (Story 32's file; modified)

29. A ticket with a `resolution` renders `ticket-resolution`, its note and the author's name; a ticket with `resolution: null` renders **no** `ticket-resolution` block.
30. `resolution.by === null` renders **"System"**.
31. A note containing `<script>alert(1)</script>` is rendered as **text** — assert `.text()` contains the literal and `wrapper.html()` contains no live `<script>` element.
32. A ticket with `resolved_at`, `closed_at` and `first_responded_at` set renders `ticket-resolved`, `ticket-closed` and `ticket-first-responded`. **The regression that proves AC2, AC4 and AC5 reached the page** — those three testids have existed since TM-26 and have never had a value to show.

---

## Verification Steps

1. **Gate:** from `backend/`, `php artisan test --filter=TicketStatusTest` → green **before** you change anything. If it is red or the file is missing, Story 32 has not landed and nothing here can work.
2. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
3. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
4. **Backend tests:** `composer test`. Expect **+21 tests** over Story 32's end state (23 added, 1 deleted, 1 split), and the **same 2** pre-existing failures — `PasswordThrottleTest` and `TicketReferenceTest`.
5. **Prove test 14 earns its place:** swap `latest('id')` for `first()` in `currentResolution()`, re-run `--filter=test_a_second_resolution_supersedes_the_first`, confirm it **fails**, restore.
6. **Prove the constants are the only copy:** `grep -rn "'resolved'\|'closed'\|\"resolved\"" backend/app` → the only hits are the three constant declarations in `Status.php`. Any other hit is a slug that escaped task 1.
7. **Prove the clearing is general, not slug-matched:** temporarily flip `statuses.is_terminal` to `1` for `reopened`, re-run `--filter=test_reopening_clears_both_timestamps`, confirm it **fails** (both stamps survive), restore with `php artisan db:seed --class=StatusSeeder`. That is the demonstration that task 3 keys on the column and not on a hard-coded `reopened`.
8. **Backend by hand.** `php artisan serve`, agent token, a New ticket:
   - `POST …/status -d '{"status_id":<open>}'` → `200`; `SELECT first_responded_at, resolved_at, closed_at FROM tickets WHERE id=<id>` → **first set, other two NULL.**
   - `-d '{"status_id":<in-progress>}'` then `-d '{"status_id":<resolved>}'` with no note → `422` `Say how the ticket was resolved before resolving it.`; the row is unchanged.
   - The same with `"resolution":"fixed"` → `422` `The resolution note must be at least 10 characters.`
   - The same with `"resolution":"Replaced the failed PSU and reseated the RAM."` → `200`; `resolved_at` set, `first_responded_at` **unchanged**.
   - `SELECT meta FROM ticket_activities WHERE ticket_id=<id> ORDER BY id DESC LIMIT 1` → `from_name`, `to_name` **and** `resolution`.
   - `GET …/tickets/<id>` → `data.resolution.note` is the sentence, `data.resolution.by.name` is the agent, `data.resolution.at` matches `data.resolved_at`.
   - As an **admin**, `-d '{"status_id":<closed>}'` → `200`; `closed_at` set, `resolved_at` **still set**, `data.resolution` still returned.
   - `-d '{"status_id":<reopened>}'` → `200`; **both NULL**, `data.resolution` is `null`, and the activity row with the note is **still in the table**.
   - Resolve again with a different note → `data.resolution.note` is the **new** one.
   - `-d '{"status_id":<pending>,"resolution":"anything at all"}'` → `422` `A resolution note belongs only on a move into Resolved.`
   - `-d '{"status_id":<open>,"reason":"x"}'` → `422` still naming **TM-40**.
   - `-d '{"status_id":<resolved>,"resolution":"   "}'` → `422` **required**, not `min`.
9. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+9 tests** across Story 32's two spec files. Then `npx prettier --check src/api/statuses.ts src/api/tickets.ts src/stores/tickets.ts src/components/TicketStatusDialog.vue src/views/TicketDetailView.vue src/components/TicketStatusDialog.spec.ts src/views/TicketDetailView.spec.ts`.
10. **Frontend by hand:** `npm run dev`, as an **agent**.
    - Open a New ticket → **no** Resolution block, and the resolved / closed / first-responded lines are absent.
    - Change status to Open → the **first-responded** line now appears. *(AC5 on screen.)*
    - Open the dialog, select In Progress → **no note field**. Select Resolved → the note field appears and Confirm is **disabled**.
    - Type `fixed` → still disabled, hint visible. Type a real sentence → enabled.
    - Switch the selection to Pending and back to Resolved → **the note is still there**.
    - Confirm → the dialog closes, the status badge changes, the **Resolution block appears** with the note and your name, and the resolved line appears — **all without a reload.** *(AC2, AC3, AC4.)*
    - Paste `<script>alert(1)</script>` as a note on a second ticket → it renders as **literal text**, no dialog fires.
    - As an **admin**, close that ticket → the closed line appears and the **Resolution block stays**.
    - Reopen it → the Resolution block **disappears**, and both the resolved and closed lines go with it.
11. **Regression:** file a ticket from `/tickets/new`, run a non-resolving status change on it and confirm no note field ever appeared and no `resolution` key was in the request body (network tab). Then confirm `git status` shows **no change** to `TicketWorkflow.php`, `StatusTransitionSeeder.php`, `TicketPolicy.php`, `routes/api.php` or any migration.

---

## Done Criteria

- [ ] A move into Resolved without a `resolution` of 10–5000 characters returns `422` under `errors.resolution`; the status does not move, no activity row is written and `resolved_at` stays null.
- [ ] Whitespace-only is rejected as **`required`** (not `min`), and no redundant `trim` was added — `TrimStrings` already does it.
- [ ] A `resolution` on any non-resolving move returns `422`; `reason` still returns `422` naming **TM-40**.
- [ ] The note is stored verbatim as `meta.resolution` on the `status_changed` row — **no new column, no migration, no sanitising on the way in** — and survives `<script>` and Arabic byte-for-byte.
- [ ] `resolved_at` is stamped on a move into Resolved, `closed_at` on a move into Closed with `resolved_at` **kept**, and **both cleared by any move into a non-terminal status** — expressed with `is_terminal`, not the `reopened` slug, and proven by flipping that column.
- [ ] `first_responded_at` is stamped the first time a ticket leaves New — via any outgoing edge — and is **never** overwritten; a ticket created outside New has none until it moves, pinned as intentional.
- [ ] All three timestamps are written in the **same statement and transaction** as the status; a thrown recorder leaves every one of them unchanged.
- [ ] `GET /api/v1/tickets/{ticket}` returns `data.resolution` = `{ note, at, by }` for a currently-resolved ticket and **`null`** otherwise; `by` is `null` for a system actor; `at` is taken from `resolved_at`, not the activity's `created_at`.
- [ ] A ticket resolved twice reports the **second** note, proven by a test that fails when `latest('id')` becomes `first()`.
- [ ] `data.resolution` costs **two** queries on a resolved ticket and **zero** on every other response, pinned by a test, with both figures in the PR.
- [ ] `data.resolution` is **absent** from the status-change response and from `POST /tickets`; the `routeIs('tickets.show')` gate is unchanged.
- [ ] `Status::SLUG_NEW`, `SLUG_RESOLVED` and `SLUG_CLOSED` are the **only** occurrences of those three slugs in `backend/app`, verified by grep, and **no `requires_note` column was added to `statuses`**.
- [ ] The dialog shows the note field **only** when the selected move is into Resolved, keeps the typed note across selection changes, sends `undefined` rather than `null` otherwise, and renders the server's message when the client check is bypassed.
- [ ] The detail page renders a `ticket-resolution` block with the note, the author (or **"System"**) and the time, escaped by `{{ }}` and never `v-html`; `ticket-resolved`, `ticket-closed` and `ticket-first-responded` now show real values with **no markup change**.
- [ ] Story 32's `test_no_timestamp_is_stamped` is **deleted** and its `test_resolution_and_reason_are_prohibited` is **split**, not duplicated.
- [ ] `docs/api-contract.md` documents the note rule, the three timestamp rules, the clearing rule, and `data.resolution`'s shape and source — with **no new endpoints-table row**.
- [ ] **TM-40's clearing clause is implemented here and recorded**, so TM-40 ships only the reason, the badge and the count; **TM-46's timeline is untouched** and reads the same `meta.resolution` when it lands.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+21 backend and +9 frontend tests** over Story 32's end state; **2** pre-existing backend failures remain, both named.
- [ ] No reopen reason, no timeline, no internal notes, no escalation, no new endpoint, no new dependency, no migration.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 34 (TM-40, reopen a closed ticket).**
