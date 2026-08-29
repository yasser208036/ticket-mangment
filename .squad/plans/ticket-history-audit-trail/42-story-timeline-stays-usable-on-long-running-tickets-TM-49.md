# Story 42 — Timeline stays usable on long-running tickets (Story: TM-49)

## Prerequisites

- **Story 39 (TM-46) is a hard prerequisite and it has not landed.** Every file this story edits is one Story 39 creates. Verified while planning: `backend/app/Http/Controllers/Api/V1/TicketActivityController.php`, `backend/app/Http/Resources/V1/TicketActivityResource.php`, `frontend/src/api/activities.ts`, `frontend/src/lib/activityEvents.ts`, `frontend/src/lib/activityProse.ts`, `frontend/src/components/TicketTimeline.vue` and `TicketTimelineEntry.vue` **all do not exist**, and `backend/routes/api.php` (52 lines) has no activities route — `tickets.store` is at **44**, `tickets.show` at **45**, and the `admin` group opens at **46**. **Verify before starting:** `ls backend/app/Http/Controllers/Api/V1/TicketActivityController.php frontend/src/components/TicketTimeline.vue frontend/src/lib/activityProse.ts`. If any is missing, Story 39 has not landed — **stop and run Stories 38 and 39 first.** This story creates none of that machinery and must not re-plan it.
- **Story 38 (TM-45) is transitively required and has also not landed.** `grep -n "function activities" backend/app/Models/Ticket.php` and `grep -n "function user" backend/app/Models/TicketActivity.php` both return **nothing** today — `Ticket.php` is 57 lines with seven `BelongsTo` relations (**23–56**) and no `activities()`; `TicketActivity.php` is 18 lines and ends at `casts()` (**14–17**). Story 38 owns both relations. **This story adds neither.**
- **Story 40 (TM-47) is a soft prerequisite, and its absence changes two tasks.** `note_added` is the event AC2's own example names (*"notes only"*), and it is Story 40's single enum case. `backend/app/Enums/TicketActivityEvent.php` is **14 lines** and today holds exactly two cases: `Created = 'created'` (**7**) and `CategoryChanged = 'category_changed'` (**8**). **If Story 40 has landed**, task 9 also edits its `addNote`; **if it has not**, skip task 9 and record in the PR that TM-47's task 13 must be written against the filter this story adds. Check with `grep -n "NoteAdded" backend/app/Enums/TicketActivityEvent.php`.
- **Story 41 (TM-48) constrains this story's shape, whether or not it has landed.** Its test 17 (`ActivityRouteImmutabilityTest::test_the_only_activity_route_is_a_get`) asserts **exactly one** `api/v1` route has `activities` in its URI and that its methods are `['GET','HEAD']`, and its plan says in as many words: *"Fails loudly if TM-49 adds a second, which is the moment to re-read AC1."* Its test 14 asserts no route whose URI contains `activit` or `note` carries `PATCH`/`PUT`/`DELETE`. **This story adds no route at all** — see the first decision. `grep -rn "test_the_only_activity_route_is_a_get" backend/tests/` tells you whether the guard is live yet; the constraint holds either way.
- **`TicketActivityEvent::values()` (lines 10–13) already exists and has no production caller.** Task 1 gives it one. **Do not hard-code an event list anywhere in this story** — that is the failure mode the whole plan is written to prevent.
- **There is still no `TicketFactory` and no `RequesterFactory`.** `backend/database/factories/` holds **only `UserFactory.php`**; TM-59 owns the rest. Task 6's test class needs its own `makeTicket()` helper — and **it must be `private`, for a language-level reason.** See the fourth decision.
- **`Rule::in`, `paginate()` and `additional()` are all that is needed on the backend.** Laravel `^13.17` (`backend/composer.json:11`). **No migration, no new column, no index change, no new composer package, no new npm package, no virtual-scroll library, no icon library.**
- **Docker up**, `tm-mysql-test` healthy on **3307** (`backend/phpunit.xml`, the test-database block at **27–39**).

---

## What this story does not build, and who owns it

| Deferred | Owner |
|---|---|
| Cursor / keyset pagination on the timeline | **Nobody — and the third decision explains why offset is provably lossless here.** A future story may take it; it would break the documented `meta.total` / `meta.last_page` envelope |
| A composite `(ticket_id, event, created_at)` index | **Nobody — recommended in the PR, not taken.** It is a migration, and no story in E7 ships one. See the fifth decision |
| Virtual scrolling / windowed rendering | **Nobody.** The sixth decision measures why appending 20 rows at a time does not need it |
| Filtering by actor, date range, or free text | **Nobody.** AC2 says *"by event type"*, and nothing more |
| A saved or URL-persisted filter | **Nobody.** The filter is component state; deep-linking a filtered timeline is not in any criterion |
| Any new `TicketActivityEvent` case | Stories 23, 24, 26, 27, 29, 32, 34, 35 and 40 — each appends its own, and **none of them needs to touch this story's files** |
| The `note_added` event and the note composer | **Story 40 (TM-47)** |
| Blocking updates and deletes on `TicketActivity` | **Story 41 (TM-48)** |

**This is the last story in epic E7.** `.squad/plans/email-notifications/00-overview.md` is still a stub with no rows, so nothing is queued behind it.

---

## Story Goal

A ticket with six hundred activities becomes as readable as one with six, and it does so without any story that adds an event ever having to touch a timeline file again.

1. The timeline loads **one page** and appends the next on demand, below what is already on screen — never replacing it, never re-ordering it, and never showing the same row twice.
2. Entries can be filtered **by event type**, and the available types come from **the ticket's own data with counts**, not from a list somebody has to maintain.
3. The work the backend does per request is **constant in the size of the trail** — a fixed number of queries and at most `per_page` rows, for 3 activities or 600.
4. The newest entry is at the top on first paint and **stays at the top** through every filter change, every appended page and every note added. Nothing the reader does can bury it.

**Not in scope:** any change to what an entry *says* or *looks like* — Story 39 owns the sentence, the icon map and the colours, and this story adds no event vocabulary of its own.

---

## Product rules (from story)

| Situation | Current behaviour (after Story 39) | New behaviour |
|---|---|---|
| Opening a ticket with 600 activities | 20 rows render, a sentence states the total, and there is **no way to see row 21** | 20 rows render with a **Load more** control; each press appends the next 20 below |
| Reading the timeline | Every event type, always | Optional filter by one or more event types; **no selection means everything** |
| What the filter offers | No filter | Exactly the event types **present on this ticket**, each with its count |
| An event type absent from this ticket | — | **Not offered.** A filter option that can only return zero rows is not shown |
| A story adds a new event | The timeline renders it through the fallback with no edit | It also appears in the filter with no edit — `Rule::in(TicketActivityEvent::values())` and a data-driven option list |
| An unknown value in `events` | — | **`422`**, naming the invalid value |
| Changing the filter | — | Reloads from page 1; the newest matching entry is at the top |
| A row inserted at the head between two page requests | — | The appended page **may repeat rows already on screen; it can never skip one.** Repeats are dropped by id — see the third decision |
| A failed **Load more** | — | The rows already on screen **stay**; a separate message appears beside the button |
| A failed first load | Timeline shows an error, ticket unaffected | Unchanged |
| Navigating to another ticket mid-append | — | The stale page is discarded and never appended to the wrong ticket |
| Adding a note while a filter excludes notes | — | The note is **not** prepended into a list it does not match; its count still rises |

---

## Context — Read These Files First

1. [`39-story-ticket-timeline-TM-46.md`](39-story-ticket-timeline-TM-46.md) — **read it end to end before writing a line.** Every file below is one it creates. In particular: its **task 1** (`TicketActivityController`, the `per_page` rule and the `created_at DESC, id DESC` ordering with its comment about `ActivityRecorder.php:29`), its **task 2** (`TicketActivityResource`, `$this->meta ?? []`, `whenLoaded('user', …)`), its **task 9** (the store's `activities` / `activitiesMeta` / `activitiesLoading` / `activitiesError` and the `latestActivitiesRequest` guard), its **task 10** (`TicketTimelineEntry.vue`, and the `timeline-truncated` paragraph in `TicketTimeline.vue` that **task 8 of this story replaces**), and its two decisions on the event map and the shape-driven sentence. **Its closing note says this story owns append-on-demand and the event filter by name, and that it exposed `meta.total` and `meta.last_page` so "TM-49 has nothing to re-plan." Honour that: do not re-derive its work.**
2. [`41-story-the-audit-trail-is-append-only-TM-48.md`](41-story-the-audit-trail-is-append-only-TM-48.md) — **test 17 in its Test Plan** ("the only activity route is a GET") and **test 14** (no mutating verb on an activity path). These are the two assertions that decide this story's first decision. Also read its **first decision** — the append-only guarantee is what makes offset append lossless here.
3. [`38-story-activity-table-and-a-single-recorder-TM-45.md`](38-story-activity-table-and-a-single-recorder-TM-45.md) — its **test 21**, `SingleWriterTest::test_only_the_recorder_writes_to_ticket_activities`, scans every `.php` under `app_path()` for a list of write idioms that includes the literal strings `"DB::table('ticket_activities')"` and `'DB::table("ticket_activities")'`. **It matches on source text, so it fires on a read too.** See the sixth decision — this is why task 1's facet query goes through `$ticket->activities()`.
4. `backend/app/Enums/TicketActivityEvent.php` — **the whole file, 14 lines.** `Created` (**7**), `CategoryChanged` (**8**), and **`values()` at 10–13**, which returns `array_column(self::cases(), 'value')`. Task 1's validation rule and task 6's test both drive off this and nothing else.
5. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php:19–38` — the pagination precedent Story 39 copied. `$request->validate([...])` at **22–26**, the `per_page` rule at **25**, `->paginate($filters['per_page'] ?? 15)->withQueryString()` at **35**. Note `->when(...)` at **28–33**: **this is the house idiom for a conditional filter**, and task 1 matches it.
6. `backend/app/Services/ActivityRecorder.php` — 40 lines. `record()` **13–19**; `recordMany()` **21–39**; the transaction guard at **26–28**; **`$createdAt = now()` at 29, one timestamp for the whole batch**; `TicketActivity::insert($chunk)` at **37**. `recordMany()` takes an **array of ticket ids**, so `recordMany(array_fill(0, 400, $id), …)` writes 400 rows for one ticket in one round trip — **task 6 builds its 400-row fixture this way.** **This file is not edited.**
7. `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php` — 29 lines. `ticket_id` `cascadeOnDelete` (**13**), `user_id` `nullOnDelete` (**14**), `event` **`varchar(50)`** (**15**), `meta` `json` nullable (**19**), `created_at` `useCurrent()` (**20**), and **the only index: `['ticket_id', 'created_at']` named `ticket_activities_timeline_index` (21)**. There is **no index on `event`** — read the fifth decision before proposing one. **This file is not edited.**
8. `backend/app/Models/TicketActivity.php` — 18 lines. **`casts()` at 14–17 casts `event` to `TicketActivityEvent`.** This matters to task 1's facet in a way that is easy to get wrong: see the sixth decision.
9. `vendor/laravel/framework/src/Illuminate/Http/Resources/Json/PaginatedResourceResponse.php:15–42` — **read `array_merge_recursive($this->paginationInformation($request), $this->resource->with($request), $this->resource->additional)` at 20–24.** This is the mechanism task 1 uses to put `event_counts` inside `meta` beside `total` and `last_page`. `additional()` itself is `vendor/laravel/framework/src/Illuminate/Http/Resources/Json/JsonResource.php:227`.
10. `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1086–1108` — `pluck()`. **Lines 1097–1100 are the branch task 1 depends on:** when the plucked *value* column has no cast, the raw collection is returned untouched; when it has one, every value is pushed through `newFromBuilder()`. The **key** column is never cast. See the sixth decision for the naming rule this forces.
11. `backend/routes/api.php` — the `['auth:sanctum','active']` group at **32**, `tickets.store` at **44**, `tickets.show` at **45**, `admin` group at **46**. **This file is not edited by this story.**
12. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `self::ACCESS` at **16**, `test_every_api_route_is_classified` at **18–25**, `test_every_classified_route_exists` at **27–33**. **This file is not edited either** — a query parameter adds no route name. Story 39 already added `tickets.activities`; confirm with `grep -c "tickets.activities" backend/tests/Feature/Authorization/RouteAuthorizationTest.php` → `1`.
13. `frontend/src/api/pagination.ts` — **the whole file, 18 lines.** `Paginated<T>` with `data`, `links` and `meta`; the `meta` shape is **9–17** (`current_page`, `from`, `last_page`, `path`, `per_page`, `to`, `total`). **Do not edit this file** — `event_counts` is an activities-specific addition and task 7 extends the type locally.
14. `frontend/src/api/users.ts:29–36` — `listUsers(query: UserListQuery = {})`, the **query-object client precedent**. `UserListQuery` at **13–18** has `page?` and `per_page?`. Task 7 follows this exactly; Story 39's `listTicketActivities(ticketId, page = 1)` becomes a query object and **that is a breaking signature change with exactly one caller** — see task 7.
15. `frontend/src/stores/users.ts` — `latestRequest` at **19** and the guard at **30**, **34**, **39**; `load()` at **20–41**; `page` at **16** and `goToPage()` at **51–54**. **Task 8's `loadMoreActivities` reads `latestActivitiesRequest` without incrementing it** — the reason is in task 8, and it is the opposite of what `load()` does here.
16. `frontend/src/views/AdminUsersView.vue` — the **filter-control precedent**: the debounced `watch` at **10–17**, the `users-count` line at **57–60** (`Showing {{ meta.from }} to {{ meta.to }} of {{ meta.total }}`), and the prev/next buttons at **61–72** with their `:disabled` bindings. **Task 10's Load-more button copies that `:disabled` shape; it does not copy the two-button pager** — a timeline appends, it does not page back and forth.
17. `frontend/src/api/errors.ts:12–22` — `errorMessage(error)`. Task 8 uses it for the new `activitiesMoreError`. **Do not add a second error formatter.**
18. `frontend/src/views/TicketDetailView.vue` — 11 lines; the whole script body is **line 9**, the whole template is **line 11**. `load` calls `store.loadTicket(Number(route.params.id))` and is wired to `onMounted` and to `watch(() => route.params.id, load)`. Story 39's task 11 extends line 9 to call `loadActivities` too. **Task 11 of this story does not touch this file** — see task 8's `activitiesTicketId`.
19. `frontend/src/views/HealthView.spec.ts` — the component-spec precedent: `vi.mock('../api/health', …)` at **9**, `createPinia()` in `global.plugins` at **19–20**, `flushPromises()`, `data-testid` selectors. Tasks 12–14 follow it.
20. `docs/api-contract.md` — **98 lines.** Conventions at **7–16**; the endpoints table at **32–47**; per-endpoint sections from **49**. Story 39 appends the `GET /api/v1/tickets/{ticket}/activities` section after **98**; **task 4 extends that section rather than adding a new one.**

---

## Decision — the filter is a query parameter on Story 39's route, and no route is added

The obvious alternatives both add a route, and both are wrong here.

- **A second endpoint for the facet** (`GET /tickets/{ticket}/activity-events`) costs a second round trip on every page load, a second `ACCESS` key, and a second thing to authorize — for data that is one `GROUP BY` on a query the request is already running.
- **A nested filtered route** (`/tickets/{ticket}/activities/{event}`) would make **Story 41's test 17 fail**, which asserts *exactly one* `api/v1` route has `activities` in its URI. Its plan names this story as the thing that test is watching for.

So: `events[]` and the existing `per_page` are **query parameters**, and `event_counts` rides in the response's `meta`. `backend/routes/api.php` is untouched, `RouteAuthorizationTest::ACCESS` is untouched, and Story 41's tests 14 and 17 keep passing without knowing this story happened.

**If you find yourself adding a route, stop.** The constraint is not stylistic; it is an assertion in a test file with a comment naming this story.

## Decision — the filter's options come from the ticket's data, never from a list

AC2's examples are *"notes only or status changes only"*. Today `note_added` exists only if Story 40 landed, and **`status_changed` does not exist at all** — it is Story 32's, unimplemented. A hard-coded `<select>` with eleven `<option>`s would therefore ship with nine options that return zero rows, and would need editing by every one of the nine stories that adds an event.

**That is precisely the failure Story 39's rendering decision was written to prevent** — *"A story that has to edit the timeline to add an event has broken this contract"* — and a hard-coded filter breaks it in the same way, one layer up.

So the filter is data-driven at both ends:

- **The server validates against the enum, not a literal:** `Rule::in(TicketActivityEvent::values())`. A story that adds a case makes it filterable in the same commit, with **no edit here**.
- **The client offers only what the ticket has, with counts:** `meta.event_counts` is `{ "created": 1, "category_changed": 4, "note_added": 37 }` — one entry per event type **present on this ticket**. An option that can only return zero rows is never rendered.

**The facet is computed over the ticket, ignoring the active filter.** Filtering the facet by the filter would collapse the option list to the one selected type the moment the user selected it, and there would be no way back. That is the bug this sentence exists to prevent, and task 6's test 5 pins it.

The measure of this design, same as Story 39's: **when Story 32 lands, "Status changed (12)" appears in the filter with no frontend change and no backend change.** Test 4 asserts it with a fictional case.

## Decision — offset append is lossless on this table, and the reason is Story 41

Appending page 2 to page 1 by offset is normally unsafe: a row inserted at the head shifts every offset and readers lose rows. **On this table it cannot lose a row, and the proof is short enough to state.**

`ticket_activities` is **append-only** (Story 41) and the order is **newest first**. So between the two requests the only possible mutation is *k* new rows arriving at the **head**. If page 1 returned old positions 1–20, then page 2 (offset 20) over the new ordering returns old positions **21−k … 40−k**. The client already holds 1–20, so:

- **Rows 21−k … 20 arrive a second time** — *k* duplicates.
- **Nothing between 21 and 40−k is skipped.** There is no gap, because the shift is entirely at the head and the window moved *backwards* over data the client already has.

**Duplicates are the whole failure mode, and they are trivially removable by `id`** — ids are unique and immutable here, for the same reason. So task 8 appends through `appendUnique()`, keyed on `id`, and test 15 simulates a head insert between two pages and asserts the merged list has no repeated id and no missing one.

**Why not keyset pagination, which needs no argument at all?** Because the correct predicate for `created_at DESC, id DESC` is `created_at < :ts OR (created_at = :ts AND id < :id)` — two cursor parameters — and it would replace `meta.total` and `meta.last_page` with a cursor. Story 39 documented that envelope in `docs/api-contract.md` and built `timeline-truncated` on it, and Story 41 documented it again. **Trading a documented contract and two shipped plans for a duplicate that a `Set` removes is not a good trade.** Recorded here so a future story that *does* want cursors finds the reasoning rather than re-deriving it.

## Decision — task 6's `makeTicket()` must be `private`, and it must not be extracted

Stories 39, 40 and 41 each carry a **private** `makeTicket()` helper because there is no `TicketFactory`. This story's test class needs a fourth. The tempting cleanup is to lift one copy into `Tests\TestCase`.

**Do not — it is a fatal error, not a style preference.** PHP forbids reducing an inherited method's visibility: a `protected function makeTicket()` on `Tests\TestCase` and a `private function makeTicket()` on a subclass is *"Access level to makeTicket() must be protected (as in class TestCase) or weaker"*, **at class-declaration time**. Three landed test classes would stop loading at once, and the failure would look nothing like its cause.

**So: a fourth private copy, and the duplication named in the PR as TM-59's to collapse** when factories arrive and all four call sites disappear together. Before even considering the extraction, `grep -rn "function makeTicket" backend/tests/` and look at the visibility keyword on every hit.

## Decision — AC3 is met by bounding the work, not by timing it

*"Renders without a noticeable delay"* has no assertion in it. A wall-clock test would be flaky on CI and would pass or fail for reasons unrelated to this code. What is testable is that **the work does not grow with the trail**:

- **A fixed number of queries per request**, whatever the row count. Test 8 records the query count for a **3-row** ticket and for a **400-row** ticket and asserts they are **equal** — that is the assertion that fails the day someone adds an N+1 or drops `->with('user')`.
- **At most `per_page` rows in the response**, enforced by `paginate()` and the `max:100` rule Story 39 wrote. Test 9.
- **At most `per_page` entries in the DOM after the first load.** Test 17.
- **The facet is one query, not one per event type.** A `GROUP BY`, asserted by the same query count.

And **one real render cost is fixed rather than measured**, in task 11: Story 39's `TicketTimelineEntry.vue` calls `activitySentence(activity)` in the template and `activityReason(activity)` **twice** — once in the `v-if`, once in the body. At 20 entries that is 60 function calls per re-render; at 600 it is 1800. Wrapping both in `computed` makes each entry's work run once and cache. **This is the only performance change this story makes to a rendering path, and it is a three-line edit.**

**No virtual scrolling.** The DOM only ever holds what the reader explicitly asked for — 20 rows per press — so the unbounded case does not arise. If a future story adds a "load all" control, windowing becomes its problem; say so in the PR.

## Decision — no migration, and the composite index is recommended rather than taken

With a filter the page query becomes `WHERE ticket_id = ? AND event IN (…) ORDER BY created_at DESC, id DESC LIMIT 20`. `ticket_activities_timeline_index` on `(ticket_id, created_at)` (**migration line 21**) serves the ticket predicate and the ordering; `event` is a residual filter applied to index entries as they are walked. For a ticket with several hundred rows that is a few hundred index entries in the worst case — the *unfiltered* cost, which Story 39 already ships.

**The complete fix is `(ticket_id, event, created_at)`, and this story does not add it:**

- **It is a migration**, and **no story in E7 ships one** — Story 40's plan states it outright for `varchar(50)`, and Stories 38, 39 and 41 each declare "no migration" in their prerequisites. Breaking that here for a read that is already fast at the stated scale is not proportionate.
- **The facet's `GROUP BY event` would benefit too**, which makes it a real recommendation rather than a hedge — but it is one measurement away from being a decision, and verification step 6 takes that measurement.

**Recommend it in the PR with the numbers from verification step 6 attached.** If `EXPLAIN` on a 400-row filtered read shows `Using filesort` or a row estimate far above `per_page`, say so in the PR **with the number** rather than leaving it unstated.

## Decision — `$ticket->activities()`, never `DB::table('ticket_activities')`, and never a column named `event`

Two traps in one query. Both are verified, and both silently produce wrong output rather than an error.

- **Story 38's test 21 scans source text.** It greps every `.php` under `app_path()` for a list of idioms that includes the literal `"DB::table('ticket_activities')"`. Its intent is to catch writes, but **it matches a read too**, so `DB::table('ticket_activities')->groupBy('event')` — the natural way to write a facet — **fails the build with a filename**. Task 1 goes through `$ticket->activities()`, which is both idiomatic and invisible to the tripwire.
- **The aggregate column must not be called `event`.** `TicketActivity::casts()` (**14–17**) casts `event` to `TicketActivityEvent`, and `Builder::pluck()` (**Builder.php:1097–1100**) pushes the plucked **value** column through `newFromBuilder()` whenever that column has a cast. `->pluck('event', …)` therefore returns **enum instances**, and `json_encode` of an enum-keyed structure is not the `{"created": 1}` the client expects. The **key** column is never cast, so `->pluck('aggregate', 'event')` — aggregate as the value, `event` as the key — returns plain strings and plain numbers. `aggregate` has no cast, so line **1100** returns the raw collection.

**Also: `event_counts` must not collide with a pagination `meta` key.** `additional` is merged with `array_merge_recursive` (**PaginatedResourceResponse.php:20–24**), which on a colliding scalar key produces an **array of both values** rather than an overwrite. `event_counts` collides with nothing in **9–17** of `pagination.ts`. **Never name an `additional` meta key `total`, `per_page`, `current_page`, `last_page`, `from`, `to` or `path`.**

---

## Backend Tasks

### 1 — The filter and the facet

**File: `backend/app/Http/Controllers/Api/V1/TicketActivityController.php`** *(Story 39's file — edit it; do not create a second controller)*

Add two imports (`App\Enums\TicketActivityEvent`, `Illuminate\Validation\Rule`), extend the validation, add one `->when()` to the query, and attach the facet.

```php
    public function __invoke(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // max: is derived, not a literal -- a story that adds an event case
            // widens the filter without editing this file. TM-49's whole design.
            'events' => ['sometimes', 'array', 'max:'.count(TicketActivityEvent::values())],
            'events.*' => ['string', Rule::in(TicketActivityEvent::values())],
        ], [
            'events.*.in' => 'Unknown activity event type: :input.',
        ]);
        $events = array_values(array_unique($filters['events'] ?? []));

        // created_at DESC alone is not deterministic: recordMany() stamps one
        // now() across a whole batch (ActivityRecorder.php:29), and the column
        // is second-resolution, so ties are normal. id DESC breaks them and
        // costs nothing -- the PK rides in the secondary index leaf.
        $activities = $ticket->activities()
            ->with('user')
            ->when($events !== [], fn ($query) => $query->whereIn('event', $events))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TicketActivityResource::collection($activities)
            ->additional(['meta' => ['event_counts' => $this->eventCounts($ticket)]]);
    }

    /**
     * Every event type present on this ticket, with its count.
     *
     * Deliberately NOT filtered by $events: the facet drives the filter's
     * option list, so narrowing it to the active selection would collapse the
     * options to the one already chosen and leave no way back.
     *
     * @return array<string, int>
     */
    private function eventCounts(Ticket $ticket): array
    {
        return $ticket->activities()
            // 'aggregate', not 'event': Builder::pluck() casts the *value*
            // column when the model casts it (Builder.php:1097-1100), and
            // 'event' casts to TicketActivityEvent. The key column is never
            // cast, so event-as-key stays a plain string.
            ->selectRaw('event, COUNT(*) as aggregate')
            ->groupBy('event')
            ->orderBy('event')
            ->pluck('aggregate', 'event')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
```

**Five things here must not be simplified:**

- **`->when($events !== [], …)`, not `->when($events, …)`.** An empty array is falsy, so both work today — but `when()` passes its condition value to the callback, and the explicit comparison says "no selection means everything" in the code rather than in a comment. It matches `UserController.php:32–33`'s explicit `=== 'active'` habit.
- **`array_unique` before `whereIn`.** `events[]=created&events[]=created` is a legal request; without it the SQL carries a pointless duplicate. It changes no result, so **no test asserts it** — it is hygiene, and that is why it is one call and not a helper.
- **`$ticket->activities()`, twice, and no `DB::table`.** Story 38's tripwire matches on source text. See the sixth decision.
- **`->additional([...])` goes on the collection, after `::collection(...)`.** It merges into `meta` through `array_merge_recursive` (**PaginatedResourceResponse.php:20–24**). Attaching it to `TicketActivityResource::make(...)` instead would put it at the top level of a single resource, which is not where the client reads it.
- **`(int)` on the count.** MySQL returns `COUNT(*)` as a string on some driver configurations, and `"37"` in JSON where the client's type says `number` is the kind of mismatch `vue-tsc` cannot catch.

**Do not touch** `$this->authorize('view', $ticket)`, the `per_page` rule, `->with('user')`, or either `orderByDesc`. All four are Story 39's and all four are pinned by its tests.

### 2 — The resource is not edited

**`backend/app/Http/Resources/V1/TicketActivityResource.php` — no change.** The filter selects rows; it does not change what a row says. State this in the PR so a reviewer does not go looking.

### 3 — No route, no classification

**`backend/routes/api.php` — no change. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — no change.** A query parameter adds no route name, so `self::ACCESS` (**16**) needs no key and `test_every_api_route_is_classified` (**18–25**) is unaffected. Read the first decision for why this is a requirement rather than a convenience.

### 4 — Document the filter and the facet

**File: `docs/api-contract.md`**

**Extend Story 39's `GET /api/v1/tickets/{ticket}/activities` section — do not add a second section, and do not touch the endpoints table** (the route is unchanged, so its row at Story 39's position is still accurate).

Add to that section's prose, after the `per_page` sentence:

```
`events` is an optional array of `TicketActivityEvent` values
(`?events[]=note_added&events[]=status_changed`); an unknown value returns
`422` naming it. No `events` parameter means every event type. Selections are
de-duplicated and capped at the number of event types that exist. Filtering
narrows `data`, `meta.total` and `meta.last_page`; it does **not** narrow
`meta.event_counts`.
```

and one row to that section's field table:

| Field | Type | Notes |
|---|---|---|
| `meta.event_counts` | object | `{event: count}` for every event type **present on this ticket**, unaffected by `events`. Drives the SPA's filter options, so a new event becomes filterable with no client change. |

and one paragraph at the end of the section:

```
Pagination is offset-based and the trail is append-only (TM-48), so a page
fetched later may repeat rows the caller already holds -- when new rows arrive
at the head, the window shifts backwards over data already seen. It can never
skip a row. Clients that append pages must de-duplicate by `id`; the SPA does.
```

**Do not backfill `GET /api/v1/tickets/{ticket}` or `POST /api/v1/tickets`** — still absent, still TM-22/TM-26's debt, still named in the PR rather than silently fixed. Story 39's plan made the same call.

### 5 — `docs/erd.md` is not edited

No schema change, no index change. `grep -n "ticket_activities" docs/erd.md` should show Story 38's entity and nothing this story needs to add.

### 6 — Backend tests

**Create file: `backend/tests/Feature/Activity/TimelineFilterTest.php`** — `RefreshDatabase` + `$this->seed()`.

Rows go in through `ActivityRecorder` inside `DB::transaction`, never `TicketActivity::create()`. The class needs its **own `private` `makeTicket()`** — copy Story 39's task 6 helper verbatim and **read the fourth decision before considering the extraction.**

The bulk fixture uses `recordMany()`'s array-of-ids signature and distributes rows across `TicketActivityEvent::cases()`, so **the test never needs editing when a story adds an event:**

```php
    /** @return array<string, int> the expected facet */
    private function fill(Ticket $ticket, int $perEvent): array
    {
        $expected = [];
        DB::transaction(function () use ($ticket, $perEvent, &$expected): void {
            $recorder = app(ActivityRecorder::class);
            foreach (TicketActivityEvent::cases() as $case) {
                $recorder->recordMany(array_fill(0, $perEvent, $ticket->getKey()), $case, [
                    'user_id' => null, 'field' => null, 'old_value' => null,
                    'new_value' => null, 'meta' => [],
                ]);
                $expected[$case->value] = $perEvent;
            }
        });

        return $expected;
    }
```

`recordMany()` stamps **one `created_at` for the whole batch** (`ActivityRecorder.php:29`), which makes this fixture a hard test of Story 39's `id DESC` tie-break as a side effect — say so in a comment.

See the Test Plan for the numbered list.

---

## Frontend Tasks

### 7 — The client takes a query object

**File: `frontend/src/api/activities.ts`** *(Story 39's file)*

Story 39's signature is `listTicketActivities(ticketId: number, page = 1)`. It becomes a query object, following `users.ts:29–36`.

```ts
export interface ActivityListQuery {
  page?: number
  events?: string[]
}

export interface ActivityMeta extends Paginated<TicketActivity>['meta'] {
  event_counts: Record<string, number>
}

export type ActivityPage = Omit<Paginated<TicketActivity>, 'meta'> & {
  meta: ActivityMeta
}

export async function listTicketActivities(
  ticketId: number,
  query: ActivityListQuery = {},
): Promise<ActivityPage> {
  const { data } = await client.get<ActivityPage>(
    `/tickets/${ticketId}/activities`,
    { params: query },
  )
  return data
}
```

- **This is a breaking signature change with exactly one caller** — `loadActivities` in `frontend/src/stores/tickets.ts`, updated in task 8. `npx vue-tsc -b` fails loudly if a second appears. **Confirm the count before editing:** `grep -rn "listTicketActivities" frontend/src/`.
- **`Omit<…, 'meta'> & { meta: ActivityMeta }`, not `interface ActivityPage extends Paginated<…>`.** Narrowing an inherited property in an `interface extends` is a TypeScript error; the intersection is the shape that compiles. **Do not edit `pagination.ts`** to add `event_counts` — it is shared with `listUsers`, which has no facet.
- **`events?: string[]`, not a union of event values.** Same reason Story 39 typed `event` as `string`: a union would have to enumerate events that do not exist yet, and would reject a legitimate payload the day one lands.
- **Axios brackets array params by default**, so `{ events: ['a','b'] }` is serialized `events[]=a&events[]=b`, which is what Laravel's `events.*` rule reads. Test 16 pins the serialized URL rather than trusting it.

### 8 — Append, filter and facet in the store

**File: `frontend/src/stores/tickets.ts`** *(Story 39's task 9 added the timeline state; this extends it)*

**Do not touch `creating` (7), the detail state (8), `create` (9) or `loadTicket` (10).** Story 39's `loadActivities` **is** edited — it is the page-1 loader and it now carries the filter and the facet.

```ts
const activityEvents = ref<string[]>([])
const activityCounts = ref<Record<string, number>>({})
const activitiesLoadingMore = ref(false)
const activitiesMoreError = ref<string | null>(null)
// Set by loadActivities so loadMoreActivities() and setActivityFilter() need no
// id. TicketTimeline.vue takes no props (Story 39's design) and must not grow
// one just to append a page.
const activitiesTicketId = ref<number | null>(null)
```

Inside Story 39's `loadActivities(id)`, add `activitiesTicketId.value = id`, clear `activitiesMoreError`, pass the filter, and store the facet:

```ts
    const response = await listTicketActivities(id, {
      page: 1,
      events: activityEvents.value.length ? activityEvents.value : undefined,
    })
    if (request !== latestActivitiesRequest) return
    activities.value = response.data
    activitiesMeta.value = response.meta
    activityCounts.value = response.meta.event_counts
```

and in its `catch`, beside the existing resets, add `activityCounts.value = {}`.

**`events: … : undefined`, not `events: activityEvents.value`.** An empty array serializes to nothing useful and would send a bare `events` key; `undefined` makes axios omit the parameter entirely, which is what "no filter" means on the wire.

Then three new functions:

```ts
const activitiesHasMore = computed(
  () =>
    activitiesMeta.value !== null &&
    activitiesMeta.value.current_page < activitiesMeta.value.last_page,
)

async function loadMoreActivities(): Promise<void> {
  const id = activitiesTicketId.value
  const meta = activitiesMeta.value
  if (id === null || meta === null) return
  if (activitiesLoadingMore.value || meta.current_page >= meta.last_page) return
  // Read, never increment. Incrementing would cancel an in-flight
  // loadActivities; reading means a loadActivities that starts while this page
  // is in flight discards this one instead. Compare stores/users.ts:19,30.
  const request = latestActivitiesRequest
  activitiesLoadingMore.value = true
  activitiesMoreError.value = null
  try {
    const response = await listTicketActivities(id, {
      page: meta.current_page + 1,
      events: activityEvents.value.length ? activityEvents.value : undefined,
    })
    if (request !== latestActivitiesRequest) return
    // Offset pagination on an append-only table can repeat a row but never
    // skip one -- see TM-49's third decision. appendUnique drops the repeat.
    activities.value = appendUnique(activities.value, response.data)
    activitiesMeta.value = response.meta
    activityCounts.value = response.meta.event_counts
  } catch (caughtError) {
    if (request !== latestActivitiesRequest) return
    // NOT activitiesError: TicketTimeline.vue renders the error *instead of*
    // the list, so a failed append would blank the rows already on screen.
    activitiesMoreError.value = errorMessage(caughtError)
  } finally {
    if (request === latestActivitiesRequest) activitiesLoadingMore.value = false
  }
}

async function setActivityFilter(events: string[]): Promise<void> {
  activityEvents.value = events
  if (activitiesTicketId.value !== null) {
    await loadActivities(activitiesTicketId.value)
  }
}
```

Add `activityEvents`, `activityCounts`, `activitiesLoadingMore`, `activitiesMoreError`, `activitiesHasMore`, `loadMoreActivities` and `setActivityFilter` to the `return` at **11**. Import `computed` from `vue` beside `ref`.

**Four constraints:**

- **`activitiesLoadingMore` is separate from `activitiesLoading`.** `TicketTimeline.vue` renders `timeline-loading` *instead of* the list, so reusing it would make every append flash the list away and scroll the reader to the top — a direct AC4 violation.
- **The early return on `activitiesLoadingMore` is the primary duplicate guard**, and `appendUnique` is the backstop. Two rapid presses read the same `latestActivitiesRequest` and would both append page N+1; the flag stops the second, and `appendUnique` makes it harmless if the flag is ever removed.
- **`setActivityFilter` awaits `loadActivities`, which resets to page 1** by construction — it is Story 39's page-1 loader and takes no page argument. Do not add one.
- **`activitiesTicketId` is set in `loadActivities`, never from a component.** Story 39's `TicketTimeline.vue` takes no props and `TicketDetailView.vue:9` already passes the id to `loadActivities`; that is the single source.

**Create file: `frontend/src/lib/appendUnique.ts`**

```ts
/**
 * Append `incoming` to `existing`, dropping any entry whose id is already
 * present. Offset pagination over an append-only, newest-first trail can hand
 * back rows the caller already holds when new rows arrive at the head; it can
 * never skip one. See TM-49's third decision.
 */
export function appendUnique<T extends { id: number }>(
  existing: T[],
  incoming: T[],
): T[] {
  const seen = new Set(existing.map((entry) => entry.id))
  return [...existing, ...incoming.filter((entry) => !seen.has(entry.id))]
}
```

**Generic over `{ id: number }`, and it returns a new array.** Pushing in place would not trigger Pinia's reactivity reliably for a `ref<T[]>` read across components, and the pure form is what test 18 exercises directly.

### 9 — Keep `addNote` honest about the filter *(only if Story 40 has landed)*

**File: `frontend/src/stores/tickets.ts`** — Story 40's task 13 `addNote`.

`grep -n "async function addNote" frontend/src/stores/tickets.ts`. **No hit → skip this task entirely** and record in the PR that TM-47's task 13 must be written against this story's filter.

Story 40's `addNote` prepends the created row and bumps `activitiesMeta.total`. Two additions:

```ts
    const created = await addTicketNote(id, body)
    const events = activityEvents.value
    // A note must not appear in a list filtered to exclude it -- the reader
    // would see one entry that contradicts the filter they just set.
    if (events.length === 0 || events.includes(created.event)) {
      activities.value = [created, ...activities.value]
      if (activitiesMeta.value) activitiesMeta.value.total += 1
    }
    activityCounts.value[created.event] =
      (activityCounts.value[created.event] ?? 0) + 1
```

**The count rises either way** — the note exists on the ticket whether or not the current filter shows it, and the facet describes the ticket, not the view. That is the same rule as task 1's `eventCounts`.

**Story 40's plan file is read-only; its store code is code, and this story owns this edit.** Story 41's plan set that precedent explicitly for Story 38's test. Record it in the PR.

### 10 — The filter control

**Create file: `frontend/src/components/TicketTimelineFilter.vue`**

Options come from `store.activityCounts`, so the component has no event vocabulary at all.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { eventTypeLabel } from '../lib/activityEvents'
import { useTicketsStore } from '../stores/tickets'
const store = useTicketsStore()
const options = computed(() =>
  Object.entries(store.activityCounts)
    .map(([event, count]) => ({ event, count, label: eventTypeLabel(event) }))
    .sort((a, b) => a.label.localeCompare(b.label)),
)
function toggle(event: string): void {
  const selected = store.activityEvents.includes(event)
    ? store.activityEvents.filter((candidate) => candidate !== event)
    : [...store.activityEvents, event]
  void store.setActivityFilter(selected)
}
</script>
<template>
  <div v-if="options.length > 1" class="filter" data-testid="timeline-filter">
    <button
      type="button"
      data-testid="timeline-filter-all"
      :aria-pressed="store.activityEvents.length === 0"
      :disabled="store.activityEvents.length === 0"
      @click="void store.setActivityFilter([])"
    >
      All
    </button>
    <label
      v-for="option in options"
      :key="option.event"
      class="option"
      data-testid="timeline-filter-option"
      :data-event="option.event"
    >
      <input
        type="checkbox"
        :checked="store.activityEvents.includes(option.event)"
        @change="toggle(option.event)"
      />
      {{ option.label }} ({{ option.count }})
    </label>
  </div>
</template>
<style scoped>
.filter {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  padding-bottom: 8px;
}
.option {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  font-size: 0.875rem;
}
</style>
```

**Four constraints:**

- **`v-if="options.length > 1"`.** A ticket whose entire trail is one event type has nothing to filter; rendering a single checkbox that can only ever be on would be noise. A brand-new ticket has exactly one `created` row, which is the common case.
- **Checkboxes, not a `<select>`.** AC2's examples are single types, but a reader narrowing to "notes and status changes" is the same feature and multi-select costs nothing on either side — the backend rule is already an array.
- **`localeCompare` on the label, not on the event value.** The counts arrive `ORDER BY event` from task 1; the reader sees labels, so the labels are what should be alphabetical.
- **`store.activityEvents` is read, never assigned.** Every change goes through `setActivityFilter`, which reloads. Assigning the ref directly would change the filter without refetching and leave the list contradicting the checkboxes.

**File: `frontend/src/lib/activityEvents.ts`** *(Story 39's file — one addition)*

```ts
// The event *type* as a label, for the filter. Deliberately not eventPhrase():
// that composes a sentence fragment about an actor ("added note"), which is the
// wrong grammar for an option in a list. Derived from the value's shape, so no
// story that adds an event has to edit this file.
export function eventTypeLabel(event: string): string {
  const words = event.replace(/_/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}
```

`created` → *"Created"*, `note_added` → *"Note added"*, `status_changed` → *"Status changed"*, `some_future_event` → *"Some future event"*. **Do not add a label map**, and **do not touch `DESCRIPTORS`, `FALLBACK` or `eventDescriptor`.**

### 11 — Load more, and memoise the entry

**File: `frontend/src/components/TicketTimeline.vue`** *(Story 39's file)*

Mount the filter above the list and **replace Story 39's `timeline-truncated` paragraph** — its plan said *"a statement, not a button… Append-on-demand is TM-49's first criterion by name"*, and this is that story.

```vue
<script setup lang="ts">
import TicketTimelineEntry from './TicketTimelineEntry.vue'
import TicketTimelineFilter from './TicketTimelineFilter.vue'
import { useTicketsStore } from '../stores/tickets'
const store = useTicketsStore()
</script>
```

In the template, `<TicketTimelineFilter />` goes **immediately after `<h3>History</h3>`** and before the loading/error/empty branches, so the controls do not appear and disappear as the list changes state. Then, replacing the `timeline-truncated` block inside the `<template v-else>`:

```vue
      <p data-testid="timeline-count">
        Showing {{ store.activities.length }} of
        {{ store.activitiesMeta?.total ?? store.activities.length }} entries.
      </p>
      <button
        v-if="store.activitiesHasMore"
        type="button"
        data-testid="timeline-load-more"
        :disabled="store.activitiesLoadingMore"
        @click="void store.loadMoreActivities()"
      >
        {{ store.activitiesLoadingMore ? 'Loading...' : 'Load older entries' }}
      </button>
      <p v-if="store.activitiesMoreError" data-testid="timeline-more-error">
        {{ store.activitiesMoreError }}
      </p>
```

**The count, the button and the error all sit *below* the `<ol>`.** AC4 is *"the most recent entries are always visible first without extra interaction"*: the filter is the only control above the list, appended rows go underneath, and **the entry at `activities[0]` never moves.** Test 19 asserts exactly that.

**Do not touch** `timeline-loading`, `timeline-error`, `timeline-empty`, the `<ol data-testid="timeline-list">` or its `v-for` key.

**File: `frontend/src/components/TicketTimelineEntry.vue`** *(Story 39's file — the AC3 render fix)*

Story 39's template calls `activitySentence(activity)` once and `activityReason(activity)` **twice** — in the `v-if` and in the body. Add two `computed`s beside its existing `descriptor` / `iconColor` / `isSystem`:

```ts
const sentence = computed(() => activitySentence(props.activity))
const reason = computed(() => activityReason(props.activity))
```

and use them in the template: `{{ sentence }}`, `v-if="reason"`, `{{ reason }}`.

**Nothing else in this file changes** — not the icon, not `data-system`, not `timeline-values`, not Story 40's `note` block (which is already a `computed`), not one CSS rule. Three functions become three cached reads per entry per render; at 600 entries that is 1800 calls saved. **`relativeAge` is deliberately left as a template call**: it reads `Date.now()`, so caching it would freeze the label, and it is one cheap call rather than two.

---

## Edge Cases & Failure Modes

- **A row arrives at the head between page 1 and page 2.** Offset shifts by *k*, so page 2 returns *k* rows the client already holds — and, because the table is append-only and newest-first, **skips none**. `appendUnique` (`frontend/src/lib/appendUnique.ts`) drops the repeats by `id`. Test 15 constructs it; the third decision proves it.
- **Two rapid presses of Load more.** Both read the same `latestActivitiesRequest`, so neither cancels the other. `activitiesLoadingMore`'s early return stops the second before it fires, and `appendUnique` makes it harmless if that guard is ever removed. Test 12.
- **Navigating to another ticket while a page is appending.** `loadActivities` increments `latestActivitiesRequest`; `loadMoreActivities` only ever **reads** it, so the in-flight append fails its post-`await` comparison and is discarded rather than appended under the wrong subject. **Reversing that — incrementing in `loadMoreActivities` — would make an append cancel a ticket switch**, which is the bug in the other direction. Test 13.
- **Load more fails on the network.** `activitiesMoreError` is set, **not** `activitiesError`. `TicketTimeline.vue` renders `timeline-error` *instead of* the list (Story 39's `v-else-if` chain), so reusing it would erase the rows the reader is holding. Test 14.
- **Selecting a filter that matches nothing on this ticket.** Cannot happen through the UI — options come from `event_counts`, which only lists events present. A hand-crafted request returns `200` with `data: []`, `meta.total: 0`, and the full `event_counts`, so `timeline-empty` renders **and the filter still offers every other type**. Test 6.
- **`events[]=not_an_event`.** `422` with *"Unknown activity event type: not_an_event."* from task 1's `events.*.in` message. **`events[]=CREATED`** is also `422` — `Rule::in` is case-sensitive and enum values are lower snake case. Test 3.
- **`events` sent as a scalar** (`?events=created`). `422` from the `array` rule. Axios never produces this from `string[]`, but a curl user will. Test 3.
- **`events[]` repeated with the same value.** `array_unique` collapses it; the response is identical to sending it once. Test 7.
- **Filtering while on page 4.** `setActivityFilter` calls `loadActivities`, which fetches page 1 and **replaces** `activities` wholesale. The reader is returned to the newest matching entry — which is AC4, not a regression. Test 20.
- **A filter active when a note is added** *(Story 40 only)*. If the filter excludes `note_added` the row is **not** prepended, but `event_counts.note_added` still rises — so the reader sees the count change and can clear the filter to find it. Task 9.
- **`activitiesMeta` is `null` when Load more is pressed.** Only reachable if the first load failed, in which case the button is not rendered (`activitiesHasMore` is `false`). The `meta === null` early return covers a caller that reaches into the store directly.
- **A ticket with exactly one event type.** `TicketTimelineFilter` renders nothing (`options.length > 1`). Every new ticket is in this state — one `created` row from `TicketController.php:44` — so **this is the common case, not the corner.** Test 21.
- **A ticket with zero activities.** `event_counts` is `{}`, the filter renders nothing, `timeline-empty` renders, and `timeline-count` is not reached (it is inside Story 39's `v-else`). Reachable only for a hand-built ticket; `POST /api/v1/tickets` always writes a `created` row.
- **An event value in the database that is not in the enum.** Unchanged from Story 39: the `event` cast throws a `ValueError` and the **whole page 500s**. The facet would surface it too — `event_counts` is built from raw column values via `pluck` and would happily report it, while the page query dies on the cast. **No defensive coercion is added**; a corrupt audit trail must fail loudly. Story 38's `tryFrom` round-trip test is the defence.
- **`per_page=100` with a filter.** Legal, and the interaction is the point of test 9: 100 rows is the cap Story 39 set, and `event_counts` is still one `GROUP BY` regardless.
- **`meta.total` under a filter is the filtered total.** `timeline-count` therefore reads *"Showing 20 of 37 entries"* while the filter is on and *"of 412"* when it is cleared. **That is correct** — it describes what the reader is looking at. The unfiltered per-type totals are always in `event_counts`.
- **`array_merge_recursive` and a colliding meta key.** Naming the facet `total` would produce `"total": [412, {...}]` rather than an overwrite (**PaginatedResourceResponse.php:20–24**). `event_counts` collides with nothing in `pagination.ts:9–17`. Recorded because the failure is silent and the JSON still parses.
- **A soft-deleted ticket.** `404` from route model binding, unchanged from Story 39. The filter never reaches the controller.

---

## Test Plan

### Backend — `backend/tests/Feature/Activity/TimelineFilterTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Own **`private`** `makeTicket()` (fourth decision) and the `fill()` helper from task 6. Rows go through `ActivityRecorder` inside `DB::transaction`; **never `TicketActivity::create()`** — Story 38's single-writer tripwire fails the build otherwise.

1. `test_the_facet_lists_every_event_type_on_the_ticket_with_counts` — `fill($ticket, 3)`; assert `meta.event_counts` equals `fill`'s return value exactly, and that its keys are **plain strings** and its values **integers**, not enum instances and not numeric strings. **This is the test that fails if the aggregate column is named `event`** (sixth decision). **AC2.**
2. `test_filtering_narrows_data_and_total` — `fill($ticket, 3)`; request `events[]=created`; assert every returned `event` is `created`, `meta.total` is `3`, and the response's `data` count is `3`. Then request two event values and assert `meta.total` is `6`. **AC2.**
3. `test_an_unknown_or_malformed_event_filter_is_rejected` — `events[]=not_an_event` → `422` with `errors.events.0` containing `not_an_event`; `events[]=CREATED` → `422` (case-sensitive); `events=created` as a scalar → `422` (the `array` rule); an array longer than `count(TicketActivityEvent::values())` → `422`.
4. `test_a_new_event_case_becomes_filterable_with_no_change_to_this_story` — **the extensibility contract, and the reason the rule is `Rule::in(TicketActivityEvent::values())`.** Assert `TicketActivityEvent::values()` is what the controller validates against by filtering on **every** case in `TicketActivityEvent::cases()` in a loop and asserting `200` for each. When Story 32 adds `StatusChanged`, this test covers it with no edit. **Add a comment saying exactly that**, so nobody "tidies" the loop into a literal list.
5. `test_the_facet_ignores_the_active_filter` — `fill($ticket, 3)`, request `events[]=created`, and assert `meta.event_counts` **still has an entry for every case**, not just `created`. **The bug this pins — a facet that collapses to the current selection, leaving the reader no way back — is the second decision's whole subject.**
6. `test_a_filter_matching_nothing_returns_an_empty_page_and_a_full_facet` — record **only** `created` rows, filter on a different case, assert `200`, `data` empty, `meta.total` `0`, and `event_counts` containing `created`.
7. `test_a_repeated_event_value_changes_nothing` — `events[]=created&events[]=created` returns the same body as `events[]=created`.
8. `test_the_query_count_does_not_grow_with_the_trail` — **AC3, in its testable form.** Count queries with `DB::listen` for a ticket with **3** rows and for one with **400** (`fill($ticket, 100)` across four cases, or `array_fill` if fewer cases exist); assert the two counts are **equal** and **≤ 5** (ticket binding, page count, page rows, actors, facet). Repeat once with a filter applied and assert the count is unchanged. **This fails the day `->with('user')` is dropped or the facet becomes a per-event query.**
9. `test_a_four_hundred_row_ticket_returns_only_one_page` — `fill($ticket, 100)`; assert `data` has **20** items, `meta.per_page` is `20`, `meta.total` is the full count, `meta.last_page` is `ceil(total / 20)`. Then `per_page=100` → 100 items; `per_page=101` → `422`. **AC1, AC3.**
10. `test_paging_through_a_filtered_trail_never_repeats_an_id` — `fill($ticket, 25)`; with a single-event filter and `per_page=10`, walk every page and assert the union of ids has **no duplicate** and its size equals `meta.total`. **Every row in a `fill()` batch shares one `created_at` (`ActivityRecorder.php:29`), so this is a real stress of Story 39's `id DESC` tie-break, not a hypothetical** — say so in a comment.
11. `test_the_filter_does_not_widen_access` — an agent token reads an admin-created ticket's filtered timeline → `200`; no token → `401`; unknown id → `404`. **The filter must not have become a second authorization path.**

### Frontend — new

12. `frontend/src/stores/tickets.spec.ts` — mock `../api/activities` as `HealthView.spec.ts:9` mocks `../api/health`; `setActivePinia(createPinia())` in `beforeEach`.
    - a. `loadActivities` sends `{ page: 1, events: undefined }` when no filter is set, and stores `meta.event_counts` into `activityCounts`.
    - b. `setActivityFilter(['created'])` sends `events: ['created']` **and `page: 1`**, and replaces `activities` rather than appending.
    - c. `loadMoreActivities()` sends `page: 2` with the **same** `events`, and `activities` afterwards is page 1 **followed by** page 2.
    - d. `loadMoreActivities()` is a no-op when `activitiesMeta` is `null`, and when `current_page >= last_page` — assert the client was **not called**.
    - e. two `loadMoreActivities()` calls fired without awaiting the first result in only **one** request (the `activitiesLoadingMore` guard).
13. `frontend/src/stores/tickets.spec.ts` (same file) — **the stale-append case.** Call `loadActivities(1)`, then `loadMoreActivities()`, then `loadActivities(2)`, resolving the append **last**; assert `activities` holds ticket 2's page 1 and **none** of the appended rows. Then the reverse: an append that resolves while no new `loadActivities` has run **is** applied.
14. `frontend/src/stores/tickets.spec.ts` (same file) — a rejected `loadMoreActivities` sets `activitiesMoreError`, leaves `activitiesError` **null**, and leaves `activities` **unchanged**. **The assertion that a failed append does not blank the timeline.**
15. `frontend/src/stores/tickets.spec.ts` (same file) — **the head-insert case from the third decision.** Page 1 resolves with ids `[100..81]`; page 2 resolves with ids `[82, 81, 80, …]` — overlapping, as a real head insert produces. Assert the merged list has **no duplicate id**, is still descending, and contains every id from both responses exactly once.
16. `frontend/src/api/activities.spec.ts` — following `frontend/src/api/client.spec.ts`'s shape. Assert `listTicketActivities(7, { page: 2, events: ['a', 'b'] })` issues a GET whose URL carries `events[]=a` and `events[]=b` and `page=2`, and that `listTicketActivities(7)` sends **no** `events` parameter at all. **Pins the axios array serialization the backend rule depends on.**
17. `frontend/src/components/TicketTimeline.spec.ts` (**modified** — Story 39 creates it)
    - a. `activitiesHasMore` true → `timeline-load-more` renders; false → absent. Story 39's `timeline-truncated` is **gone**; assert `wrapper.find('[data-testid="timeline-truncated"]').exists()` is `false`.
    - b. pressing `timeline-load-more` calls `store.loadMoreActivities`.
    - c. `activitiesLoadingMore` true → the button is `disabled` and reads `Loading...`, **and `timeline-list` is still rendered** with its rows. **The AC4 assertion: appending must not blank the list.**
    - d. `activitiesMoreError` set → `timeline-more-error` renders **and** `timeline-list` still renders.
    - e. `timeline-count` reads `Showing 20 of 412 entries.` from `activities.length` and `activitiesMeta.total`.
    - f. **20 activities in the store → exactly 20 `timeline-entry` nodes in the DOM.** AC3's render-side bound.
18. `frontend/src/lib/appendUnique.spec.ts` — disjoint arrays concatenate; a fully overlapping second array is a no-op; a partial overlap keeps existing order and appends only the new ids; empty `existing`; empty `incoming`; and the input arrays are **not mutated**.
19. `frontend/src/components/TicketTimeline.spec.ts` (same file) — **AC4, directly.** With 20 entries rendered, append 20 more and assert the **first** `timeline-entry`'s `data-event` and text are **unchanged** and it is still the first node. Then set a filter and assert the first node is the newest **matching** entry.
20. `frontend/src/components/TicketTimelineFilter.spec.ts` (new)
    - a. `activityCounts` with three types → three `timeline-filter-option` nodes, labels *"Category changed (4)"* / *"Created (1)"* / *"Note added (37)"* in that (alphabetical) order, each carrying its `data-event`.
    - b. checking an option calls `setActivityFilter` with `[event]`; checking a second calls it with **both**; unchecking calls it with the remainder.
    - c. `timeline-filter-all` is `disabled` when `activityEvents` is empty and calls `setActivityFilter([])` when pressed.
    - d. a **fictional** event key in `activityCounts` renders as a label with no error — the filter has no vocabulary of its own. **The client half of test 4's contract.**
21. `frontend/src/components/TicketTimelineFilter.spec.ts` (same file) — `activityCounts` with **one** type renders **nothing** (`timeline-filter` absent); with `{}` likewise.
22. `frontend/src/lib/activityEvents.spec.ts` (**modified** — Story 39 creates it) — `eventTypeLabel`: `created` → `'Created'`, `note_added` → `'Note added'`, `status_changed` → `'Status changed'`, `some_future_event` → `'Some future event'`. **Do not touch Story 39's `eventDescriptor` cases.**

### Frontend — not tested here

`TicketTimelineEntry.vue`'s two new `computed`s are a pure refactor: Story 39's cases 16e–16i already assert the rendered output, and they must keep passing **unchanged**. **A `computed` that returns a different value than the call it replaced would fail those tests**, which is the coverage that matters. Verification step 8 runs them.

---

## Verification Steps

1. **Services:** `docker compose ps` → all three healthy, `tm-mysql-test` on **3307**.
2. **Confirm the prerequisites landed:** `ls backend/app/Http/Controllers/Api/V1/TicketActivityController.php backend/app/Http/Resources/V1/TicketActivityResource.php frontend/src/api/activities.ts frontend/src/lib/activityProse.ts frontend/src/components/TicketTimeline.vue frontend/src/components/TicketTimelineEntry.vue` → **all six present**. Any miss means Story 39 has not landed — **stop.** Also `grep -n "function activities" backend/app/Models/Ticket.php` → one hit (Story 38).
3. **Confirm nothing new was routed:** `git diff --stat backend/routes/api.php backend/tests/Feature/Authorization/RouteAuthorizationTest.php` → **empty**. Then, if Story 41 has landed, `php artisan test --filter=ActivityRouteImmutabilityTest` → green, **including `test_the_only_activity_route_is_a_get`**. That test exists to catch this story adding a route.
4. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
5. **Backend tests:** from `backend/`, `composer test`. Expect **+11 tests** and the same pre-existing failure set as before this story — one failure, `Auth\PasswordThrottleTest`, owned by TM-14. **No new failure, and no wall of unrelated failures** — a wall would mean the tripwire fired; read its output for the filename.
6. **Measure the filtered read, and put the numbers in the PR.** With a 400-row ticket in the dev database (`php artisan tinker`, `fill()`-style `recordMany(array_fill(0, 100, $id), …)` per case, inside `DB::transaction`):
   ```
   php artisan tinker --execute="dump(DB::select(\"EXPLAIN SELECT * FROM ticket_activities WHERE ticket_id = 1 AND event IN ('created') ORDER BY created_at DESC, id DESC LIMIT 20\"));"
   php artisan tinker --execute="dump(DB::select('EXPLAIN SELECT event, COUNT(*) FROM ticket_activities WHERE ticket_id = 1 GROUP BY event'));"
   ```
   Record `key`, `rows` and `Extra` for both. `key` should be **`ticket_activities_timeline_index`**. **If either shows `Using filesort`, or `rows` is far above 20 on the filtered read, say so in the PR with the number** and attach the `(ticket_id, event, created_at)` recommendation from the fifth decision. **Do not add the index in this story.**
7. **Prove test 8 earns its place:** delete `->with('user')` from `TicketActivityController`, re-run `--filter=test_the_query_count_does_not_grow_with_the_trail`. It must **fail on unequal counts**. Restore.
8. **Prove the entry refactor changed no output:** from `frontend/`, `npx vitest run src/components/TicketTimelineEntry.spec.ts src/components/TicketTimeline.spec.ts` → Story 39's cases pass **unchanged** except the two Story 39 cases this story deliberately replaces (`timeline-truncated`). Name those two in the PR.
9. **Frontend:** from `frontend/` — `npx vue-tsc -b` (exit `0`), `npm run lint` (exit `0`, warnings fail), `npm run format:check` (exit `0`), `npm test` (**+4 spec files**, 2 modified, all green).
10. **Prove the extensibility contract, which is the point of the whole story.** Temporarily add `case Escalated = 'escalated';` to `backend/app/Enums/TicketActivityEvent.php`, record one such row through tinker, and reload a ticket detail page. **Without editing one file in this story or Story 39:** the entry renders through Story 39's grey fallback, *"Escalated (1)"* appears in the filter, selecting it returns that one row, and `php artisan test --filter=test_a_new_event_case_becomes_filterable` still passes. **Then remove the case and the row.** Record the result in the PR — this is the evidence for the second decision.
11. **By hand,** `php artisan serve` in `backend/` and `npm run dev` in `frontend/`, signed in as the seeded admin:
    - File a ticket, then in tinker record ~400 rows across the existing cases. Open `/tickets/<id>`.
    - **First paint:** 20 entries, newest at top, *"Showing 20 of 400 entries."*, a **Load older entries** button below the list, and a filter row above it with one option per event type and its count.
    - Press **Load older entries** three times. Each press appends 20 **below**; the top entry never moves; the count climbs to 80; the list never flashes empty.
    - Check one filter option. The list reloads to page 1 of that type only, the count changes to that type's total, and **every other option keeps its full count** and stays selectable. Press **All** to clear.
    - Throttle the network and press **Load older entries**, then navigate to another ticket before it resolves. The stale page is **not** appended to the second ticket.
    - Block the request (devtools) and press **Load older entries**. An error appears beside the button and **the 20 rows stay on screen.**
    - `curl -H "Authorization: Bearer <token>" "http://localhost:8000/api/v1/tickets/<id>/activities?events[]=created" | jq '.meta'` → pagination keys **plus** `event_counts`; `event_counts` lists **every** type on the ticket, not just `created`; `total` is a **number**, not an array. Then `?events[]=nope` → `422` naming `nope`.
12. **Regression:** `git status` shows **no file under `backend/database/`**, and no change to `backend/routes/api.php`, `RouteAuthorizationTest.php`, `ActivityRecorder.php`, `TicketActivity.php`, `TicketActivityEvent.php`, `TicketActivityResource.php`, `TicketController.php`, `TicketResource.php`, `docs/erd.md`, any policy, any seeder, `frontend/src/api/pagination.ts`, `frontend/src/lib/activityProse.ts`, `frontend/src/views/TicketDetailView.vue`, `frontend/src/lib/relativeTime.ts`, `composer.json` or `package.json`. `grep -rn "v-html" frontend/src/` → **no output**. `grep -rn "DB::table('ticket_activities')\|DB::table(\"ticket_activities\")" backend/app/` → **no output**.

---

## Done Criteria

- [ ] The timeline loads **one page** and appends the next on demand: `loadMoreActivities()` fetches `page + 1` and **appends below**, and a test asserts the merged list is page 1 followed by page 2 with `activities[0]` **unchanged**.
- [ ] Appending is **lossless and duplicate-free** through `appendUnique()` keyed on `id`, and a test constructs the real head-insert overlap that offset pagination produces on an append-only trail. The reasoning — *repeats are possible, skips are not, because the table is append-only and newest-first* — is written into `docs/api-contract.md` for other clients.
- [ ] Entries can be filtered by **one or more event types** via `?events[]=…`, validated with **`Rule::in(TicketActivityEvent::values())`** and capped at `count(TicketActivityEvent::values())` — **no hard-coded event list anywhere in this story**, backend or frontend.
- [ ] The filter's options come from **`meta.event_counts`**, one entry per event type **present on this ticket**, with counts. An option that could only return zero rows is never shown, and a ticket with a single event type shows **no filter at all**.
- [ ] **`meta.event_counts` ignores the active filter**, and a test pins it — a facet that collapsed to the current selection would leave the reader no way back.
- [ ] **A new event case becomes both renderable and filterable with no edit to this story's files or Story 39's**, proven twice: a backend test that loops `TicketActivityEvent::cases()`, and verification step 10, which adds a case by hand and records the result in the PR.
- [ ] **AC3 is asserted as bounded work, not wall-clock:** the query count for a **400-row** ticket **equals** the count for a 3-row ticket and is ≤ 5, filtered or not; the response carries at most `per_page` rows; the DOM carries at most `per_page` entries; and the facet is **one `GROUP BY`**, not one query per type.
- [ ] Story 39's `TicketTimelineEntry.vue` no longer calls `activityReason` **twice per render** — both it and `activitySentence` are `computed` — and Story 39's own rendering tests pass **unchanged**, which is what proves the refactor changed no output.
- [ ] The **newest entry never moves**: the filter is the only control above the list, the count/button/error sit below it, appending adds rows underneath, a filter change reloads to page 1, and a test asserts `activities[0]` is stable across an append.
- [ ] A **failed append leaves the rows on screen** — `activitiesMoreError` is separate from `activitiesError`, and a test asserts `activities` is unchanged and `activitiesError` still `null`.
- [ ] An **appending page is discarded when the reader switches tickets**, because `loadMoreActivities` **reads** `latestActivitiesRequest` and never increments it — so a ticket switch cancels an append and never the reverse. Both directions tested.
- [ ] **No route is added**, `backend/routes/api.php` and `RouteAuthorizationTest::ACCESS` are untouched, and Story 41's `test_the_only_activity_route_is_a_get` and `test_no_route_mutates_an_activity` both still pass. The first decision records that this is an assertion in a test file, not a preference.
- [ ] The facet query goes through **`$ticket->activities()`** — never `DB::table('ticket_activities')`, which Story 38's source-text tripwire matches even for a read — and its aggregate column is named **`aggregate`, never `event`**, because `Builder::pluck()` (**Builder.php:1097–1100**) casts the value column and would return enum instances.
- [ ] **`event_counts` collides with no pagination `meta` key**, because `additional` is merged with `array_merge_recursive` (**PaginatedResourceResponse.php:20–24**), which turns a colliding scalar into an array of both values rather than overwriting.
- [ ] `docs/api-contract.md`'s existing activities section is **extended, not duplicated**: the `events` parameter, the `422`, the `meta.event_counts` field and its filter-independence, and the offset/append caveat. The endpoints table is untouched, and the two undocumented `/tickets` endpoints stay **TM-22/TM-26's debt, named in the PR**.
- [ ] The fourth `private makeTicket()` copy is added **and not extracted** — a `protected` parent method is a **fatal error** against three subclasses that declare it `private`. The duplication is named in the PR as **TM-59's** to collapse.
- [ ] **No migration, no column, no index change, no new composer or npm package, no virtual scrolling, no icon library**, and no change to `ActivityRecorder`, `TicketActivity`, `TicketActivityEvent`, `TicketActivityResource`, `TicketController`, `TicketResource`, `pagination.ts`, `activityProse.ts`, `TicketDetailView.vue`, `docs/erd.md` or any seeder. The `(ticket_id, event, created_at)` index is **recommended in the PR with verification step 6's numbers attached**, not taken.
- [ ] Every edit to Story 39's and Story 40's files is **additive or a named replacement**, enumerated in the PR: task 1's validation + `->when` + facet, task 4's doc paragraphs, task 7's client signature, task 8's store additions and the `loadActivities` filter/facet lines, task 9's `addNote` filter check *(if Story 40 landed)*, task 10's `eventTypeLabel`, and task 11's filter mount, `timeline-truncated` → count-and-button swap, and two `computed`s.
- [ ] `pint --test`, `vue-tsc -b`, `npm run lint`, `npm run format:check` all exit `0`; **+11 backend tests**; **+4 frontend spec files, 2 modified**; one pre-existing failure remains (`PasswordThrottleTest`, TM-14).

**STOP HERE. Report to the user and wait for confirmation. This is the last story in epic E7 — `.squad/plans/email-notifications/00-overview.md` is still an unplanned stub, so there is no Story 43 to proceed to.**
