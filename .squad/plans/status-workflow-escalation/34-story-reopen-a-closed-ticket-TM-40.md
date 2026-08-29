# Story 34 — Reopen a closed ticket (Story: TM-40)

## Prerequisites

- **Story 33 (TM-39) — PLANNED, NOT IMPLEMENTED, and it already shipped this story's AC2.** Read [`33-story-resolving-requires-a-resolution-note-TM-39.md`](33-story-resolving-requires-a-resolution-note-TM-39.md) first. Its `TicketTimestamps::apply()` clears `resolved_at` and `closed_at` on **any** move into a non-terminal status, which is exactly *"Reopening clears `resolved_at` and `closed_at`"* — and its overview note says in writing that TM-40 must not re-implement it. **This story writes no timestamp code at all.** It also owns the `Status::SLUG_*` constants task 1 extends and the `resolution` / `reason` rule shape task 2 extends.
- **Story 32 (TM-38) — PLANNED, NOT IMPLEMENTED.** `POST /api/v1/tickets/{ticket}/status`, `ChangeTicketStatusRequest`, `TicketActivityEvent::StatusChanged`, `TicketStatusDialog.vue` and `changeTicketStatus()` all originate there. Its `'reason' => ['prohibited']` rule carries the message *"A reopen reason arrives with TM-40."* — **this is that story, and that line is the one it flips.**
- **Story 31 (TM-37) — PLANNED, NOT IMPLEMENTED, and it already shipped this story's AC1 first half.** Its seeded graph contains `resolved → reopened` and `closed → reopened`, both ungated. **AC1's *"available from Resolved and Closed"* is therefore already true the moment Story 31 lands; this story only adds *"and requires a reason"*.** Do not add an edge, do not touch `StatusTransitionSeeder`, and do not gate either edge on a role — Story 31's plan records that `closed → reopened` was **deliberately** left open to agents *because* TM-40 is written for an agent.
- **Chain gate.** Every file this story edits is created by Story 32 or Story 33. **Do not start until `php artisan test --filter=TicketStatusTest` passes with Story 33's tests in it** — specifically `test_reopening_clears_both_timestamps`, which is the proof AC2 is already done.
- **Story 36 (TM-46, ticket timeline) does not exist.** `GET /api/v1/tickets/{ticket}/activities` and every timeline component are E7-S2. Task 4 introduces the `Ticket::activities()` relation that TM-46 will need, and task 8's reopen badge is **not** a timeline entry — the same split Story 33 made for the resolution block.
- **Measured baseline, 2026-08-26, from `backend/`.** `composer test` → **101 tests, 98 passing, 3 failing**; `pint --test` exits `0`. Stories 32 and 33 move that to **2 failures** and roughly **+40 tests**. **Re-measure after Story 33 lands** — this story's baseline is Story 33's end state.
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No new composer or npm dependency, and no migration.** `ticket_activities.event` is `varchar(50)` (`…_create_ticket_activities_table.php:15`), so a new enum case needs no schema change, and the `(ticket_id, created_at)` index at **21** already scopes every query task 4 adds.

---

## Story Goal

A recurrence goes back onto the ticket it belongs to, with a written reason, and the page says plainly that this ticket has been round before.

1. Moving a ticket **into Reopened** requires a `reason` of 10–5000 characters; without it the move is a `422` and nothing changes.
2. The move writes **one** activity row whose event is `reopened` — not `status_changed` — carrying the reason under `meta.reason`.
3. `GET /api/v1/tickets/{ticket}` gains `data.reopen_count`.
4. The detail page badges a ticket whose current status is Reopened, and shows the reopen count whenever it is greater than zero — **including after the ticket has moved on**.

**Not in scope, and each belongs to a named story.** **No timestamp code** — Story 33 clears `resolved_at` and `closed_at` for every non-terminal target and this story adds nothing to `TicketTimestamps`. **No new endpoint** — reopening is a status transition and goes through Story 32's `POST /tickets/{ticket}/status`; see the decision below. **No graph change** — `resolved → reopened` and `closed → reopened` are already seeded, ungated, by Story 31. **No timeline** (TM-46), **no internal notes** (TM-47), **no reopen notification** (E8). **No reopen-rate reporting** — E9 measures what this story records. **No SLA reset, no priority change, no reassignment on reopen** — a reopened ticket keeps its assignee and its priority; if the product wants otherwise that is a new story. **No escalation** (TM-41).

---

## Context — Read These Files First

1. [`33-story-resolving-requires-a-resolution-note-TM-39.md`](33-story-resolving-requires-a-resolution-note-TM-39.md) — **tasks 1, 2, 3, 4, 8, 9 and 10, and its test 13.** Task 1's three `SLUG_` constants, task 2's `$resolving` branch, task 4's `array_filter` on `meta`, and task 10's `resolving` computed are each extended by exactly one sibling here. **Its `TicketTimestamps` (task 3) is read-only for this story.**
2. [`32-story-change-a-tickets-status-TM-38.md`](32-story-change-a-tickets-status-TM-38.md) — **tasks 1, 2, 3, 7, 8, 9 and 11, and its test 4.** Test 4 asserts *"exactly one `status_changed` row"* per change; task 3 below narrows that claim and adds the reopen case beside it.
3. [`31-story-transition-table-and-workflow-guard-TM-37.md`](31-story-transition-table-and-workflow-guard-TM-37.md) — the **edge table**, rows 10 and 11 (`resolved → reopened`, `closed → reopened`, both `required_role` null) and the decision section explaining why `closed → reopened` was **not** made admin-only: *"TM-40 is written for an agent."* **Read it before anyone proposes gating reopen.**
4. `backend/app/Models/Ticket.php` — the seven `BelongsTo` relations at **23–56**. **There is no `activities()` relation**; task 4 adds the first `HasMany` on this model. `casts()` at **18–21** already covers all three timestamps.
5. `backend/app/Models/TicketActivity.php` — `#[Fillable]` at **9**, `UPDATED_AT = null` at **12**, `casts()` at **14–17**. Story 33 adds a `user()` relation here; task 4 adds no more.
6. `backend/app/Enums/TicketActivityEvent.php` — `Created` and `CategoryChanged` at **7–8**, plus `StatusChanged` from Story 32. **Append only.** Stories 23, 24, 26 and 29 each append one too.
7. `backend/app/Services/ActivityRecorder.php:13–19` — `record()` takes the event as a parameter, so choosing between two enum cases costs nothing structural. **The `field` / `old_value` / `new_value` triple is filled identically for both**, which is what keeps a reopen queryable as a status change — see the decision below.
8. `backend/app/Http/Controllers/Api/V1/CategoryController.php:91–95` — the precedent for `meta` keys: `['reason' => 'category_deleted', 'from_name' => …, 'to_name' => …]`. **`meta.reason` is already this project's key for "why", and Story 29 (TM-34) uses the same key for a reassignment.** Task 3 does not invent a name.
9. `backend/app/Http/Resources/V1/TicketResource.php` — **lines 23–28** plus Story 32's `allowed_transitions` and Story 33's `resolution`. Task 5 adds a fifth `routeIs('tickets.show')` key beside them.
10. `backend/app/Http/Controllers/Api/V1/TicketController.php` — `show()` at **23–28**. **Story 32 deliberately left it unmodified; this story is the first to change it**, and task 4 explains why a count belongs there and not in the resource.
11. `frontend/src/api/statuses.ts` — 4 lines; `Status` carries `slug` (**3**). Story 33 adds `RESOLVED_SLUG`; task 6 adds `REOPENED_SLUG` beside it.
12. `frontend/src/components/TicketStatusDialog.vue` — Story 32's dialog as extended by Story 33's task 10 (`resolution` ref, `resolving` computed, `noteTooShort`). Task 9 adds the mirror-image trio for reopening **and** replaces the call signature; read Story 33's task 10 table before touching the test ids.
13. `frontend/src/views/TicketDetailView.vue:11` — one long template line. Story 33 adds the `ticket-resolution` block above `<CategoryBadge>`; task 10 adds the badge and the count beside the status `<ColorBadge>`.
14. `frontend/src/components/ColorBadge.vue` — **2 lines**, `{ name, color }`. The reopened badge **reuses it** with the reopened status's own seeded colour (`#EF4444`, `StatusSeeder.php:19`) rather than inventing a component.
15. `docs/api-contract.md` — the `## Status workflow` section (Story 31, extended by Story 33) and the `### POST /api/v1/tickets/{ticket}/status` subsection (Story 32, edited by Story 33). Task 11 edits both again and adds **no** endpoints-table row.

---

## Product rules (from story)

| Situation | Behaviour after Story 33 | New behaviour |
|---|---|---|
| Move into **Reopened** without `reason` | `422` — the field is `prohibited` naming TM-40 | `422` `required`, message asks what came back |
| `reason` shorter than 10 characters | `422` `prohibited` | `422` `min` |
| `reason` on any **other** move | `422` `prohibited` naming TM-40 | **Still `422` `prohibited`** — the message now names Reopened |
| The move itself | Legal from Resolved and Closed, ungated | **Unchanged** — Story 31 seeded both edges |
| `resolved_at` / `closed_at` on reopen | Already cleared by `TicketTimestamps` | **Unchanged.** This story writes no timestamp code |
| The activity row | One `status_changed` row | **One `reopened` row**, same `field` / `old_value` / `new_value`, plus `meta.reason` |
| Counting status changes | `event = 'status_changed'` | **`field = 'status_id'`** — the invariant that survives both events |
| `GET /tickets/{ticket}` | No reopen information | `data.reopen_count`, an integer, always present on the detail route |
| Detail page, status is Reopened | Plain status badge | **Plus** a `Reopened` badge |
| Detail page, reopened 2× then moved on | Nothing | **"Reopened 2 times"** still shown |
| Assignee, priority, escalation on reopen | Unchanged | **Still unchanged** — deliberately |

---

## Decision — reopening is a status transition, not a second endpoint

No `POST /api/v1/tickets/{ticket}/reopen`. The move goes through Story 32's status endpoint with `status_id` = the Reopened status and a `reason` in the body.

- **The graph already answers "may this ticket be reopened".** Story 31 exists so that question is data, and a separate endpoint would have to ask it a second way — or skip it, which is worse. A ticket in `New` would be reopenable through a dedicated route unless that route re-derived the graph.
- **The `422` messages, the lock, the transaction and the activity write are all already there.** A second endpoint duplicates `changeStatus()` almost line for line to change one enum case and one validation rule.
- **The SPA already has the control.** The Reopened option appears in `allowed_transitions` on a resolved or closed ticket with no client change at all; task 9 only adds the field the server now demands.

**Contrast with Story 26's `/assign` and Story 27's `/claim`, which are separate endpoints on purpose** — those change a field the status graph knows nothing about. Reopening changes `status_id`, so it belongs to the status endpoint.

## Decision — the reopen writes **one** row, and its event is `reopened`

Not a `status_changed` row with a reason, and not two rows.

- **AC3 says "a reopened activity row", singular.** Two rows for one transition makes the trail say a thing happened twice.
- **TM-46's fourth criterion is *"Each event type has its own icon and colour so the timeline can be scanned quickly."*** A reopen is the single most scannable event on a ticket's history; folding it into `status_changed` means the timeline must inspect `new_value` to know it happened.
- **AC4's count becomes exact and cheap**: `where event = 'reopened'`. Counting `status_changed` rows whose `new_value` matches the reopened status id ties the count to an id that is only stable because nothing deletes statuses.

**The cost, stated rather than hidden: "every status change is a `status_changed` row" stops being true.** The mitigation is deliberate and is task 3's main constraint — **the reopen row fills `field`, `old_value` and `new_value` exactly as `changeStatus()` fills them for every other move.** So the invariant that survives is:

> **Every status change writes exactly one row with `field = 'status_id'`.** Its event is `status_changed`, except a move into Reopened, whose event is `reopened`.

**Query status history by `field`, never by `event`.** Task 11 writes that sentence into the API contract, and test 6 pins it.

## Decision — 10–5000 characters, the same as a resolution note

Story 33 chose 10–5000 for `resolution`. The reopen reason uses the **same numbers**, and TM-41's escalation reason should too. One bound for every free-text justification in the workflow means a reader learns it once and no story has to defend its own figure. **10** rejects `broke again`… no — it accepts that (11) and rejects `again` (5), `same issue` (10 exactly — accepted), `nope` (4). That is the intended line.

## Decision — the badge and the count are two different signals

- **`ticket-reopened-badge` renders when the *current status* is Reopened.** It answers "what is this ticket now".
- **`ticket-reopen-count` renders whenever `reopen_count > 0`**, including on a ticket that has since moved to In Progress or been closed again. It answers "has this ticket been round before", which is the question AC4's *"how many times it has been reopened"* is really asking and which a status-only badge cannot answer.

Deriving the badge from `reopen_count > 0` was rejected: a ticket reopened last March and closed since is not *currently* reopened, and a badge saying so would be wrong.

---

## Backend Tasks

### 1 — A fourth slug constant

**File: `backend/app/Models/Status.php`** *(Story 33's task 1)*

Add beside the other three:

```php
    public const SLUG_REOPENED = 'reopened';
```

Story 33's rule stands: **after this story, `backend/app` contains no other literal `'reopened'` used as a status slug.** Verification step 5 greps for it.

### 2 — Make `reason` conditional

**File: `backend/app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php`** *(Story 32's task 1, as rewritten by Story 33's task 2)*

Story 33 resolves the target slug into a `$resolving` boolean. Widen that to the slug itself and branch twice:

```php
    public function rules(): array
    {
        // One query for the target's slug; two rules read it. Rule::exists below
        // still proves the id, so an unknown id yields a null slug here, both
        // free-text fields fall to `prohibited`, and the exists rule produces
        // the error the caller actually needs to see.
        $slug = Status::query()->whereKey($this->integer('status_id'))->value('slug');

        return [
            'status_id' => ['required', 'integer', Rule::exists('statuses', 'id')],
            'resolution' => $slug === Status::SLUG_RESOLVED
                ? ['required', 'string', 'min:10', 'max:5000']
                : ['prohibited'],
            'reason' => $slug === Status::SLUG_REOPENED
                ? ['required', 'string', 'min:10', 'max:5000']
                : ['prohibited'],
        ];
    }
```

Replace the `reason.prohibited` message Story 32 wrote and add two:

```php
            'reason.required' => 'Say what brought this ticket back before reopening it.',
            'reason.min' => 'The reopen reason must be at least 10 characters.',
            'reason.prohibited' => 'A reason belongs only on a move into Reopened.',
```

- **The two branches are mutually exclusive by construction** — no seeded status is both Resolved and Reopened — so a body carrying **both** fields is always a `422` on at least one of them. Test 9 pins it.
- **`TrimStrings` already trims**, so `"   "` fails `required`, not `min`. Same measured behaviour Story 33 pins for `resolution`; **do not add a `prepareForValidation` trim.**

### 3 — The reopen event and the reason on the row

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append one case:

```php
    case Reopened = 'reopened';
```

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`** *(Story 32's task 2, as extended by Story 33's task 4)*

Inside the existing `DB::transaction` closure, after `$ticket->save()`, replace the single `$recorder->record(...)` call with:

```php
            // One row per transition. `field`, `old_value` and `new_value` are
            // filled identically whichever event this is, so status history is
            // queried by `field = 'status_id'` and never by event -- see the
            // API contract's Status workflow section.
            $reopening = $target->slug === Status::SLUG_REOPENED;

            $recorder->record($ticket->getKey(), $reopening ? TicketActivityEvent::Reopened : TicketActivityEvent::StatusChanged, [
                'user_id' => $actor->getKey(),
                'field' => 'status_id',
                'old_value' => (string) $from->getKey(),
                'new_value' => (string) $target->getKey(),
                'meta' => array_filter([
                    'from_name' => $from->name,
                    'to_name' => $target->name,
                    'resolution' => $request->validated('resolution'),
                    'reason' => $request->validated('reason'),
                ], fn ($value): bool => $value !== null),
            ]);
```

Add `use App\Models\Status;` if Story 33 has not already.

- **`field` stays `'status_id'` on the reopen row.** This is the whole mitigation for the decision above; **changing it to `'reopened'` or null silently breaks status auditing** and test 6 is what catches it.
- **`resolution` and `reason` can never both be present** (task 2), so `array_filter` yields exactly one of them or neither.
- **Nothing else in the closure changes** — not the lock, not `$from`'s read position, not `TicketTimestamps::apply()`, not `save()`.

### 4 — The `activities()` relation and the count on `show()`

**File: `backend/app/Models/Ticket.php`**

Add after `escalatedBy()` (**ends at 56**), with `use Illuminate\Database\Eloquent\Relations\HasMany;`:

```php
    /** @return HasMany<TicketActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }
```

Docblock shape copied from `Requester::tickets()` (`backend/app/Models/Requester.php:36–40`). **TM-46's timeline endpoint needs this relation too** — it is added here rather than duplicated there.

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

`show()` gains one chained call:

```php
        return TicketResource::make($ticket->load([...])->loadCount(['activities as reopen_count' => fn ($query) => $query->where('event', TicketActivityEvent::Reopened)]))->response();
```

**Why here and not in the resource, unlike `allowed_transitions` and `resolution`:** both of those depend on the **caller** — one on their role, one on a trail lookup keyed to `resolved_at`. `reopen_count` is a plain aggregate of a relation the model should have anyway, and `loadCount` is the framework's answer to exactly that. **Story 32 left `show()` untouched; this is the first story to change it, and this is the reason.**

**Do not add `loadCount` to `changeStatus()`'s response** — `reopen_count` is gated to the detail route like every other per-caller key.

### 5 — `data.reopen_count` on the detail response

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Add one key after Story 33's `resolution`:

```php
            'reopen_count' => $this->when($request->routeIs('tickets.show'), fn (): int => (int) ($this->reopen_count ?? 0)),
```

**The `?? 0` is load-bearing**: `loadCount` sets the attribute only on the show route, and a future caller that renders this resource without it must get `0`, not a 500 on a missing attribute. **`(int)` cast** because MySQL returns the count as a string through some drivers and the SPA's type says `number`.

---

## Frontend Tasks

### 6 — The slug constant and the type

**File: `frontend/src/api/statuses.ts`** *(Story 33's task 8)*

Add beside `RESOLVED_SLUG`:

```ts
export const REOPENED_SLUG = 'reopened'
```

**File: `frontend/src/api/tickets.ts`**

Add `reopen_count: number` to `TicketDetail` — **not** to `Ticket`. It is absent from the status-change response and from `POST /tickets`, and the type must say so.

### 7 — One options object instead of a growing tail of optional strings

**File: `frontend/src/api/tickets.ts`** *(supersedes Story 33's task 8 signature)*

Story 33 widened `changeTicketStatus(id, statusId, resolution?)`. A fourth positional `reason?` would make the reopen call read `changeTicketStatus(id, s, undefined, reason)` — two adjacent optional strings, which is a defect waiting for the first person who counts commas wrong. **Replace it with one object:**

```ts
export interface StatusChangePayload { resolution?: string; reason?: string }
export async function changeTicketStatus(id: number, statusId: number, extra: StatusChangePayload = {}): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/status`, { status_id: statusId, ...extra }); return data.data }
```

**The spread still omits absent keys**, which is the property Story 33's plan depends on: both fields are `prohibited` on moves they do not belong to, and a literal `null` in the body is a `422`. **Never build the payload with `resolution: resolution ?? null`.**

**File: `frontend/src/stores/tickets.ts`** *(supersedes Story 33's task 9 signature)*

```ts
async function changeStatus(id: number, statusId: number, extra: StatusChangePayload = {}): Promise<void> {
```

passing `extra` straight through. **Everything else in that action is unchanged**, including the `loadTicket(id)` re-read and both of Story 32's comments — the re-read is what makes the badge and the count appear.

**If Story 33 has already landed, this task is a three-call-site refactor**, and its `TicketStatusDialog.spec.ts` test 26 (which asserts the third argument) changes with it. **Record it in the PR description** so it does not read as an unrelated change.

### 8 — The reason field in the status dialog

**File: `frontend/src/components/TicketStatusDialog.vue`** *(Story 32's task 9, as extended by Story 33's task 10)*

Add the mirror image of Story 33's resolution trio. Import `REOPENED_SLUG`.

```ts
const reason = ref('')
const reopening = computed(() => props.ticket.allowed_transitions.find((status) => status.id === selected.value)?.slug === REOPENED_SLUG)
const reasonTooShort = computed(() => reopening.value && reason.value.trim().length < 10)
```

`confirm()` builds the object once:

```ts
    await store.changeStatus(props.ticket.id, selected.value, {
      ...(resolving.value ? { resolution: resolution.value } : {}),
      ...(reopening.value ? { reason: reason.value } : {}),
    })
```

| Element | `data-testid` | Notes |
|---|---|---|
| Reason textarea | `ticket-status-reason` | `v-if="reopening"`, `v-model="reason"`, placeholder **"What brought this ticket back?"** |
| Reason hint | `ticket-status-reason-hint` | `v-if="reasonTooShort"` — **"At least 10 characters."** |
| Reason server error | `ticket-status-reason-error` | `v-if="errors.reason"`, renders `errors.reason[0]` |
| Confirm | `ticket-status-confirm` | `:disabled` gains `|| reasonTooShort` |

- **`resolving` and `reopening` are never both true** — one status id, one slug. **Do not merge them into a single "justification" field**: the two prompts ask genuinely different questions, and TM-41's escalation reason lives in its own dialog on its own endpoint, so there is no third case coming here.
- **`reason` is not cleared when the selection changes**, exactly as Story 33 specifies for `resolution`, and for the same reason.
- **Nothing else in the dialog changes** — same `errors` / `error` refs, same two-step `validationErrors()` → `errorMessage()` read, same "stays open on failure".

### 9 — The badge and the count on the detail page

**File: `frontend/src/views/TicketDetailView.vue`** *(Story 32's task 11, as extended by Story 33's task 11)*

Import `REOPENED_SLUG`. Add immediately after the status `<ColorBadge>` on line **11**:

```html
<ColorBadge v-if="store.current.status.slug === REOPENED_SLUG" data-testid="ticket-reopened-badge" name="Reopened" :color="store.current.status.color" /><p v-if="store.current.reopen_count > 0" data-testid="ticket-reopen-count">Reopened {{ store.current.reopen_count }} {{ store.current.reopen_count === 1 ? 'time' : 'times' }}</p>
```

- **`ColorBadge` is reused with the reopened status's own seeded colour** (`#EF4444`, `StatusSeeder.php:19`) — no new component, no hard-coded hex in the SPA.
- **The badge keys on the slug, the count on the number.** Two signals, per the decision above; test 24 asserts a ticket that was reopened and has since moved shows the count and **not** the badge.
- **Singular and plural are both handled.** "Reopened 1 times" is the kind of detail that makes a page look unfinished.
- **Story 33's `ticket-resolution` block and the three timestamp lines are untouched.** On a reopened ticket the resolution block disappears on its own, because `resolved_at` is null — Story 33's mechanism, not this story's.

---

## Documentation

### 10 — The API contract

**File: `docs/api-contract.md`**

In the `## Status workflow` section, after the paragraph Story 33 added, append:

```markdown
Moving into Reopened requires a `reason` of 10–5000 characters, rejected on any
other move. The move writes **one** activity row whose event is `reopened`
rather than `status_changed`, carrying the reason under `meta.reason`. Both rows
fill `field = 'status_id'` with the two status ids, so **status history is
queried by `field`, never by `event`**: every status change is exactly one row
with `field = 'status_id'`, and only its event name differs.

Reopening clears `resolved_at` and `closed_at` through the same non-terminal rule
as any other move out of a terminal status; there is no reopen-specific
timestamp code. A reopened ticket keeps its assignee, its priority and its
escalation level.
```

In the `### POST /api/v1/tickets/{ticket}/status` subsection, replace the sentence Story 33 left reading *"a `reason` field returns `422` naming TM-40"* with:

```markdown
A `reason` is required on a move into Reopened and rejected on every other move.
```

And extend the paragraph describing the detail response:

```markdown
`data.reopen_count` is the number of `reopened` activity rows on the ticket. It
is always present on the detail route, is `0` for a ticket that has never been
reopened, and survives the ticket moving on from Reopened.
```

**No new row in the endpoints table** — this story adds no endpoint.

---

## Edge Cases & Failure Modes

- **Reopening with no `reason`** → `422` `Say what brought this ticket back before reopening it.` under `errors.reason`; the status does not move, no activity row is written, and `resolved_at` / `closed_at` are untouched. Test 2.
- **Reopening with `"   "`** → `TrimStrings` makes it `""`, so it fails `required`, not `min`. Test 3.
- **A 9-character reason** → `422` `min`. A 5001-character one → `422` `max`. Tests 4 and 5.
- **A `reason` on a non-reopening move** → `422` `A reason belongs only on a move into Reopened.` The client never sends it (task 8 spreads conditionally), so this fires only on a hand-rolled request. Test 8.
- **A body carrying both `resolution` and `reason`** → always a `422`, because at most one of the two branches in task 2 can be the required one and the other is `prohibited`. Test 9.
- **Reopening a ticket that is not terminal** → rejected by `TicketWorkflow` before validation of the reason is even relevant: no seeded edge leads into `reopened` from an active status, so `A ticket cannot move from Open to Reopened.` **The reason rule and the graph are independent guards and both are tested.** Test 10.
- **Reopening a ticket already in Reopened** → `This ticket is already Reopened.` from the same-status branch, **not** a reason error. Test 11.
- **The reopen row's `field`** → `'status_id'`, identical to every other move. **Test 6 is the one that fails if someone "improves" it**, and it is the only thing keeping status auditing whole after the event split.
- **A reopen writes no `status_changed` row** → Story 32's test 4 asserts exactly one `status_changed` row per change; that claim is now *"per non-reopen change"*. **Amend Story 32's test 4 rather than leaving it to fail**, and add test 7 for the reopen case.
- **`resolved_at` and `closed_at` on reopen** → cleared by Story 33's `TicketTimestamps`, which this story does not touch. **Test 12 asserts it anyway**, at this story's boundary, because AC2 is this story's acceptance criterion even though the code is Story 33's.
- **A reason containing `<script>` or Arabic** → stored **verbatim** (`ActivityRecorder.php:34`, `JSON_UNESCAPED_UNICODE`, utf8mb4 containers). Escaping happens at render, in Vue's `{{ }}`, exactly as Story 33 rules for the resolution note. **The reason is not rendered anywhere in this story** — TM-46's timeline is its first reader — so test 13 asserts only the round-trip.
- **`reopen_count` on a never-reopened ticket** → `0`, and the key is **present**, so the SPA's `number` type is honest and `reopen_count > 0` is safe. Test 15.
- **`reopen_count` after the ticket moves on** → unchanged; the count is over the trail, not the current status. **This is what the count is for.** Tests 16 and 24.
- **A ticket reopened, closed and reopened again** → `reopen_count` is `2`, two `reopened` rows, each with its own reason, and `data.resolution` is `null` because `resolved_at` was cleared by the second reopen. Test 17.
- **Query cost** → **+1 always on `tickets.show`**, from `loadCount`. Together with Story 32's `allowed_transitions` (+2 always) and Story 33's `resolution` (+2 on a resolved ticket only), the detail of a resolved ticket is Story 32's base **+5**, and of an unresolved one **+3**. Test 18 pins both.
- **`reopen_count` absent from the status-change response and from `POST /tickets`** → guarded by the `routeIs('tickets.show')` gate; test 19.
- **A soft-deleted ticket's activity rows** → preserved (`ON DELETE CASCADE` never fires on a soft delete), so a restored ticket keeps its count. TM-48's third criterion covers this explicitly; nothing here changes it.
- **Two agents reopen at once** → they serialise on Story 32's `lockForUpdate()`; the second is re-checked against Reopened and gets `This ticket is already Reopened.` **Exactly one `reopened` row, so the count cannot double-increment.** No new code.
- **An agent reopens a ticket assigned to someone else** → allowed, and the assignee is unchanged. `TicketPolicy::changeStatus()` admits all staff and the graph gates neither reopen edge. **Recorded as intended, not overlooked** — if reopening should notify or reassign, that is TM-52 and a new story.

---

## Test Plan

**Every test below is added to files Stories 32 and 33 created.** Two existing assertions are **amended, not duplicated**:

- Story 32's `test_the_change_writes_one_status_changed_row` → narrow its name and body to a **non-reopen** move (`new → open` already is one; only the docblock/name needs to say so). Test 7 covers the reopen case.
- Story 33's `test_reason_is_still_prohibited` → **superseded** by tests 2–5 and 8. Delete it; `reason` is no longer unconditionally prohibited.

### Backend — `backend/tests/Feature/Tickets/TicketStatusTest.php` (modified)

1. `test_reopening_with_a_reason_succeeds` — **AC1.** `closed → reopened` as an agent with a 20-character reason → `200`, `data.status.slug` is `reopened`. **As an agent, deliberately** — Story 31 left both reopen edges ungated for exactly this.
2. `test_reopening_without_a_reason_is_rejected` — `422`, `assertJsonPath('errors.reason.0', 'Say what brought this ticket back before reopening it.')`; status unchanged, **no activity row**, `closed_at` still set.
3. `test_a_whitespace_only_reason_is_rejected_as_required` — `"   "` → the **`required`** message, not `min`.
4. `test_a_short_reason_is_rejected` — 9 characters → `The reopen reason must be at least 10 characters.`
5. `test_an_overlong_reason_is_rejected` — 5001 characters → `422` `max`.
6. `test_the_reopen_row_is_a_status_change_by_field` — **the invariant, and the most load-bearing test in this story.** After a reopen, exactly one row exists with `field = 'status_id'` for that move; its `old_value` and `new_value` are the two status ids. **Change `field` in task 3 and confirm this fails.**
7. `test_the_reopen_row_uses_the_reopened_event_and_carries_the_reason` — **AC3.** That row's `event` is `reopened`, `meta.reason` is the reason verbatim, `meta.from_name` / `meta.to_name` are present, `user_id` is the agent, and **no `status_changed` row was written for this move.**
8. `test_a_reason_on_a_non_reopening_move_is_rejected` — `new → open` with a reason → `422`, `A reason belongs only on a move into Reopened.`
9. `test_a_body_with_both_a_resolution_and_a_reason_is_rejected` — on a move into Resolved **and** on a move into Reopened; `422` both times, on the field that does not belong.
10. `test_reopening_a_non_terminal_ticket_is_rejected_by_the_graph` — `open → reopened` with a valid reason → `422` `A ticket cannot move from Open to Reopened.` **Proves the two guards are independent.**
11. `test_reopening_an_already_reopened_ticket_is_rejected_before_the_reason` — same-status move with a valid reason → `This ticket is already Reopened.`
12. `test_reopening_clears_both_timestamps` — **AC2, asserted at this story's boundary even though Story 33 owns the code.** Resolve, close as an admin, reopen: `resolved_at` and `closed_at` both null, `data.resolution` is `null`, and **the `status_changed` row carrying the resolution still exists.**
13. `test_a_reason_is_stored_verbatim` — one reason with `<script>alert(1)</script>`, one in Arabic; both read back byte-identical from `meta.reason`.
14. `test_reopening_leaves_assignee_priority_and_escalation_untouched` — assign the ticket and set `escalation_level` directly first. **Pins the deliberate non-behaviour**; without it, the first person to think reopening should unassign will just do it.
15. `test_reopen_count_is_zero_and_present_on_a_fresh_ticket` — **AC4's server half.** `show()` → `data.reopen_count` is `0` and `assertJsonPath` finds the key.
16. `test_reopen_count_survives_the_ticket_moving_on` — reopen, then move to In Progress; `data.reopen_count` is still `1`.
17. `test_reopen_count_counts_every_reopen` — resolve, reopen, resolve, reopen → `2`; two `reopened` rows with **different** reasons, each intact.
18. `test_show_query_cost_with_reopen_count` — `GET /tickets/{id}` costs exactly Story 33's pinned number **+1** on both an unresolved and a resolved ticket. Both figures in the PR.
19. `test_reopen_count_is_absent_from_other_responses` — `assertJsonMissingPath('data.reopen_count')` on the status-change response and on `POST /tickets`.
20. `test_the_reopen_is_atomic` — bind a throwing `ActivityRecorder` on a reopen; the exception propagates and `status_id`, `resolved_at` and `closed_at` are all unchanged. Extends Story 33's test 19 to the reopen path.

### Frontend — `frontend/src/components/TicketStatusDialog.spec.ts` (Story 33's file; modified)

21. Selecting Reopened reveals `ticket-status-reason` and **not** `ticket-status-resolution`; selecting Resolved does the reverse; selecting anything else shows neither.
22. `ticket-status-confirm` is disabled while the reason is under 10 characters, with `ticket-status-reason-hint` visible, and enabled at 10.
23. Confirming a reopen calls `changeTicketStatus(id, reopenedId, { reason })` — **assert the object, and assert it has no `resolution` key.** Confirming a resolve passes `{ resolution }` with no `reason`; confirming an ordinary move passes `{}`. **This is the test that replaces Story 33's test 26** when task 7's signature change lands.
24. A `422` of `{"errors": {"reason": ["The reopen reason must be at least 10 characters."]}}` renders that sentence in `ticket-status-reason-error` and keeps the dialog mounted.
25. Typing a reason, switching to another option and switching back keeps it.

### Frontend — `frontend/src/views/TicketDetailView.spec.ts` (Story 32's file, extended by Story 33; modified)

26. **AC4's client half.** A ticket whose `status.slug` is `reopened` renders `ticket-reopened-badge`; one in any other status does not.
27. A ticket with `reopen_count: 2` and a status of `in-progress` renders `ticket-reopen-count` reading **"Reopened 2 times"** and **no badge** — the two-signal rule.
28. `reopen_count: 1` reads **"Reopened 1 time"**, singular.
29. `reopen_count: 0` renders **no** `ticket-reopen-count` element.

---

## Verification Steps

1. **Gate:** from `backend/`, `php artisan test --filter=TicketStatusTest` → green **before** you change anything, with Story 33's `test_reopening_clears_both_timestamps` among the passes. If it is missing, Story 33 has not landed.
2. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
3. **Backend formats:** `./vendor/bin/pint --test` → exit `0`.
4. **Backend tests:** `composer test`. Expect **+19 tests** over Story 33's end state (20 added, 1 deleted, 1 renamed) and the **same 2** pre-existing failures.
5. **Prove the constants are the only copy:** `grep -rn "'reopened'" backend/app` → the single hit is `Status::SLUG_REOPENED`. Any other is a slug that escaped task 1.
6. **Prove test 6 earns its place:** change `'field' => 'status_id'` to `'field' => 'reopened'` in task 3's recorder call, re-run `--filter=test_the_reopen_row_is_a_status_change_by_field`, confirm it **fails**, restore. **This is the demonstration that the event split did not break status auditing.**
7. **Prove the graph and the reason rule are independent:** temporarily give the `reason` rule `'sometimes'` instead of `'required'`, re-run `--filter=test_reopening_without_a_reason_is_rejected` (fails) and `--filter=test_reopening_a_non_terminal_ticket_is_rejected_by_the_graph` (**still passes**), restore.
8. **Backend by hand.** `php artisan serve`, agent token, a ticket taken to Resolved:
   - `POST …/status -d '{"status_id":<reopened>}'` → `422` `Say what brought this ticket back before reopening it.`; `SELECT resolved_at FROM tickets WHERE id=<id>` → **still set**.
   - `-d '{"status_id":<reopened>,"reason":"again"}'` → `422` `min`.
   - `-d '{"status_id":<reopened>,"reason":"The same disk failed a second time."}'` → `200`.
   - `SELECT event, field, old_value, new_value, meta FROM ticket_activities WHERE ticket_id=<id> ORDER BY id DESC LIMIT 1` → `event` is **`reopened`**, `field` is **`status_id`**, both ids present, `meta.reason` is the sentence.
   - `SELECT count(*) FROM ticket_activities WHERE ticket_id=<id> AND field='status_id'` → counts **every** move including this one.
   - `SELECT resolved_at, closed_at FROM tickets WHERE id=<id>` → **both NULL**.
   - `GET …/tickets/<id>` → `data.reopen_count` is `1`, `data.resolution` is `null`, `data.status.slug` is `reopened`.
   - Move to In Progress → `data.reopen_count` is **still `1`**.
   - `-d '{"status_id":<open>,"reason":"x whatever"}'` → `422` `A reason belongs only on a move into Reopened.`
   - `-d '{"status_id":<resolved>,"resolution":"Fixed it properly.","reason":"Also this."}'` → `422`.
   - On an **Open** ticket, `-d '{"status_id":<reopened>,"reason":"valid enough"}'` → `422` `A ticket cannot move from Open to Reopened.`
9. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+9 tests** across two existing spec files. Then `npx prettier --check src/api/statuses.ts src/api/tickets.ts src/stores/tickets.ts src/components/TicketStatusDialog.vue src/views/TicketDetailView.vue src/components/TicketStatusDialog.spec.ts src/views/TicketDetailView.spec.ts`.
10. **Frontend by hand:** `npm run dev`, as an **agent**, on a ticket you have taken to Resolved.
    - Open the dialog → **Reopened** is offered (Closed is not, for an agent). Select it → the **reason** field appears and the **resolution** field does not.
    - Type `again` → Confirm disabled, hint visible. Type a sentence → enabled.
    - Switch to another option and back → the reason is still there.
    - Confirm → the dialog closes, the status badge changes, the **Reopened badge** appears, **"Reopened 1 time"** appears, the **Resolution block disappears**, and the resolved line disappears — **all without a reload.** *(AC2, AC3, AC4.)*
    - Move the ticket to In Progress → the badge goes, **"Reopened 1 time" stays**.
    - Take it back through Resolved and reopen again → **"Reopened 2 times"**, plural.
    - Check the assignee line before and after a reopen → **unchanged**.
11. **Regression:** run a plain `open → in-progress` change and confirm no reason field ever appeared, the request body carried neither `resolution` nor `reason`, and the activity row's event is still `status_changed`. Then confirm `git status` shows **no change** to `TicketWorkflow.php`, `TicketTimestamps.php`, `StatusTransitionSeeder.php`, `TicketPolicy.php`, `routes/api.php` or any migration.

---

## Done Criteria

- [ ] A move into Reopened without a `reason` of 10–5000 characters returns `422` under `errors.reason`; the status does not move, no activity row is written, and neither timestamp is cleared.
- [ ] Whitespace-only is rejected as **`required`**, not `min`, with no `trim` added — `TrimStrings` already does it.
- [ ] A `reason` on any non-reopening move returns `422`; a body carrying **both** `resolution` and `reason` always returns `422`.
- [ ] The graph and the reason rule are **independent guards** — `open → reopened` is refused by `TicketWorkflow` even with a valid reason, proven by reverting the rule and watching only one test fail.
- [ ] **No new edge, no seeder change, and neither reopen edge is role-gated** — Story 31 left `closed → reopened` open to agents on purpose and this story keeps it that way.
- [ ] Reopening writes **exactly one** activity row, `event = 'reopened'`, with `meta.reason` verbatim and **no `status_changed` row for that move**.
- [ ] That row still carries **`field = 'status_id'`** and both status ids, so status history is queryable by `field` regardless of event — proven by a test that fails when `field` changes.
- [ ] Story 32's `test_the_change_writes_one_status_changed_row` is **narrowed** to non-reopen moves and Story 33's `test_reason_is_still_prohibited` is **deleted**, not left alongside.
- [ ] `resolved_at` and `closed_at` are cleared on reopen by **Story 33's** `TicketTimestamps`; **`git status` shows no change to that file** and this story adds no timestamp code.
- [ ] Reopening leaves the assignee, the priority and the escalation level untouched — asserted, not assumed.
- [ ] `Ticket::activities()` exists as the model's first `HasMany`, and `show()` uses `loadCount` — **one** extra query, always, with the reason for putting it in the controller rather than the resource recorded.
- [ ] `GET /api/v1/tickets/{ticket}` returns `data.reopen_count` as an integer, present and `0` on a never-reopened ticket, correct after repeated reopens, and **absent** from the status-change response and from `POST /tickets`.
- [ ] `tickets.show` costs exactly **one** more query than Story 33's pinned number, on both a resolved and an unresolved ticket, with both figures in the PR.
- [ ] `Status::SLUG_REOPENED` is the only occurrence of that slug in `backend/app`, verified by grep.
- [ ] `changeTicketStatus` and `store.changeStatus` take **one options object**, not a tail of optional strings; the payload spread still omits absent keys and never sends a literal `null`. The three-call-site refactor of Story 33's signature is recorded in the PR.
- [ ] The dialog shows the reason field **only** on a move into Reopened, never alongside the resolution field, keeps the typed reason across selection changes, and renders the server's message when the client check is bypassed.
- [ ] The detail page badges a ticket whose **current status** is Reopened, and separately shows **"Reopened N time(s)"** whenever the count is above zero — including after the ticket has moved on — with correct singular and plural.
- [ ] `docs/api-contract.md` documents the reason rule, the `reopened` event, the **query-by-`field`** invariant, and `data.reopen_count` — with **no new endpoints-table row**.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+19 backend and +9 frontend tests** over Story 33's end state; **2** pre-existing backend failures remain, both named.
- [ ] No new endpoint, no timeline, no notification, no reassignment or priority change on reopen, no new dependency, no migration.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 35 (TM-41, escalate a ticket).**
