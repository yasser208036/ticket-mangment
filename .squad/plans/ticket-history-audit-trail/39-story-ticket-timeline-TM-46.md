# Story 39 — Ticket timeline (Story: TM-46)

## Prerequisites

- **Story 38 (TM-45) must land first, and this story is the reason it exists.** Story 38's closing line names this story by number. Two of its deliverables are load-bearing here: `Ticket::activities()` (its task 3, `backend/app/Models/Ticket.php`) and `TicketActivity::user()` with the docblock that states **null means the system acted and no consumer may substitute a name**. **Verify both before starting:** `grep -n "function activities" backend/app/Models/Ticket.php` and `grep -n "function user" backend/app/Models/TicketActivity.php`. If either is absent, Story 38 has not landed — **stop and run it**, do not add the relations here. Stories 33 and 34 also claim them; they must exist exactly once.
- **Story 38 also fixes `recordMany()`'s defaults, and this story depends on that fix for correctness.** Before it, an omitted `meta` stores the literal string `"null"` in the `json` column, which casts back to PHP `null`. Task 2's resource therefore coerces `meta` with `?? []` **regardless**, because rows written before that fix may already be in a developer's database. See the edge cases.
- **Only two events exist in the codebase today.** `backend/app/Enums/TicketActivityEvent.php` (14 lines) has `Created = 'created'` (**line 7**) and `CategoryChanged = 'category_changed'` (**line 8**). **Nine more are planned and none is implemented:** `status_changed` (Story 32), `updated` (Story 23), `assigned` (Story 26), `claimed` (Story 27), `unassigned` (Story 29), `reopened` (Story 34), `escalated` (Story 35), `deleted` (Story 24), `note_added` (TM-47). AC4 asks for an icon and colour **per event type**. **Read the rendering decision below before writing a single `switch` statement** — hard-coding eleven cases, nine of which cannot occur, is the failure mode this plan is written to prevent.
- **There is no `TicketFactory` and no `RequesterFactory`.** `backend/database/factories/` contains **only `UserFactory.php`**; TM-59 owns the rest. Backend tests build a ticket by hand — `$this->seed()` for master data, then `Requester::create(...)` and `Ticket` assigned field by field. Task 6 gives the exact helper.
- **`RouteAuthorizationTest::test_every_api_route_is_classified` is already red, and this story adds a route to the table it guards.** `self::ACCESS` (`backend/tests/Feature/Authorization/RouteAuthorizationTest.php:16`) has **no `tickets.store` key** — Story 38's baseline names this as failure 2 of 3, owned by Story 32. Task 5 adds `tickets.activities`. **It must also add `tickets.store`**, because `test_every_api_route_is_classified` (**18–25**) fails on the first unclassified route and `tickets.store` (`routes/api.php:44`) is registered **before** the new route — leave it out and the new classification is never actually asserted. See the decision.
- **`docs/api-contract.md` documents no ticket endpoint at all.** The file is **98 lines**; the endpoints table (**32–47**) jumps from `/categories` to `/admin/users`. `GET /api/v1/tickets/{ticket}` and `POST /api/v1/tickets` are both shipped and both undocumented. Task 4 documents **only this story's endpoint** and the PR records the pre-existing gap as TM-22/TM-26's debt. Do not silently backfill two other stories' documentation.
- **`docs/erd.md` (79 lines) has no `ticket_activities` entity** — Story 38's task 4 adds it. Nothing in this story touches the ERD.
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No migration, no new column, no index change, no new composer package, no new npm package, no icon library.**

---

## What this story does not build, and who owns it

| Deferred | Owner |
|---|---|
| Append-more-on-demand (infinite scroll / "load older") | **TM-49** (E7-S5), first criterion |
| Filtering the timeline by event type | **TM-49**, second criterion |
| The `note_added` event and the note composer | **TM-47** (E7-S3) |
| Blocking updates and deletes on `TicketActivity` | **TM-48** (E7-S4) |
| Any new event case in `TicketActivityEvent` | Stories 23, 24, 26, 27, 29, 32, 34, 35 — each appends its own |
| Wiring the disabled toolbar buttons | TM-27, TM-31, TM-38, TM-41 |
| The `ticket-resolution` block on the detail page | **Story 33 (TM-39)** — see the decision; this story does **not** remove or duplicate it |

**AC1 says "paginated" and TM-49 says "appends more on demand".** This story ships the paginated **endpoint** in full — `data`, `links`, `meta`, a validated `per_page` — and renders the **first page** with the total count and a plain statement when more exist. The append interaction is TM-49's first criterion, by name. **Do not build a "Load more" button here**; do expose `meta.total` and `meta.last_page` so TM-49 has nothing to re-plan.

---

## Story Goal

A ticket's history stops being a table only the database can read and becomes the first thing an agent scans on the detail page.

1. `GET /api/v1/tickets/{ticket}/activities` returns a ticket's activities **newest first**, paginated, with a deterministic tie-break — the audit trail's first reader.
2. Every entry reads as a sentence: *"Ahmed changed category from Hardware to Software"*, composed from structured fields rather than a string the backend guessed at.
3. The old and new **names** are shown, not the raw foreign keys the table actually stores.
4. Each event carries its own icon and colour, from **one map with a working fallback**, so the eight events that do not exist yet render correctly the day they land.
5. A system row — `user_id` null — reads "System" and is visually distinct, honouring the contract Story 38 wrote into `TicketActivity::user()`'s docblock.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Reading a ticket's history over HTTP | No endpoint exists | `GET /api/v1/tickets/{ticket}/activities`, newest first, paginated |
| Two rows sharing a `created_at` second | Undefined order | **Deterministic**: `created_at DESC, id DESC` |
| `old_value` / `new_value` on a field change | Stringified foreign keys, meaningless to a reader | Raw ids **and** resolved labels from `meta.from_name` / `meta.to_name` |
| `user_id` null | Documented as "the system", unread | Renders **"System"**, entry marked `data-system="true"` |
| An event value the frontend has never seen | No frontend exists | Falls back to a neutral icon, a neutral colour and a generated sentence |
| `meta.reason` present | Stored, never shown | Rendered as a quoted line beneath the sentence |
| `meta` holding `<script>` or Arabic | Stored verbatim | Rendered verbatim through Vue `{{ }}`; **never `v-html`** |
| A ticket with no activities | N/A | An explicit empty state, not a blank region |
| Switching tickets mid-request | N/A | The stale response is discarded, not rendered on the wrong ticket |

---

## Context — Read These Files First

1. `backend/app/Services/ActivityRecorder.php` — **the whole file, 40 lines.** `record()` at **13–19** and its five defaults; `recordMany()` at **21–39**; **`$createdAt = now()` at 29 is assigned once and written to every row in the batch** — that is why task 1's ordering needs a tie-break. `TicketActivity::insert($chunk)` at **37** is the project's only write. **This file is not edited.**
2. `backend/app/Models/TicketActivity.php` — 18 lines. `#[Fillable]` at **9**, **`UPDATED_AT = null` at 12** (no `updated_at` column — the trail is append-only), `casts()` at **14–17**: `event` casts to `TicketActivityEvent`, `meta` casts to `array`. **Both casts matter to task 2.** Story 38 adds `user()` below **17**.
3. `backend/app/Http/Controllers/Api/V1/CategoryController.php:86–97` — `reassignTickets()`, and **the meta convention this story reads**: `field` at **93**, **stringified** ids in `old_value`/`new_value` at **94**, and human names under `meta.from_name` / `meta.to_name` at **95**. Story 32 (`meta.from_name`/`to_name` for statuses) and Story 26 (for assignees) repeat it exactly. **This is the contract task 2 normalises.**
4. `backend/app/Http/Controllers/Api/V1/TicketController.php` — `show()` at **23–28** (note `authorize('view', $ticket)` at **25** and the `load([...])` at **27**), `store()` at **30–50**, the `record()` call at **44** with `meta => ['reference' => …]`. **Task 1's controller copies `show()`'s authorize-then-respond shape; it does not touch this file.**
5. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php:19–38` — **the pagination precedent, copied field for field by task 1**: `AnonymousResourceCollection` as the return type (**19**), `$request->validate([...])` with `'per_page' => ['sometimes','integer','min:1','max:100']` (**22–26**), and `->paginate($filters['per_page'] ?? 15)->withQueryString()` (**35**). Task 1 defaults to **20**, not 15.
6. `backend/app/Http/Resources/V1/TicketResource.php` — **lines 19–21 are the exact idiom task 2's `actor` uses**: `$this->whenLoaded('assignee', fn () => ['id' => …, 'name' => …])`. **`whenLoaded` returns literal `null` when the relation is loaded and null**, which is precisely what AC5 needs. Line **29** is the `toIso8601String()` convention.
7. `backend/app/Policies/TicketPolicy.php` — `view()` at **15–18** returns `true` for every staff user. **Task 1 authorizes `view`; it adds no new ability.** See the decision.
8. `backend/routes/api.php:44–45` — `tickets.store` then `tickets.show`, both inside the `['auth:sanctum','active']` group opened at **32**. Task 3 adds one line after **45**.
9. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `self::ACCESS` at **16**, `test_every_api_route_is_classified` at **18–25**, and `routesFor()` at **121–124** (which resolves `{ticket}` but is never called for `'staff'`). **Read 18–25 to see why task 5 must add two keys, not one.**
10. `backend/tests/Feature/Database/RequestersTableSchemaTest.php` — the assertion style task 6 follows.
11. `frontend/src/api/users.ts:29–36` — `listUsers()`, **the paginated client precedent**: it returns `Paginated<T>` whole, not `data.data`. Contrast `frontend/src/api/tickets.ts:12`, `getTicket()`, which unwraps `data.data` because a single resource is wrapped. Task 7 follows `users.ts`.
12. `frontend/src/api/pagination.ts` — **the whole file, 18 lines.** `Paginated<T>` already exists with `data`, `links` and `meta`. **Do not redefine it.**
13. `frontend/src/stores/users.ts:19`, **30**, **34**, **39** — the `latestRequest` stale-response guard. **Task 9 copies it**, because `TicketDetailView.vue:9` watches `route.params.id` and can have two timeline requests in flight.
14. `frontend/src/stores/tickets.ts` — 12 lines. `loadTicket()` at **10**, the `return { … }` at **11**. Task 9 adds a third concern beside `creating` and the detail state; **it changes neither**.
15. `frontend/src/views/TicketDetailView.vue` — 11 lines. **Line 9** holds the whole script body: `const load = () => void store.loadTicket(...)`, `onMounted(load)`, `watch(() => route.params.id, load)`. **Line 11** is the entire template. Task 11 extends both.
16. `frontend/src/components/CategoryBadge.vue` — 27 lines. **Line 15's `:data-inactive="… ? undefined : 'true'"` plus the `[data-inactive='true']` rule at 24–26 is the exact idiom AC5 uses** for system entries. `readableTextColor` at **8**.
17. `frontend/src/lib/color.ts:10–16` — `readableTextColor(hex)`. Task 8 reuses it so each event's colour gets a legible foreground; **do not write a second contrast helper**.
18. `frontend/src/lib/relativeTime.ts` — one line, `relativeAge(iso)`, already used four times in `TicketDetailView.vue:11`. **AC3's "relative time" is this function.** Do not add a second.
19. `frontend/src/views/HealthView.spec.ts` — **the component-test precedent**: `vi.mock('../api/health', …)` at **9**, `createPinia()` in `global.plugins` at **20**, `flushPromises()`, and `data-testid` selectors. Task 13 follows it exactly.
20. `docs/api-contract.md` — conventions at **7–16** (**line 16 is the rule task 4 satisfies**: "List endpoints whose result set grows with usage return paginated `data`, `links`, and `meta` envelopes"), the endpoints table at **32–47**, per-endpoint sections from **49**.
21. Cross-story contracts this renderer must honour, all in planned-not-implemented files. **Read the named lines; each is a meta key this story reads:**
    - [`../ticket-creation-tracking/23-story-edit-a-ticket-TM-27.md`](../ticket-creation-tracking/23-story-edit-a-ticket-TM-27.md) **line 95** — a category change arrives as **two** events, `category_changed` and `updated` with `field = 'category_id'`; `meta.reason` distinguishes them (`'category_deleted'` vs `'edited'`).
    - [`../assignment-workload/29-story-reassign-or-unassign-with-a-reason-TM-34.md`](../assignment-workload/29-story-reassign-or-unassign-with-a-reason-TM-34.md) **line 293** — `meta.reason` is **omitted, not null**, when no reason was given, so `'reason' in meta` means a human wrote something. **Line 410** — escaping is this story's job, not the recorder's.
    - [`../assignment-workload/26-story-assign-a-ticket-to-an-agent-TM-31.md`](../assignment-workload/26-story-assign-a-ticket-to-an-agent-TM-31.md) **line 246** — `meta.from_name` is **null on a first assignment**, and the timeline reads that as "the queue".
    - [`../status-workflow-escalation/33-story-resolving-requires-a-resolution-note-TM-39.md`](../status-workflow-escalation/33-story-resolving-requires-a-resolution-note-TM-39.md) **line 84** — the detail page's `ticket-resolution` block and the timeline coexist; **this story does not remove it**.
    - [`../ticket-creation-tracking/24-story-soft-delete-a-ticket-TM-28.md`](../ticket-creation-tracking/24-story-soft-delete-a-ticket-TM-28.md) **line 104** — a `deleted` row snapshots `reference` and `subject` under `meta`.

---

## Decision — the backend returns structured fields, the frontend composes the sentence

AC2 wants prose. The tempting shortcut is a `description` string built in PHP. **Do not.**

- **AC4 forces a frontend event map anyway.** "Each event type has its own icon and colour" cannot be served by a string, so an event-keyed map has to exist in `frontend/src/lib/`. Composing the sentence anywhere else means **two** places that must both know every event.
- **Escaping is explicitly this story's job, in Vue.** Story 29's plan (line 410) rules that a reason with `<script>` is stored verbatim and *"escaping is the renderer's job, which is TM-46's"*. A PHP-composed sentence would force a decision about escaping inside the API payload; a structured payload plus Vue `{{ }}` makes the safe path the only path.
- **AC3 wants the values shown, not only narrated.** *"Old and new values are shown for field changes"* is a separate criterion from the prose. The payload must carry them as fields regardless, so the prose adds no data.

**What the backend does own** is resolving the ids. `old_value` and `new_value` hold **stringified foreign keys** (`CategoryController.php:94`); the readable names live under `meta.from_name` / `meta.to_name` (**95**). That convention was set by one story and copied by two more, and the frontend should not have to know it. Task 2's resource emits `from_label` / `to_label` — `meta.from_name` when present, the raw value otherwise — **and keeps `old_value` / `new_value` alongside**, so nothing is lost.

## Decision — one event map with a real fallback, not eleven hard-coded cases

Two events exist; nine are planned. A `switch` over eleven values would be **nine branches of unreachable code** asserted by no test, and every one of them would be a guess at another story's meta shape.

Task 8 ships:
- **An explicit map entry for the two events that exist**, `created` and `category_changed`, each with an icon path and a colour, both asserted by tests.
- **One fallback entry** — a neutral icon and colour — for everything else.
- **A sentence generator driven by the event *value's shape*, not by a list.** The rule is short and it produces AC2's own example verbatim:
  - `created` → *"created this ticket"*.
  - `<noun>_changed` → *"changed \<noun\>"*. So `status_changed` → *"changed status"*, and with labels → **"Ahmed changed status from Open to In Progress"** — the intake's example sentence, from a generic rule, for an event that does not exist yet.
  - `<noun>_added` → *"added \<noun\>"*. `note_added` → *"added note"*.
  - `updated` **with a `field`** → *"updated \<field\>"*. That is Story 23's second category-change path.
  - anything else → the event value with underscores as spaces. `claimed`, `reopened`, `escalated`, `unassigned`, `deleted` all read correctly.
  - **`" from {from_label} to {to_label}"` is appended whenever both labels are present**, for every branch.

**The measure of this design:** when Story 32 adds `StatusChanged`, the timeline renders *"changed status from Open to In Progress"* with an icon and a colour **without a single edit to this story's files**. Adding a bespoke icon later is one map entry. **A story that has to edit the timeline to add an event has broken this contract** — task 14's test pins it.

## Decision — `view` authorizes the timeline; no new policy ability

`TicketPolicy` has eight abilities (**10–48**). The timeline is part of reading a ticket, and `view()` (**15–18**) already returns `true` for every staff user. A ninth ability would be a second thing to keep in step with `view` for no behavioural difference, and `TicketResource`'s `can` block (**line 28**) would then owe it a key.

**`$this->authorize('view', $ticket)`, exactly as `TicketController::show()` does at line 25.** Route model binding on a `SoftDeletes` model excludes trashed rows, so a soft-deleted ticket's timeline is a `404` — see the edge cases.

## Decision — task 5 adds `tickets.store` to `ACCESS`, and says so in the PR

`test_every_api_route_is_classified` (**18–25**) loops `Route::getRoutes()` and `assertArrayHasKey` on each `api/v1` route. It **fails on the first miss**. `tickets.store` is registered at `routes/api.php:44`, before the new route, and is missing from `ACCESS` — so adding `tickets.activities` alone leaves the test red at the same line and **never asserts the new route at all**.

Adding `'tickets.store' => 'staff'` is a single key in a test constant. Story 32's plan adopts the same failure; **whichever lands first adds it, never twice.** Check with `grep -c "tickets.store" backend/tests/Feature/Authorization/RouteAuthorizationTest.php` before editing, and **record in the PR** that this story took Story 32's baseline failure 2 because its own route could not otherwise be tested. If Story 32 has already landed, add only `tickets.activities`.

---

## Backend Tasks

### 1 — The endpoint

**Create file: `backend/app/Http/Controllers/Api/V1/TicketActivityController.php`**

Single-action controller, matching `PriorityController` / `StatusController`'s invokable shape and `UserController::index()`'s pagination shape.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TicketActivityResource;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketActivityController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // created_at DESC alone is not deterministic: recordMany() stamps one
        // now() across a whole batch (ActivityRecorder.php:29), and the column
        // is second-resolution, so ties are normal. id DESC breaks them and
        // costs nothing -- the PK rides in the secondary index leaf.
        $activities = $ticket->activities()
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TicketActivityResource::collection($activities);
    }
}
```

**`->with('user')` is not optional.** Without it every row queries `users` and task 2's `whenLoaded('user', …)` drops the `actor` key instead of returning `null`. Test 9 pins the query count.

### 2 — The resource

**Create file: `backend/app/Http/Resources/V1/TicketActivityResource.php`**

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'field' => $this->field,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            // ticket_activities stores stringified foreign keys; the readable
            // names live under meta.from_name / meta.to_name, a convention set
            // by CategoryController::reassignTickets() and copied by TM-38 and
            // TM-31. Normalised here so the SPA never has to know it.
            'from_label' => $meta['from_name'] ?? $this->old_value,
            'to_label' => $meta['to_name'] ?? $this->new_value,
            'meta' => $meta,
            // null means the system acted -- a scheduled command or a cascade.
            // See TicketActivity::user()'s docblock. Never substitute a name.
            'actor' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

Three things about this file are deliberate and **must not be simplified**:

- **`$this->meta ?? []`.** The column is nullable, and a row written before Story 38's `recordMany()` fix holds the literal string `"null"`, which the `array` cast (`TicketActivity.php:16`) decodes to PHP `null`. Without the coalesce the next two lines throw on a real row in a real developer database.
- **`whenLoaded` and not `$this->user ? … : null`.** Laravel's `whenLoaded` returns literal `null` when the relation **is** loaded and **is** null, which is exactly AC5. It is also the idiom at `TicketResource.php:19–21`. It returns `MissingValue` — dropping the key entirely — when the relation is not loaded, which is why task 1 eager-loads and why test 4 asserts the key **exists**.
- **`'from_label'` sits beside `old_value`, it does not replace it.** AC3 asks for the values; the labels are for the sentence.

**Do not add an `is_system` boolean.** `actor === null` already says it, and two encodings of one fact drift.

### 3 — Route

**File: `backend/routes/api.php`**

Add the import beside the other `Api\V1` controllers (alphabetically, `TicketActivityController` before `TicketController`), and one line after **45**:

```php
    Route::get('/tickets/{ticket}/activities', TicketActivityController::class)->name('tickets.activities');
```

**Inside the existing `['auth:sanctum','active']` group opened at 32.** Not inside the `admin` group at **46** — agents are the primary reader. **Never write `/api/v1` into the path**; `bootstrap/app.php`'s `apiPrefix` supplies it.

### 4 — Document the endpoint

**File: `docs/api-contract.md`**

One row in the endpoints table, after the `/categories/{category}` rows (**43**) and before the `/admin/users` rows (**44**):

| Method | Path | Purpose | Auth | Owning story |
|--------|------|---------|------|--------------|
| `GET` | `/api/v1/tickets/{ticket}/activities` | Paginated audit trail for one ticket, newest first. | bearer (TicketPolicy `view`) | TM-46 |

and one section at the end of the file (after **98**):

```
### `GET /api/v1/tickets/{ticket}/activities`

Requires `Authorization: Bearer <token>`. Any active staff user may read any
ticket's timeline (`TicketPolicy::view`). Returns `200` with the paginated
`data` / `links` / `meta` envelope. `per_page` is optional, an integer between
1 and 100, default 20; anything else returns `422`. Ordering is
`created_at DESC, id DESC` -- the tie-break matters because `recordMany()`
stamps one timestamp across a whole batch. An unknown or soft-deleted ticket
returns `404`.

| Field | Type | Notes |
|---|---|---|
| `id` | int | Activity row id. Stable; rows are never updated (TM-48). |
| `event` | string | A `TicketActivityEvent` value. New cases are added by the story that records them. |
| `field` | string\|null | The changed column, e.g. `category_id`. Null for events that are not field changes. |
| `old_value` | string\|null | Raw previous value. A **stringified foreign key** for id fields. |
| `new_value` | string\|null | Raw new value, same encoding. |
| `from_label` | string\|null | `meta.from_name` when present, else `old_value`. What a human should read. |
| `to_label` | string\|null | `meta.to_name` when present, else `new_value`. |
| `meta` | object | Event-specific detail. Never null -- `{}` when empty. See the keys below. |
| `actor` | object\|null | `{id, name}`, or **`null` meaning the system acted**. Consumers render "System" and must never substitute a name. |
| `created_at` | string | ISO-8601. |

Known `meta` keys, each owned by the story that writes it: `reference`
(`created`, TM-22), `from_name` / `to_name` / `reason` (`category_changed`,
TM-18; `status_changed`, TM-38; `assigned`, TM-31), `resolution`
(`status_changed` into Resolved, TM-39), `reference` / `subject` (`deleted`,
TM-28). `reason` is **omitted rather than null** when no reason was given, so
its presence means a human wrote something.

Values are stored verbatim: no HTML escaping, no unicode escaping. **Escaping
is the renderer's obligation** -- the SPA renders every one of these through
Vue interpolation and never `v-html`.
```

**`GET /api/v1/tickets/{ticket}` and `POST /api/v1/tickets` are still absent from this file.** TM-26 and TM-22 shipped them undocumented. **Do not backfill them here** — record the gap in the PR description so it is owned rather than forgotten.

### 5 — Classify the route

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add to `self::ACCESS` (**16**): `'tickets.activities' => 'staff'`, and **`'tickets.store' => 'staff'`** unless it is already there (`grep -c "tickets.store" …` → `1` means Story 32 landed and only the first key is needed). Read the decision above for why both.

Add one assertion to `test_agent_reaches_staff_routes` (**52–58**) — it hard-codes URLs rather than using `routesFor('staff')`, so a new staff route is otherwise unexercised. It needs a real ticket id, which means the test class must build one; use task 6's helper, extracted to `Tests\TestCase` **only if** `test_agent_reaches_staff_routes` needs it. **Simpler and preferred:** leave that test alone and let task 6's own class cover the agent path, since it already builds a ticket. Add the `ACCESS` keys and nothing else.

### 6 — Backend tests

**Create file: `backend/tests/Feature/Activity/TicketTimelineTest.php`**

`RefreshDatabase` + `$this->seed()` for master data. **There is no `TicketFactory`** — the class needs this helper, because `tickets` requires `reference`, `requester_id`, `category_id`, `priority_id`, `status_id` and `created_by` (`…create_tickets_table.php:16–24`, all `restrictOnDelete`):

```php
    private function makeTicket(User $creator): Ticket
    {
        $requester = Requester::create(['name' => 'Rana', 'email' => 'rana@example.test']);
        $ticket = new Ticket(['subject' => 'Printer jams', 'description' => 'Every third page.']);
        $ticket->requester_id = $requester->getKey();
        $ticket->category_id = Category::query()->value('id');
        $ticket->priority_id = Priority::query()->where('is_default', true)->value('id');
        $ticket->status_id = Status::query()->where('is_default', true)->value('id');
        $ticket->created_by = $creator->getKey();
        $ticket->reference = 'TKT-2026-000001';
        $ticket->save();

        return $ticket;
    }
```

**Rows are inserted through `ActivityRecorder` inside `DB::transaction`**, not with `TicketActivity::create()` — Story 38's tripwire test fails the build if anything else writes to the table, and `RefreshDatabase`'s open transaction satisfies the recorder's guard (`ActivityRecorder.php:26–28`) so no extra wrapper is needed.

See the Test Plan for the numbered list.

---

## Frontend Tasks

### 7 — The client

**Create file: `frontend/src/api/activities.ts`**

```ts
import client from './client'
import type { Paginated } from './pagination'

export interface ActivityActor {
  id: number
  name: string
}

export interface TicketActivity {
  id: number
  event: string
  field: string | null
  old_value: string | null
  new_value: string | null
  from_label: string | null
  to_label: string | null
  meta: Record<string, unknown>
  actor: ActivityActor | null
  created_at: string
}

export async function listTicketActivities(
  ticketId: number,
  page = 1,
): Promise<Paginated<TicketActivity>> {
  const { data } = await client.get<Paginated<TicketActivity>>(
    `/tickets/${ticketId}/activities`,
    { params: { page } },
  )
  return data
}
```

**`event` is `string`, not a union.** A union would have to list eleven values, nine of them for events that do not exist, and would make TypeScript reject a payload the API legitimately sends the day Story 32 lands. The fallback in task 8 is what makes `string` safe. **Return `data` whole**, like `users.ts:29–36` — a paginated response is not wrapped twice.

### 8 — The event map and the sentence

**Create file: `frontend/src/lib/activityEvents.ts`**

One descriptor per known event, one fallback, no `switch`. Icon paths are inline `d` attributes on a 16×16 viewBox — **the repo has no icon library and this story adds none** (`frontend/public/icons.svg` was deleted).

```ts
export interface EventDescriptor {
  color: string
  path: string
}

const FALLBACK: EventDescriptor = {
  color: '#6B7280',
  path: 'M8 1a7 7 0 100 14A7 7 0 008 1zm0 3.2a.9.9 0 110 1.8.9.9 0 010-1.8zm.8 3.3v4.3H7.2V7.5h1.6z',
}

// One entry per event that exists in TicketActivityEvent today. Events added by
// TM-27, TM-28, TM-31, TM-32, TM-34, TM-38, TM-40, TM-41 and TM-47 render
// through FALLBACK until their own story adds an entry here -- adding one is a
// single line and requires no other change to the timeline.
const DESCRIPTORS: Record<string, EventDescriptor> = {
  created: { color: '#0F766E', path: 'M8 2v12M2 8h12' },
  category_changed: { color: '#7C3AED', path: 'M2 4h5l2 2h5v6H2z' },
}

export function eventDescriptor(event: string): EventDescriptor {
  return DESCRIPTORS[event] ?? FALLBACK
}
```

**Create file: `frontend/src/lib/activityProse.ts`**

```ts
import type { TicketActivity } from '../api/activities'

const SYSTEM_ACTOR = 'System'
const FIELD_LABELS: Record<string, string> = {
  category_id: 'category',
  status_id: 'status',
  priority_id: 'priority',
  assigned_to: 'assignee',
}

export function actorName(activity: TicketActivity): string {
  return activity.actor?.name ?? SYSTEM_ACTOR
}

export function fieldLabel(field: string): string {
  return FIELD_LABELS[field] ?? field.replace(/_id$/, '').replace(/_/g, ' ')
}

// Derived from the shape of the event value, not from a list of events. See the
// rendering decision in the plan: 'status_changed' with both labels yields
// "changed status from Open to In Progress" without an entry of its own.
export function eventPhrase(event: string, field: string | null): string {
  if (event === 'created') return 'created this ticket'
  const changed = /^(.+)_changed$/.exec(event)
  if (changed) return `changed ${changed[1].replace(/_/g, ' ')}`
  const added = /^(.+)_added$/.exec(event)
  if (added) return `added ${added[1].replace(/_/g, ' ')}`
  if (event === 'updated' && field) return `updated ${fieldLabel(field)}`
  return event.replace(/_/g, ' ')
}

export function activitySentence(activity: TicketActivity): string {
  const phrase = eventPhrase(activity.event, activity.field)
  const { from_label: from, to_label: to } = activity
  const transition = from && to ? ` from ${from} to ${to}` : ''
  return `${actorName(activity)} ${phrase}${transition}`
}

export function activityReason(activity: TicketActivity): string | null {
  const reason = activity.meta.reason
  return typeof reason === 'string' && reason !== '' ? reason : null
}
```

**`activityReason` reads `meta.reason` and returns `null` for a non-string or an empty string.** Story 29's plan (line 293) rules that the key is *omitted* when no reason was given; Story 23's `'category_deleted'` and `'edited'` are machine markers, not sentences, but they are legitimate `reason` values — **they display as-is**, which reads acceptably (*"because: category_deleted"*) and is honest. **Do not build a reason-code translation table**: it would be a guess at three unimplemented stories' vocabularies.

### 9 — Timeline state in the store

**File: `frontend/src/stores/tickets.ts`**

Add a third concern beside `creating` (**7**) and the detail state (**8**). **Do not touch either, and do not touch `loadTicket` (10).**

```ts
const activities = ref<TicketActivity[]>([])
const activitiesMeta = ref<Paginated<TicketActivity>['meta'] | null>(null)
const activitiesLoading = ref(false)
const activitiesError = ref<string | null>(null)
let latestActivitiesRequest = 0

async function loadActivities(id: number): Promise<void> {
  const request = ++latestActivitiesRequest
  activitiesLoading.value = true
  activitiesError.value = null
  try {
    const response = await listTicketActivities(id)
    if (request !== latestActivitiesRequest) return
    activities.value = response.data
    activitiesMeta.value = response.meta
  } catch (caughtError) {
    if (request !== latestActivitiesRequest) return
    activitiesError.value = errorMessage(caughtError)
    activities.value = []
    activitiesMeta.value = null
  } finally {
    if (request === latestActivitiesRequest) activitiesLoading.value = false
  }
}
```

Add all five to the `return` at **11**. The `latestActivitiesRequest` guard is copied from `stores/users.ts:19,30,34,39` and it is **load-bearing**: `TicketDetailView.vue:9` watches `route.params.id`, so navigating between two tickets puts two requests in flight and the slower one must not paint. **Do not reuse `detailError`** — a failing timeline must not blank the ticket.

`activitiesMeta` is kept for its `total` and `last_page`, which task 10 shows and **TM-49 needs** for append-on-demand.

### 10 — The timeline

**Create file: `frontend/src/components/TicketTimelineEntry.vue`**

One entry: icon chip, sentence, old→new values, relative time, system styling.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { TicketActivity } from '../api/activities'
import { eventDescriptor } from '../lib/activityEvents'
import { activityReason, activitySentence } from '../lib/activityProse'
import { readableTextColor } from '../lib/color'
import { relativeAge } from '../lib/relativeTime'
const props = defineProps<{ activity: TicketActivity }>()
const descriptor = computed(() => eventDescriptor(props.activity.event))
const iconColor = computed(() => readableTextColor(descriptor.value.color))
const isSystem = computed(() => props.activity.actor === null)
</script>
<template>
  <li
    class="entry"
    data-testid="timeline-entry"
    :data-event="activity.event"
    :data-system="isSystem ? 'true' : undefined"
  >
    <span
      class="icon"
      data-testid="timeline-icon"
      :style="{ backgroundColor: descriptor.color, color: iconColor }"
      aria-hidden="true"
    >
      <svg viewBox="0 0 16 16" width="12" height="12">
        <path :d="descriptor.path" fill="none" stroke="currentColor" stroke-width="1.5" />
      </svg>
    </span>
    <p class="sentence" data-testid="timeline-sentence">{{ activitySentence(activity) }}</p>
    <p
      v-if="activity.field && activity.from_label && activity.to_label"
      class="values"
      data-testid="timeline-values"
    >
      {{ activity.from_label }} &rarr; {{ activity.to_label }}
    </p>
    <p v-if="activityReason(activity)" class="reason" data-testid="timeline-reason">
      {{ activityReason(activity) }}
    </p>
    <time
      class="age"
      data-testid="timeline-age"
      :datetime="activity.created_at"
      :title="activity.created_at"
      >{{ relativeAge(activity.created_at) }}</time
    >
  </li>
</template>
<style scoped>
.entry {
  display: grid;
  grid-template-columns: 24px 1fr auto;
  gap: 8px;
  padding: 8px 0;
  border-bottom: 1px solid #e5e7eb;
}
.icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  grid-row: 1 / span 3;
}
.sentence {
  grid-column: 2;
}
.values,
.reason {
  grid-column: 2;
  font-size: 0.875rem;
  opacity: 0.8;
}
.reason {
  font-style: italic;
}
.age {
  grid-column: 3;
  grid-row: 1;
  font-size: 0.875rem;
  opacity: 0.7;
}
.entry[data-system='true'] {
  border-left: 3px dashed #9ca3af;
  padding-left: 8px;
  background: #f9fafb;
}
.entry[data-system='true'] .sentence {
  font-style: italic;
}
</style>
```

**Every interpolation is `{{ }}`. There is no `v-html` in this story and there must never be one** — `meta.reason`, `from_label` and `to_label` are user-supplied and stored verbatim (Story 29's plan, line 410; E9-S6's fourth criterion names the timeline). AC5's *"visually distinct"* is the `data-system="true"` block, following `CategoryBadge.vue:15,24–26`.

**Create file: `frontend/src/components/TicketTimeline.vue`**

Loading, error, empty and the list. Reads the store directly, like `TicketDetailView.vue` does.

```vue
<script setup lang="ts">
import { useTicketsStore } from '../stores/tickets'
import TicketTimelineEntry from './TicketTimelineEntry.vue'
const store = useTicketsStore()
</script>
<template>
  <section data-testid="ticket-timeline">
    <h3>History</h3>
    <p v-if="store.activitiesLoading" data-testid="timeline-loading">Loading history...</p>
    <p v-else-if="store.activitiesError" data-testid="timeline-error">{{ store.activitiesError }}</p>
    <p v-else-if="!store.activities.length" data-testid="timeline-empty">
      Nothing has happened to this ticket yet.
    </p>
    <template v-else>
      <ol data-testid="timeline-list">
        <TicketTimelineEntry
          v-for="activity in store.activities"
          :key="activity.id"
          :activity="activity"
        />
      </ol>
      <p
        v-if="store.activitiesMeta && store.activitiesMeta.last_page > 1"
        data-testid="timeline-truncated"
      >
        Showing the {{ store.activities.length }} most recent of
        {{ store.activitiesMeta.total }} entries.
      </p>
    </template>
  </section>
</template>
```

**`timeline-truncated` is a statement, not a button.** Append-on-demand is TM-49's first criterion by name; this tells the reader the truth about what they are seeing without pre-empting that story's interaction.

### 11 — Mount it on the detail page

**File: `frontend/src/views/TicketDetailView.vue`**

Import `TicketTimeline` beside the three existing component imports (**4–6**), and extend `load` on **line 9** so both requests fire together:

```ts
const load = () => {
  const id = Number(route.params.id)
  void store.loadTicket(id)
  void store.loadActivities(id)
}
```

In the template (**11**), add `<TicketTimeline />` inside the `v-else-if="store.current"` article, **after** the last timestamp paragraph and after any `ticket-resolution` block Story 33 may have added. **Two rules:**

- **The timeline is inside the `store.current` branch.** A ticket that 404s shows the not-found state and no history.
- **`ticket-resolution` stays.** Story 33's plan (line 84) rules that the resolution block and the timeline coexist — *"one is 'how this ticket stands', the other is 'what happened when'"*. **Do not remove or duplicate it.**

---

## Edge Cases & Failure Modes

- **Two activities in the same second.** `recordMany()` assigns one `now()` to the whole batch (`ActivityRecorder.php:29`) and `created_at` is second-resolution, so a bulk category reassignment writes N rows with identical timestamps, and a status change plus a note in the same request tie too. **`created_at DESC` alone gives MySQL licence to return any order, and paginating an unstable sort duplicates and drops rows across pages.** Enforced by `orderByDesc('id')` in task 1 and by test 2.
- **`meta` is `null` in the database.** The column is nullable, and any row written by `recordMany()` before Story 38's fix holds the string `"null"`, which the `array` cast (`TicketActivity.php:16`) decodes to `null`. `$this->meta ?? []` in task 2 covers both; without it `$meta['from_name']` throws. Test 6 inserts a null-`meta` row and asserts `meta` comes back as `{}`.
- **`user_id` non-null but the user row is gone.** `user_id` is `nullOnDelete` (`…create_ticket_activities_table.php:14`), so a hard-deleted user turns every one of their activities into a system row. **This cannot happen through the API** — `UserPolicy::delete` denies everyone and there is no delete route (`docs/api-contract.md:25–26`), and TM-12 chose deactivation precisely *"so ticket history keeps a valid author"*. A direct SQL delete would silently rewrite history as "System"; recorded here so the next person to propose a user-delete endpoint reads it first.
- **An `event` value not in the enum.** `TicketActivity::casts()` maps `event` to `TicketActivityEvent`, so a raw string reaching that column makes `$this->event->value` throw a `ValueError` and the **whole page 500s, not just one entry**. Story 38's `tryFrom()` round-trip test is the defence, and its typed `record()` signature is why it cannot arrive through the recorder. **No defensive coercion is added here** — swallowing it would hide a corrupt audit trail, which is the one thing this table must not do.
- **An event the frontend has never seen.** `eventDescriptor()` returns `FALLBACK` and `eventPhrase()` generates a sentence from the value's shape. **A new event renders correctly with no frontend change** — test 15 asserts it with a deliberately fictional event value.
- **`from_label` present, `to_label` null.** A first assignment sets `meta.from_name = null` (Story 26's plan, line 246). `activitySentence` appends the transition only when **both** are truthy, so the sentence degrades to *"Nadia assigned"* rather than *"assigned from null to Omar"*, and `timeline-values` is hidden by the same condition. Test 14 covers it.
- **`meta.reason` is an empty string.** Story 29's plan flags `""` as a footgun: the key exists but says nothing. `activityReason` returns `null` for it, so no empty italic line renders. Test 16.
- **`<script>` or Arabic in `meta.reason`, a subject or a name.** Stored verbatim (`JSON_UNESCAPED_UNICODE`, utf8mb4 containers) and returned verbatim by the API. **Escaping happens in Vue `{{ }}` and nowhere else.** Test 10 asserts the API round-trip; test 17 asserts the DOM shows the literal text and that no `<script>` element is created.
- **Navigating from ticket A to ticket B while A's timeline is loading.** `TicketDetailView.vue:9` watches `route.params.id`. The `latestActivitiesRequest` guard discards the stale response; without it A's history paints under B's subject. Test 18.
- **A soft-deleted ticket.** `Ticket` uses `SoftDeletes` (`Ticket.php:16`), so implicit binding excludes trashed rows and the endpoint returns **404** — even though TM-48's third criterion guarantees the rows still exist. That is correct for this story: the detail page 404s too (`ticket-not-found`), so there is no screen to render a timeline on. **Reaching a deleted ticket's history is not in any current story**; test 8 pins the 404 so the behaviour is a decision, not an accident.
- **A ticket with hundreds of activities.** Page one is 20 rows; `timeline-truncated` states the total. TM-49's third criterion owns the performance claim. **No unbounded query exists** — `paginate()` caps at 100 via the `per_page` rule.
- **`per_page=0`, `per_page=101`, `per_page=abc`.** All `422` from the `['sometimes','integer','min:1','max:100']` rule, matching `UserController.php:25`. Test 3.
- **An unauthenticated or inactive caller.** `auth:sanctum` then `active` (`routes/api.php:32`) return `401` before the controller runs. Test 7.
- **A ticket with zero activities.** Impossible through `POST /api/v1/tickets` (which always writes a `created` row at `TicketController.php:44`) but reachable for a ticket created by hand or by a future seeder. `timeline-empty` renders. Test 13c.

---

## Test Plan

### Backend — `backend/tests/Feature/Activity/TicketTimelineTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Rows are written through `ActivityRecorder` inside `DB::transaction`, never `TicketActivity::create()` — Story 38's single-writer tripwire fails the build otherwise. Uses task 6's `makeTicket()` helper; **there is no `TicketFactory`.**

1. `test_activities_are_returned_newest_first` — three rows recorded with distinct `created_at` values; assert `data.0.id`, `data.1.id`, `data.2.id` descend by time. **AC1.**
2. `test_rows_sharing_a_timestamp_are_ordered_by_id_descending` — one `recordMany()` across three tickets plus two more `record()` calls on the target ticket under a frozen `now()`, so every `created_at` is identical; assert ids strictly descend, then request `per_page=1` for pages 1 and 2 and assert **no id appears twice**. This is the test that fails if `orderByDesc('id')` is dropped.
3. `test_per_page_is_validated_and_defaults_to_twenty` — 25 rows; no param → `meta.per_page === 20` and 20 items; `per_page=5` → 5; `per_page=0`, `per_page=101` and `per_page=abc` → `422`. **AC1.**
4. `test_the_actor_is_present_and_null_for_a_system_row` — one row with `user_id` set, one with `user_id` null. Assert the user row's `actor.name`, and for the system row assert **`assertArrayHasKey('actor', $response->json('data.0'))`** *and* that it is `null`. `assertJsonPath(…, null)` alone would also pass if the key were missing — which is exactly what a forgotten `->with('user')` produces. **AC3, AC5.**
5. `test_field_change_exposes_raw_values_and_resolved_labels` — a `category_changed` row shaped like `CategoryController.php:91–95`. Assert `field === 'category_id'`, `old_value`/`new_value` are the **stringified ids**, and `from_label`/`to_label` are the two **names** from `meta`. **AC3.**
6. `test_labels_fall_back_to_raw_values_and_meta_never_returns_null` — a row with `field` and both values but **no `from_name`/`to_name`**, plus a row inserted with `meta` explicitly `null` via `DB::table('ticket_activities')->insert(...)`. Assert `from_label === old_value`, and `meta === []` for the null row rather than a 500.
7. `test_unauthenticated_is_rejected` — no token → `401`.
8. `test_unknown_and_soft_deleted_tickets_return_404` — id `999999` → `404`; then `$ticket->delete()` and the same request → `404`.
9. `test_the_actor_is_eager_loaded` — 10 rows across 10 distinct users; count queries with `DB::listen` and assert **at most 4** (ticket binding, count, page, users). Fails the moment `->with('user')` is removed. Prove it by removing it.
10. `test_unicode_and_markup_round_trip_through_the_api` — `meta.reason` set to `'<script>alert(1)</script> مرحبا'`; assert `assertJsonPath('data.0.meta.reason', …)` returns the **byte-identical** string. Nothing escapes on the way out.
11. `test_an_agent_may_read_any_tickets_timeline` — a ticket created by an admin, read with an agent token → `200`. **`TicketPolicy::view` returns true for all staff; this pins it.**
12. `test_activities_of_another_ticket_are_not_included` — two tickets, rows on both; assert every returned `id` belongs to the requested ticket and `meta.total` matches.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

13. `self::ACCESS` gains `'tickets.activities' => 'staff'` and `'tickets.store' => 'staff'`. `test_every_api_route_is_classified` and `test_every_classified_route_exists` then both pass. **Expect the suite to go from 2 pre-existing failures to 1** — `PasswordThrottleTest` alone, owned by TM-14.

### Frontend — new

14. `frontend/src/lib/activityProse.spec.ts`
    - `created` → `'Ahmed created this ticket'`.
    - `category_changed` with both labels → `'Ahmed changed category from Hardware to Software'`.
    - **`status_changed` with `from_label: 'Open'`, `to_label: 'In Progress'` → `'Ahmed changed status from Open to In Progress'`** — the intake's own example sentence, from an event that has no map entry. This test is the contract that later stories need no timeline edit.
    - `note_added` → `'Ahmed added note'`; `updated` with `field: 'category_id'` → `'Ahmed updated category'`; `claimed` → `'Ahmed claimed'`; `some_future_event` → `'Ahmed some future event'`.
    - `actor: null` → the sentence begins `'System '`.
    - `to_label` set but `from_label` null → **no `' from … to … '` clause**.
    - `activityReason`: a real string → the string; `''` → `null`; missing key → `null`; a non-string (`42`) → `null`.
15. `frontend/src/lib/activityEvents.spec.ts`
    - `created` and `category_changed` return **distinct** colours and **distinct** paths. **AC4.**
    - A fictional event returns the fallback, and the fallback has a non-empty `path` and a colour matching `/^#[0-9A-Fa-f]{6}$/` — so `readableTextColor` (`lib/color.ts:11`) never hits its invalid-hex branch.
    - Every descriptor's colour matches that pattern.
16. `frontend/src/components/TicketTimeline.spec.ts` — mock `../api/activities` as `HealthView.spec.ts:9` mocks `../api/health`; mount with `createPinia()`.
    - a. loading → `timeline-loading`, no `timeline-list`.
    - b. error → `timeline-error` with the store's message.
    - c. empty `data` → `timeline-empty`.
    - d. three activities → three `timeline-entry` nodes in payload order, each with `data-event` set and one `timeline-sentence`.
    - e. **`actor: null` → that entry carries `data-system="true"` and its sentence starts `'System'`; a user entry has no `data-system` attribute. AC5.**
    - f. `timeline-icon`'s inline `background-color` differs between a `created` entry and a `category_changed` entry. **AC4.**
    - g. a field-change entry renders `timeline-values` as `'Hardware → Software'`; an entry with no `field` renders none.
    - h. `meta.reason: 'category_deleted'` → `timeline-reason`; `meta: {}` → none.
    - i. `meta.reason: '<script>alert(1)</script>'` → `wrapper.find('script').exists()` is `false` and the reason's `text()` contains the literal `'<script>'`. **The XSS assertion; E9-S6's fourth criterion names the timeline.**
    - j. `meta.last_page: 3` → `timeline-truncated` names `meta.total`; `last_page: 1` → absent.
    - k. **stale response** — call `loadActivities(1)` and `loadActivities(2)` with the mock resolving 1 after 2, and assert the rendered entries are ticket 2's. Pins the `latestActivitiesRequest` guard.

### Frontend — modified

17. `frontend/src/views/TicketDetailView.vue` has no spec today and this story does not add one; case 16 covers the timeline in isolation. **Assert by hand** (verification step 8) that `loadActivities` fires on mount and on an id change.

---

## Verification Steps

1. **Services:** `docker compose ps` → all three containers healthy, `tm-mysql-test` on **3307**.
2. **Confirm Story 38 landed:** from `backend/`, `grep -n "function activities" app/Models/Ticket.php` and `grep -n "function user" app/Models/TicketActivity.php` → **one hit each**. Neither is added by this story.
3. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
4. **Backend tests:** from `backend/`, `composer test`. Expect **+12 tests** and **1 remaining failure** — `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user`, owned by TM-14 — down from 2, because task 5 classifies `tickets.store`.
5. **Prove test 2 earns its place:** delete `->orderByDesc('id')` from `TicketActivityController`, re-run `--filter=test_rows_sharing_a_timestamp_are_ordered_by_id_descending`. It must **fail on the duplicate id across pages**. Restore.
6. **Prove test 9 earns its place:** delete `->with('user')`, re-run `--filter=test_the_actor_is_eager_loaded` **and** `--filter=test_the_actor_is_present_and_null_for_a_system_row`. **Both** must fail — the second because `whenLoaded` drops the `actor` key when the relation is not loaded. Restore.
7. **Prove the index serves the query.** From `backend/`, on a ticket with activities:
   `php artisan tinker --execute="dump(DB::select('EXPLAIN SELECT * FROM ticket_activities WHERE ticket_id = 1 ORDER BY created_at DESC, id DESC LIMIT 20'));"`
   → `key` is **`ticket_activities_timeline_index`** and `Extra` does **not** contain `Using filesort`. If it does, say so in the PR rather than leaving it unstated — the composite exists for exactly this read (TM-45's fourth criterion).
8. **Frontend:** from `frontend/` — `npx vue-tsc -b` (exit `0`), `npm run lint` (exit `0`, warnings fail), `npm run format:check` (exit `0`), `npm test` (**+3 spec files**, all green).
9. **Prove the fallback works, which is the whole rendering decision.** In `frontend/src/lib/activityEvents.ts` temporarily rename the `created` key to `created_x`, run `npx vitest run src/lib/activityEvents.spec.ts` and confirm the distinct-colour case fails **while nothing throws**; then run `npm test` and confirm `activityProse.spec.ts` is **entirely unaffected** — the sentence never consulted the map. Restore.
10. **By hand.** `php artisan serve` in `backend/`, `npm run dev` in `frontend/`, log in as the seeded admin.
    - `POST /api/v1/tickets` to file a ticket, then open `/tickets/<id>`. The **History** section shows one entry: *"\<your name\> created this ticket"*, a teal icon, a relative age, no `→` line.
    - `curl -H "Authorization: Bearer <token>" http://localhost:8000/api/v1/tickets/<id>/activities | jq` → `data`, `links`, `meta`; `meta.per_page` **20**; `actor.name` your name; `meta` an object, **never the string `"null"`**.
    - Delete a category holding that ticket (`DELETE /api/v1/categories/<id>` with `reassign_to`). Reload the detail page: a **second, purple** entry above the first reading *"\<name\> changed category from \<old\> to \<new\>"*, a `Hardware → Software` line, and an italic `category_deleted` reason.
    - **Force a system row** to see AC5: `php artisan tinker --execute="DB::transaction(fn () => app(App\Services\ActivityRecorder::class)->record(<ticketId>, App\Enums\TicketActivityEvent::Created, []));"` → reload. The new entry reads **"System created this ticket"**, is italic with a dashed left border, and carries no name.
    - **Force an unknown event** to see the fallback: `DB::table('ticket_activities')->insert([...])` — do **not** do this; the enum cast would 500 the endpoint (see the edge cases). Instead temporarily add a case to `TicketActivityEvent`, record one row, reload, confirm the entry renders with the **grey fallback icon** and a generated sentence, then remove the case and the row.
    - **XSS by hand:** record a row whose `meta.reason` is `<img src=x onerror=alert(1)>`, reload, and confirm the text appears **literally** and no dialog fires.
    - **Stale-response check:** with the network throttled, navigate `/tickets/1` → `/tickets/2` quickly and confirm the history shown belongs to ticket 2.
11. **Regression:** `git status` shows **no file under `backend/database/`**, no change to `docs/erd.md`, `ActivityRecorder.php`, `TicketActivity.php`, `TicketActivityEvent.php`, `TicketController.php`, `TicketResource.php`, any policy, any seeder, or either `package.json` / `composer.json`. `grep -rn "v-html" frontend/src/` → **no output**.

---

## Done Criteria

- [ ] `GET /api/v1/tickets/{ticket}/activities` returns a ticket's activities **newest first**, in the paginated `data`/`links`/`meta` envelope, with `per_page` validated `1..100` and defaulting to **20**.
- [ ] Ordering is `created_at DESC, id DESC`, and a test **paginates across a set of rows sharing one timestamp and proves no id is duplicated or dropped** — it fails when the `id` tie-break is removed. `recordMany()` stamps one `now()` per batch, so this is a real case, not a hypothetical.
- [ ] Every entry renders as a sentence built from the **shape of the event value**, so `status_changed` yields **"Ahmed changed status from Open to In Progress"** — the intake's own example — with **no map entry and no frontend edit**. Asserted by a test.
- [ ] `old_value` / `new_value` carry the raw stringified ids **and** `from_label` / `to_label` carry the readable names from `meta.from_name` / `meta.to_name`, falling back to the raw values. The SPA never learns the meta key convention.
- [ ] The actor and a relative age appear on **every** entry, the age through the existing `relativeAge` (`frontend/src/lib/relativeTime.ts`) — **no second time helper**.
- [ ] Each event has its own icon and colour from **one map with a working fallback**; a fictional event returns the fallback and still renders. Two known events are asserted to have distinct colours and distinct paths.
- [ ] A system row (`actor: null`) reads **"System"**, carries `data-system="true"` and is visually distinct — honouring `TicketActivity::user()`'s docblock rule that **no consumer substitutes a name**.
- [ ] `actor` is proven **present and null** for a system row, not merely null-or-absent — the assertion that catches a forgotten `->with('user')`, which is also pinned by a query-count test.
- [ ] `meta` is **never `null`** in the response: `?? []` covers both a nullable column and the `"null"` string that rows written before Story 38's fix contain.
- [ ] Loading, error, and empty states all exist and are tested; a failing timeline **does not blank the ticket** (`activitiesError` is separate from `detailError`).
- [ ] Navigating between two tickets discards the stale timeline response, via the `latestRequest` guard copied from `stores/users.ts:19`.
- [ ] **No `v-html` anywhere in `frontend/src/`** — `grep` clean — and a test asserts a `<script>` payload in `meta.reason` renders as literal text and creates no element.
- [ ] `TicketPolicy` gains **no new ability**; `view` authorizes the timeline, exactly as `TicketController::show()` does.
- [ ] `docs/api-contract.md` gains the endpoint row and a section documenting the field table, the ordering rule, the known `meta` keys with their owning stories, the null-actor contract, and that **escaping is the renderer's obligation**. The two undocumented `/tickets` endpoints are **named in the PR as TM-22/TM-26's debt, not silently backfilled**.
- [ ] `RouteAuthorizationTest::ACCESS` classifies `tickets.activities` **and** `tickets.store` — the second because the classification loop fails on the first miss and would otherwise never reach the new route. **Story 32's baseline failure is taken deliberately and recorded in the PR**; the suite goes from **2 pre-existing failures to 1**.
- [ ] **No append-on-demand button and no event filter** — both are TM-49 by name. `meta.total` and `meta.last_page` are exposed and a plain sentence states what is being shown, so TM-49 has nothing to re-plan.
- [ ] Story 33's `ticket-resolution` block, if present, is **untouched** — the timeline sits beside it, not over it.
- [ ] **No migration, no column, no index change, no new event case, no new composer or npm package, no icon library**, and no change to `ActivityRecorder`, `TicketActivity`, `TicketActivityEvent`, `TicketController`, `TicketResource`, `docs/erd.md` or any seeder.
- [ ] `pint --test`, `vue-tsc -b`, `npm run lint`, `npm run format:check` all exit `0`; **+12 backend tests**, **+3 frontend spec files**.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 40 (TM-47, internal notes on a ticket).**
