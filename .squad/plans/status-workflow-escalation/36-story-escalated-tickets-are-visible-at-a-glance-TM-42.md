# Story 36 — Escalated tickets are visible at a glance (Story: TM-42)

## Prerequisites

- **This story is the most blocked in the epic, and two of its four criteria are already built by other stories.** Read the "What is already delivered" section below **before** writing a line — roughly half of what the acceptance criteria describe is Story 20's and Story 25's work, and re-implementing any of it is the main risk here.
- **Story 19 (TM-23) — PLANNED, NOT IMPLEMENTED. Hard blocker.** `frontend/src/views/TicketListView.vue`, `frontend/src/api/pagination.ts`'s list call, `TicketListItem`, `frontend/src/components/ColorBadge.vue`'s use in the table and `backend`'s `TicketController::index()` all originate there. Verified on disk: `TicketController` has **no `index()`**, `routes/api.php` has **no `tickets.index`**, and `TicketListView.vue` does not exist. **Every file this story's AC1 touches is a file Story 19 creates.**
- **Story 20 (TM-24) — PLANNED, NOT IMPLEMENTED. Hard blocker, and it already ships the `escalated` filter.** Read [`../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md`](../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md) tasks 1, 2, 3, 5, 7 and 8. Its `IndexTicketRequest::SORTS` (**line 144**) is the constant task 1 extends; its `lib/ticketQuery.ts` (**task 7**) is the URL vocabulary task 5 extends; its `filter-escalated` select and `escalated` API parameter are **AC1's filter half, already done**.
- **Story 25 (TM-29) — PLANNED, NOT IMPLEMENTED. Hard blocker for AC2, and it already ships the escalated card.** Read [`../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md`](../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md) task 8. Its `stat-escalated` card and `escalatedTo` link already exist — **but the link is wrong for an agent, and fixing it is this story's only AC2 work.** See the decision section.
- **Story 35 (TM-41) — PLANNED, NOT IMPLEMENTED. Soft blocker.** It is what puts a non-zero `escalation_level` on any ticket. Everything here works against hand-seeded rows and can be built and tested without it, but **nothing is visible in the running app until TM-41 lands.** Its detail-page escalation section is **not** touched by this story.
- **Gate:** `php artisan test --filter='TicketIndexTest|TicketStatsTest'` green and `npx vitest run src/lib/ticketQuery.spec.ts src/views/TicketListView.spec.ts src/views/DashboardView.spec.ts` green **before you start.** If any of those files is missing, its story has not landed.
- **Measured today against `tm-mysql`, on 60 tickets with a third escalated, inside a rolled-back transaction — task 1 rests on it.** `ORDER BY escalated_at DESC, id DESC LIMIT 15`:
  - **Without an index:** `type=ALL, key=NULL, rows=60, Extra=Using where; Using filesort`.
  - **With `index(escalated_at)`:** `type=index, key=tickets_escalated_at_index, rows=15, Extra=Using where; Backward index scan` — **the filesort disappears.** Identical to the profile Story 20 measured for `updated_at`, which is why that story added an index and this one does the same.
  - **NULLs sort last on `DESC` and first on `ASC`.** Measured: `orderByDesc('escalated_at')` returned three real timestamps; `orderBy('escalated_at')` returned three `null`s. **This is exactly what AC3 asks for and needs no `IS NULL` clause.**
- **No new composer or npm dependency.** One migration, adding one index.
- **Baseline:** re-measure after Stories 19, 20 and 25 have landed. As of 2026-08-26 the suite is **101 tests, 98 passing, 3 failing**; this story's baseline is whatever those three stories leave behind.

---

## What is already delivered — do not rebuild it

| Acceptance criterion | Owner | Status |
|---|---|---|
| "The ticket list supports an **escalated filter**" | **Story 20**, tasks 2, 3, 7, 8 | **Done.** `escalated` is a validated boolean parameter (`20-…md:177`), applied as `escalation_level > 0` / `= 0` (**235–237**), carried in the URL as `?escalated=true`, and exposed as the three-option `filter-escalated` select (**456**). |
| "…and shows an **escalation badge with its level**" | **This story** | **To build.** Story 19's table has no escalation cell. |
| "The **dashboard shows an escalated count**" | **Story 25**, tasks 1 and 8 | **Done.** `stats.escalated` (`25-…md:151, 165`) and the `stat-escalated` card (**399**). |
| "…that **links to the filtered list**" | **Story 25**, then **this story** | **Half done, and wrong for agents.** `escalatedTo` links to `?escalated=true` (**388**) while the card's figure is **scoped to the agent**. Task 6 fixes it. |
| "Escalated tickets can be **sorted so the most recently escalated appear first**" | **This story** | **To build.** `SORTS` is `created_at`, `updated_at`, `priority` only (`20-…md:144`). |
| "The badge **distinguishes level one from higher levels**" | **This story** | **To build.** |
| The `escalation_level` index | **Story 20**, task 1 | **Done** (`20-…md:103`). This story adds a **different** index, on `escalated_at`. |
| `escalation_level` and `escalated_at` in the list payload | **Story 22 / TM-26** | **Done.** `TicketResource` emits both unconditionally (`backend/app/Http/Resources/V1/TicketResource.php:22–23`) and `TicketListItem` is `Omit<Ticket, 'description'>` (`19-…md:321`), so **the badge needs no API change.** |

**Four regression tests in this plan (7, 8, 20, 21) exist only to prove the already-delivered half still works after this story touches its neighbours.** They are not duplicates of Stories 20 and 25's tests; they are the seam.

---

## Story Goal

An admin scanning the queue can see which tickets have been escalated, how far, and can bring them to the top — and the dashboard card that says "11 escalated" lands them on exactly those eleven.

1. `GET /api/v1/tickets` accepts `sort=escalated_at`; `direction=desc` puts the most recently escalated first and never-escalated tickets last.
2. `tickets.escalated_at` is indexed, so that sort is a backward index scan rather than a filesort.
3. Every list row shows an escalation badge when `escalation_level > 0`, **amber "Escalated" at level 1 and red "Escalated ×N" above it**, and nothing at level 0.
4. The Escalated column header sorts, and `escalated_at` joins the sort select and the URL vocabulary.
5. The dashboard's escalated card links to a list **filtered the same way the card was counted** — `?escalated=true` for an admin, `?escalated=true&assignee=me` for an agent.

**Not in scope, and each belongs to a named story.** **No new endpoint and no change to `/tickets/stats`'s payload** — Story 25's plan already says any later queue metric should extend `escalated` rather than add an endpoint, and this story adds no metric. **No escalation UI on the ticket detail page** — TM-41's `ticket-escalation` section already announces it there and a second signal would be noise. **No change to how escalation happens** — `TicketController::escalate()`, `TicketPolicy`, the priority ladder and the routing rule are all TM-41's and are untouched. **No de-escalation, no bulk action, no saved views, no "escalated" tab.** **No email or digest** (TM-53). **No auto-escalation** (TM-43). **No change to `escalation_level`'s index**, which Story 20 owns.

---

## Context — Read These Files First

1. [`../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md`](../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md) — **task 1** (the index migration this story copies the shape of, **98–111**), **task 2** (`IndexTicketRequest`, `SORTS` at **144**, `rules()` at **178**, `messages()` at **187**), **task 3** (`applySort()` at **256–264**), **task 5** (`TicketSort`, **325**), **task 7** (`ticketQuery.ts`, `SORTS` at **430**), **task 8** (`filter-sort` at **457**), and **task 10** (sortable headers, `aria-sort`, **505**). **Its note at line 11 matters: TM-25 (search) extends `SORTS` too — expect a merge neighbour.**
2. [`../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md`](../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md) — **task 8, lines 380–402**, especially `escalatedTo` at **388** and the note at **402** that the escalated card's *label* follows `scope` while the unassigned card's does not. **The label already follows scope; the link does not. That asymmetry is the bug task 6 fixes.**
3. [`../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md`](../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md) — **task 12's markup table at 484–498.** The exact `data-testid` values (`tickets-table`, `tickets-row`, `tickets-age`), and the rule at **498** that priority and status use `ColorBadge` while category uses `CategoryBadge`. `TicketListItem` is defined at **321**.
4. `frontend/src/components/ColorBadge.vue` — **2 lines**: `defineProps<{ name: string; color: string }>()` and a `<span :style="{ backgroundColor: color }">`. **Attributes fall through**, which is how Story 19 puts `data-testid="color-badge"` on it (`19-…md:450`) and how task 4 puts its own test id on it.
5. `backend/database/seeders/PrioritySeeder.php:12–15` — the project's palette in practice: `#F59E0B` is Medium's amber and `#EF4444` is Urgent's red. **Task 4 reuses those two hex values rather than inventing colours**, so an escalated badge reads as the same visual language as the priority beside it.
6. `backend/database/migrations/2026_08_26_084625_create_tickets_table.php:26` — `escalated_at` is a nullable `timestamp`. **34–39** are the existing indexes; note that `create_tickets_table` is **not** edited by Story 20 and must not be edited here either.
7. `backend/app/Http/Resources/V1/TicketResource.php:22–23` — `escalation_level` and `escalated_at` are emitted **unconditionally**, on every route. **This is why AC1 needs no backend change beyond the sort.**
8. `backend/tests/Feature/Database/RequestersTableSchemaTest.php` — the schema-assertion style Story 20's test 25 follows for index existence. Task 8's test 5 follows the same.
9. `frontend/src/lib/color.ts` — read it before task 4. If it already exposes a contrast or luminance helper, the badge uses it rather than adding a second one.

---

## Product rules (from story)

| Situation | Behaviour after Stories 19/20/25 | New behaviour |
|---|---|---|
| `?escalated=true` | Filters to `escalation_level > 0` | **Unchanged** |
| `?sort=escalated_at` | **`422`** — not in the whitelist | `200`, most recently escalated first on `desc` |
| Never-escalated tickets under that sort | — | **Last** on `desc`, first on `asc`. Measured; no `IS NULL` clause |
| `?sort=nonsense` | `422` | **Still `422`**, with `escalated_at` added to the message |
| A list row at level 0 | No escalation cell at all | **Empty cell**, no badge |
| A list row at level 1 | — | **Amber** badge reading **"Escalated"** |
| A list row at level 3 | — | **Red** badge reading **"Escalated ×3"** |
| The Escalated column header | Does not exist | Sortable, with `aria-sort`, like Priority and Age |
| Dashboard escalated card, **admin** | Links to `?escalated=true` | **Unchanged** |
| Dashboard escalated card, **agent** | Links to `?escalated=true` — **the whole queue, while the card counted only theirs** | `?escalated=true&assignee=me` |
| `/tickets/stats` payload | `escalated` is a scoped integer | **Unchanged** — no new field |

---

## Decision — the dashboard link must compose with `scope`, and that is a real defect

Story 25's `stat-escalated` card is **scoped**: for an agent the figure counts only tickets assigned to them (`25-…md:38`, and its plan explicitly says *"an agent's escalated card must be actionable by them"*). Its **label** already follows `scope` — "My escalated tickets" vs "Escalated tickets" (**402**). **Its link does not** (`escalatedTo` at **388** is a plain const, `{ escalated: 'true' }`).

So today an agent sees "My escalated tickets: 3", clicks, and lands on a list of **every escalated ticket in the queue** — a number that does not match the card they clicked. AC2 says the count *"links to the filtered list"*, singular: the list must be the one the count describes.

**Task 6 makes the link a computed that appends `assignee: 'me'` when `stats.data.scope === 'own'`.** It uses Story 20's URL vocabulary (`assignee`, sentinel `me`), which `fromQuery` already parses — **no new URL key.**

- **Keyed on `scope`, not on the role**, because Story 25's own rule is *"The API decides; the view reports."*
- **Story 25's test 20 asserts the escalated card's href is `?escalated=true`.** That assertion is now correct only for an admin. **Amend it, do not leave both** — task 6's tests 18 and 19 replace it.
- **The unassigned card is deliberately not touched.** Its figure is queue-wide for both roles (`25-…md:40`), so its link is already right.

## Decision — the badge carries the level in its **text**, not only its colour

AC4 says the badge *"visually distinguishes escalation level one from higher levels"*. It does that twice over:

- **Colour:** amber `#F59E0B` at level 1, red `#EF4444` above it — the same two hex values `PrioritySeeder` uses for Medium and Urgent, so the queue has one palette rather than two.
- **Text:** `Escalated` at level 1, `Escalated ×N` above it.

**Colour alone would fail the criterion for a colour-blind reader**, and amber-vs-red is one of the worst pairs for that. The text is what actually carries the distinction; the colour is reinforcement. **Do not "simplify" this to a colour swap**, and do not render `×1` — a bare "Escalated" is the level-1 signal and the `×` is what says "more than once".

## Decision — one shared `EscalationBadge`, used only by the list

The rule "level 1 is amber, above is red" lives in **one** component, `frontend/src/components/EscalationBadge.vue`, wrapping Story 19's `ColorBadge`.

**It is used only in the ticket list.** The ticket detail page already announces escalation through TM-41's `ticket-escalation` section, which states the level in prose along with the reason and who escalated. Adding a badge there would be a second signal for the same fact on a page that has room for the sentence. **TM-41's section is not touched by this story.**

## Decision — `escalated_at` gets its own index, its own migration, and its own sortable header

- **Its own index**, by the measurement in the prerequisites: filesort → backward index scan, the identical case Story 20 made for `updated_at`.
- **Its own migration file.** Story 20's migration adds two indexes; editing it would break anyone who has already run it, and TM-16's plan fixed the convention that separate concerns get separate files so each is a one-file diff.
- **Its own sortable header**, testid `sort-escalated_at`, with `aria-sort` — because this story adds an Escalated **column**, and Story 20's rule was that a sort gets a header when it has a column and lives in the select otherwise (`20-…md:505`, which keeps `updated_at` in the select precisely because there is no Updated column). **It goes in the select as well**, since Story 20's select lists every sort.

---

## Backend Tasks

### 1 — The sort key

**File: `backend/app/Http/Requests/Api/V1/IndexTicketRequest.php`** *(Story 20's task 2)*

Extend `SORTS` (**line 144**) with one entry:

```php
    public const SORTS = ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'priority' => 'level', 'escalated_at' => 'escalated_at'];
```

and the whitelist message (**187**):

```php
            'sort.in' => 'Tickets can only be sorted by created_at, updated_at, priority or escalated_at.',
```

- **`rules()` needs no change** — `Rule::in(array_keys(self::SORTS))` (**178**) picks the new key up automatically. That is the property Story 20 built the constant for.
- **`applySort()` needs no change either.** `escalated_at` is a real column on `tickets`, so it falls through Story 20's `priority` branch to `orderBy(self::SORTS[$sort], $direction)` (**264**). **Do not add a branch**, and do not add an `IS NULL` clause — the measurement shows MySQL already puts NULLs last on `DESC`.
- **`->orderByDesc('id')` stays the final tiebreak**, unchanged. It matters more here than anywhere: every never-escalated ticket ties on `NULL`.
- **Story 20's test 21 parameterises rejected sort values and asserts the message from `messages()`.** If it hard-codes the old sentence, update it; if it reads the constant, it passes untouched. **Check before assuming.**
- **Merge neighbour:** TM-25 (search) extends the same constant with a relevance sort (`20-…md:11`). Append, do not reorder.

### 2 — The index

**Create file: `backend/database/migrations/<timestamp>_add_escalated_at_index_to_tickets_table.php`**

Generate with `php artisan make:migration add_escalated_at_index_to_tickets_table` so the timestamp sorts after Story 20's.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Measured on 60 rows, a third escalated:
            //   without: type=ALL,   key=NULL,                        Using filesort
            //   with:    type=index, key=tickets_escalated_at_index,  Backward index scan
            // The same profile Story 20 measured for updated_at, and the same
            // fix. Separate from `escalation_level`'s index, which answers the
            // filter; this one answers the sort.
            $table->index('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['escalated_at']);
        });
    }
};
```

**Do not touch `create_tickets_table.php`**, and **do not add this to Story 20's migration** even if it has not run anywhere yet.

### 3 — Document the parameter

**File: `docs/api-contract.md`**

In the `GET /api/v1/tickets` parameter table Story 20 adds, change the `sort` row:

```markdown
| `sort` | enum | `created_at` (default), `updated_at`, `priority`, `escalated_at`. Anything else is `422`. |
```

and append after that table:

```markdown
`escalated_at` sorts by when a ticket was last escalated. It is nullable, and
MySQL orders NULL below any value, so `sort=escalated_at&direction=desc` puts the
most recently escalated first and never-escalated tickets last — **without**
filtering them out. Combine it with `escalated=true` to see only escalated
tickets. `tickets.escalated_at` is indexed for this sort.
```

---

## Frontend Tasks

### 4 — The badge component

**Create file: `frontend/src/components/EscalationBadge.vue`**

```ts
const props = defineProps<{ level: number }>()
// AC4. The level is carried by the TEXT as well as the colour: amber and red
// are one of the worst pairs for a colour-blind reader, so colour alone would
// not distinguish anything. Hex values are Medium's and Urgent's from
// PrioritySeeder, so the queue reads as one palette.
const colour = computed(() => (props.level > 1 ? '#EF4444' : '#F59E0B'))
const label = computed(() => (props.level > 1 ? `Escalated ×${props.level}` : 'Escalated'))
```

```html
<template>
  <ColorBadge v-if="level > 0" :name="label" :color="colour" data-testid="escalation-badge" :data-level="level" />
</template>
```

- **`v-if="level > 0"` inside the component**, so every call site is one unconditional tag and no caller can forget the guard.
- **`data-level`** is for the specs — asserting on a hex value in a test would break the first time the palette moves, while the level is the thing the criterion is about.
- **Never `×1`.** A bare "Escalated" *is* level one.
- **Check `frontend/src/lib/color.ts` first**: if it already has a contrast helper, use it for the text colour rather than adding a second one.

### 5 — The column, the header and the sort vocabulary

**File: `frontend/src/api/tickets.ts`** *(Story 20's task 5)*

Add `'escalated_at'` to the `TicketSort` union (**`20-…md:325`**).

**File: `frontend/src/lib/ticketQuery.ts`** *(Story 20's task 7)*

Add it to the `SORTS` array (**430**):

```ts
const SORTS: TicketSort[] = ['created_at', 'updated_at', 'priority', 'escalated_at']
```

`fromQuery` validates `sort` against that array and falls back to `created_at`, so **`?sort=escalated_at` starts round-tripping with no other change** — and `?sort=colour` still falls back. Story 20's `ticketQuery.spec.ts` round-trip test covers the new value once it is in the array; **add one case for it explicitly** (test 16).

**File: `frontend/src/views/TicketListView.vue`** *(Story 19's task 12, as extended by Story 20's tasks 8 and 10)*

- Add `'escalated_at'` to the `filter-sort` select's options (**`20-…md:457`**), labelled **"Recently escalated"**.
- Add one `<th>` and one `<td>` to the table, **between Priority and Age**:

| Element | `data-testid` | Rendering rule |
|---|---|---|
| Header button | `sort-escalated_at` | `@click="store.setSort('escalated_at')"`, `:aria-sort` `ascending` / `descending` / `none`, exactly as `sort-priority` does |
| Cell | `tickets-escalation` | `<EscalationBadge :level="ticket.escalation_level" />` — **always rendered**, the component decides |

- **`ticket.escalation_level` is already on `TicketListItem`** (`TicketResource.php:22`, `Omit<Ticket, 'description'>`). **No API change, no store change, no new request.**
- **`store.setSort` already toggles direction when the column is active** (Story 20's task 5). Picking it fresh uses the default `desc`, which is "most recently escalated first" — **AC3 needs no special-casing.**
- **The cell is always present** even at level 0, so the column does not jump between rows.

### 6 — The dashboard link

**File: `frontend/src/views/DashboardView.vue`** *(Story 25's task 8)*

Replace the `escalatedTo` const (**`25-…md:388`**) with a computed:

```ts
// The card's figure is scoped for an agent, so its link must be too, or a
// three-ticket card lands on a fifty-ticket list. Keyed on `scope`, not on the
// role -- Story 25's rule is that the API decides and the view reports.
// `assignee: 'me'` is Story 20's URL sentinel; fromQuery already parses it.
const escalatedTo = computed(() => ({
  name: 'tickets',
  query: {
    escalated: 'true',
    ...(stats.data?.scope === 'own' ? { assignee: 'me' } : {}),
  },
}))
```

- **`stats.data?.scope`, with the optional chain** — the card renders only inside the `dashboard` block, but the computed evaluates during setup and must not throw on a null `data`. A missing scope falls through to the admin form, which is the safe default: an over-broad list is a worse UI than a wrong one only if it claims to be scoped, and the label would say "Escalated tickets" in that state anyway.
- **`mineOpenTo` and `unassignedTo` are untouched.** `unassigned` is queue-wide for both roles by design (`25-…md:40`); adding `assignee: 'me'` there would break it.
- **Amend Story 25's `DashboardView.spec.ts` test 20**, which asserts the escalated href is `?escalated=true`. It is now the **admin** case; tests 18 and 19 below replace it.

---

## Edge Cases & Failure Modes

- **`?sort=escalated_at` on a queue with no escalated tickets** → `200`, every row has `escalated_at` null, order falls entirely to the `id` tiebreak. **Not an error and not empty** — the sort does not filter. Test 2.
- **Mixed null and non-null under `direction=desc`** → non-null first, newest first, nulls last. **Measured**, not assumed. Test 1 asserts the exact id order across the boundary.
- **`direction=asc`** → nulls **first**. Measured. Odd to look at but consistent, and it is what "oldest escalation first" has to mean when most rows have none. Test 3.
- **Paging a `escalated_at` sort** → stable, because `orderByDesc('id')` is still the final tiebreak and every never-escalated row ties on NULL. **Without that tiebreak this sort is the most undefined of the four** — Story 20's rule earns its keep here. Test 4.
- **`?sort=escalated_at` combined with `?escalated=true`** → the intended combination: only escalated tickets, most recent first, and the `escalation_level` index answers the filter while the new index answers the sort. Test 6.
- **A non-whitelisted sort** → still `422`, now with `escalated_at` in the message. **Story 20's test 21 must still pass**; if it hard-codes the old sentence, update the string, not the test's intent. Test 7.
- **The migration on a database where Story 20's has not run** → independent; the two touch different columns. **Half-applied state does not arise** — one index, one statement.
- **Re-running the migration** → `Duplicate key name 'tickets_escalated_at_index'`. Drop it and re-run: `ALTER TABLE tickets DROP INDEX tickets_escalated_at_index;`.
- **Rolling back** → the sort still works, as a filesort. **A performance regression, not a failure**, exactly as Story 20 records for its two indexes, so the migration can be rolled back independently of the code.
- **A row at level 0** → the cell renders, the badge does not. The column keeps its width and the table does not jitter. Test 12.
- **A row at level 1** → amber, text **"Escalated"**, `data-level="1"`. **No `×1`.** Test 13.
- **A row at level 2 or above** → red, text `Escalated ×N`. Test 14.
- **A level above 9** → renders `Escalated ×12` with no truncation. The column is text; nothing formats or clamps it. Test 15.
- **An agent clicking the escalated card** → `?escalated=true&assignee=me`, and the resulting `meta.total` equals the number the card showed. **Test 19 asserts the href; verification step 8 asserts the two numbers match in the running app**, which is the thing the user actually notices.
- **An admin clicking it** → `?escalated=true`, unchanged. Test 18.
- **A stats response with no `scope`** (an older cached payload, or a stubbed store in a spec) → the link falls through to the admin form. Documented in task 6 rather than guarded with a throw.
- **Query cost** → **unchanged at 8** with every filter and a sort applied. This story adds a sort key and an index; it adds no query, no eager load and no field. Test 5 re-asserts Story 20's number rather than trusting it.
- **The badge in a `tickets-row` that is also being sorted** → no interaction. The badge reads `escalation_level`; the sort reads `escalated_at`. **They can disagree**: a ticket escalated then de-escalated would have a level of 0 and a non-null `escalated_at`. **There is no de-escalation in the product** (TM-41's plan rules it out), so this is unreachable — recorded so nobody adds one without revisiting both.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketIndexSortTest.php` (Story 20's file; modified — or `TicketIndexTest` if Story 20 put its sort tests there)

Find where Story 20's sort tests live before creating a file; **do not start a third ticket-index test class.** Fixtures use Story 19's `TicketFactory` if it exists, otherwise `Model::create` and the seeded master data.

1. `test_sorts_by_most_recently_escalated_first` — **AC3.** Five tickets: three escalated at known, distinct times and two never escalated. `?sort=escalated_at&direction=desc` → the three in newest-first order, then the two. **Assert the full id sequence**, not just the first row.
2. `test_the_sort_does_not_filter` — a queue with **no** escalated tickets → `200` and `meta.total` equal to the unsorted total. The sort is not a filter.
3. `test_ascending_puts_never_escalated_first` — `direction=asc` → nulls first. Pins the measured NULL ordering so a later "fix" adding `IS NULL` fails.
4. `test_the_sort_pages_without_repeats` — `per_page=2` across a fixture with **four** never-escalated tickets tying on NULL; page through and assert every id is distinct. **Remove `orderByDesc('id')` and confirm this fails.**
5. `test_query_count_is_unchanged_with_the_new_sort` — all five of Story 20's filters plus `sort=escalated_at` on a non-empty result → **8** queries, the number Story 20 measured.
6. `test_escalated_filter_and_escalated_sort_compose` — `?escalated=1&sort=escalated_at&direction=desc` → only escalated rows, newest first.
7. `test_the_whitelist_message_names_the_new_sort` — `?sort=id` → `422` and the message contains `escalated_at`. **The seam test**: Story 20's rejection behaviour still holds with a fourth key in the constant.
8. `test_the_escalated_filter_still_works` — `?escalated=1` and `?escalated=0` behave exactly as Story 20 specifies. **Regression on the already-delivered half.**

### Backend — `backend/tests/Feature/Database/TicketIndexesTest.php` (Story 20's test 25; modified)

9. `test_escalated_at_is_indexed` — assert `tickets_escalated_at_index` exists via `Schema::getIndexes('tickets')`, beside Story 20's two assertions. Style from `RequestersTableSchemaTest`.

### Frontend — `frontend/src/components/EscalationBadge.spec.ts` (new)

10. Level `0` renders **nothing** — `wrapper.find('[data-testid="escalation-badge"]').exists()` is `false`.
11. Level `1` renders the badge with text exactly `Escalated` and `data-level="1"`. **Assert there is no `×`.**
12. Level `2` renders `Escalated ×2` with `data-level="2"`.
13. Level `1` and level `2` render **different** `background-color` styles. **AC4's colour half** — assert they differ rather than asserting the hex values, so a palette change does not break the test.
14. Level `12` renders `Escalated ×12` — no clamping.

### Frontend — `frontend/src/views/TicketListView.spec.ts` (Stories 19 and 20's file; modified)

15. A row with `escalation_level: 2` renders `tickets-escalation` containing the badge; a row at `0` renders the **cell** but no badge. **AC1's badge half.**
16. `sort-escalated_at` calls `store.setSort('escalated_at')` and sets `aria-sort`, exactly as Story 20's `sort-priority` test does.
17. Mounting at `/tickets?sort=escalated_at` hydrates the store with that sort — **the round-trip through `fromQuery` with the new key.** Add one matching case to `ticketQuery.spec.ts`: `?sort=escalated_at` survives, `?sort=colour` still falls back to `created_at`.

### Frontend — `frontend/src/views/DashboardView.spec.ts` (Story 25's file; modified)

18. With `scope: 'all'`, `stat-escalated`'s resolved href is `?escalated=true` with **no** `assignee`. **This replaces Story 25's test 20.**
19. With `scope: 'own'`, the href is `?escalated=true&assignee=me`. **AC2.**
20. The `unassigned` card's href is **identical in both scopes** — the regression that stops someone "fixing" it the same way.
21. The escalated card's **label** still follows `scope` ("My escalated tickets" / "Escalated tickets"). Story 25's assertion, re-run here because task 6 edits the lines beside it.

---

## Verification Steps

1. **Gate:** from `backend/`, `php artisan test --filter='TicketIndexTest|TicketStatsTest'` → green. From `frontend/`, `npx vitest run src/lib/ticketQuery.spec.ts src/views/TicketListView.spec.ts src/views/DashboardView.spec.ts` → green. **If any file is missing, its story has not landed and this one cannot start.**
2. **Migrate:** from `backend/`, `php artisan migrate`, then `php artisan db:table tickets` → confirm **`tickets_escalated_at_index`** alongside Story 20's `tickets_updated_at_index` and `tickets_escalation_level_index`.
3. **Prove the index earns its place.** With a few dozen tickets seeded, run:
   ```sql
   EXPLAIN SELECT * FROM tickets WHERE deleted_at IS NULL ORDER BY escalated_at DESC, id DESC LIMIT 15;
   ```
   Expect `type=index`, `key=tickets_escalated_at_index`, `Extra=Using where; Backward index scan`. Then `ALTER TABLE tickets DROP INDEX tickets_escalated_at_index;`, re-run, and confirm `type=ALL … Using filesort`. Restore with `php artisan migrate:refresh --step=1` or re-add by hand.
4. **Backend formats and tests:** `./vendor/bin/pint --test`; `composer test`. Expect **+9 tests** and **no new failures**.
5. **Prove the tiebreak earns its place:** remove `->orderByDesc('id')` from `index()`, re-run `--filter=test_the_sort_pages_without_repeats`, confirm it fails, restore.
6. **Prove the NULL ordering is not accidental:** add `->whereNotNull('escalated_at')` to the sort path, re-run `--filter=test_the_sort_does_not_filter`, confirm it fails, restore. **This is the guard against someone "tidying" the sort into a filter.**
7. **Backend by hand.** `php artisan serve`, with a queue containing escalated and non-escalated tickets:
   - `…/tickets?sort=escalated_at&direction=desc` → escalated first, newest first, never-escalated last.
   - `…/tickets?sort=escalated_at&direction=asc` → never-escalated first.
   - `…/tickets?escalated=1&sort=escalated_at&direction=desc&per_page=5` → only escalated, and `links.next` carries all four parameters.
   - `…/tickets?sort=escalation_level` → `422`, message naming `escalated_at` among the four.
8. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+12 tests**. Then `npx prettier --check src/components/EscalationBadge.vue src/lib/ticketQuery.ts src/api/tickets.ts src/views/TicketListView.vue src/views/DashboardView.vue src/components/EscalationBadge.spec.ts`.
9. **Frontend by hand:** `npm run dev`. **Escalate a few tickets first** — TM-41's endpoint, or `UPDATE tickets SET escalation_level = 2, escalated_at = NOW() WHERE id = …` if TM-41 has not landed.
   - `/tickets` → escalated rows carry a badge; level-1 rows read **"Escalated"** in amber, level-2+ read **"Escalated ×2"** in red; level-0 rows show an empty cell and the column does not jitter.
   - Click the **Escalated** header → the order flips and `aria-sort` follows; the URL gains `?sort=escalated_at`. Reload the page → **the sort survives**.
   - Pick **"Recently escalated"** from `filter-sort` → the same result.
   - Set `filter-escalated` to **Escalated** and sort by recency → only escalated tickets, newest first.
   - **Click Clear all** → the sort resets to `created_at` and the escalated filter clears, per Story 20's AC5.
   - **As an admin**, open the dashboard, note the Escalated card's number, click it → the list's "Showing … of **N**" equals the card's number.
   - **As an agent**, do the same → the URL carries **`assignee=me`** and the two numbers **still match**. *(This is the defect task 6 fixes; without it the second number is larger.)*
   - The **Unassigned** card behaves identically for both roles.
10. **Regression:** confirm `git status` shows **no change** to `TicketController::escalate()`, `TicketPolicy.php`, `EscalateTicketRequest.php`, `TicketDetailView.vue`'s escalation section, `create_tickets_table.php` or Story 20's migration. Then run one escalation through the UI and confirm the badge appears on the list without a manual refresh of anything but the list itself.

---

## Done Criteria

- [ ] `GET /api/v1/tickets?sort=escalated_at` returns `200`; `direction=desc` puts the most recently escalated first and never-escalated tickets **last**, with **no** `IS NULL` clause and **no** filtering.
- [ ] `direction=asc` puts never-escalated first — asserted, so the measured NULL ordering is pinned rather than assumed.
- [ ] `id` descending is still the final tiebreak, proven by a paging test that fails when it is removed.
- [ ] `tickets.escalated_at` is indexed **in a new migration** that leaves `create_tickets_table` and Story 20's migration untouched; the `EXPLAIN` is `Backward index scan`, verified by dropping the index and watching it become `Using filesort`.
- [ ] `sort=escalated_at` is added to `IndexTicketRequest::SORTS` **by appending**, `rules()` and `applySort()` are unchanged, and every non-whitelisted sort is still `422` with the new key in the message.
- [ ] The endpoint still issues **8 queries** with all five filters and the new sort applied.
- [ ] Every list row renders an escalation cell; the badge appears only above level 0, reads **"Escalated"** in amber at level 1 and **"Escalated ×N"** in red above it, and **never renders `×1`**.
- [ ] The level is carried by the **text as well as the colour**, and the level-1 and level-2 badges are asserted to differ in both.
- [ ] The badge rule lives in **one** component; the ticket **detail** page is untouched.
- [ ] The Escalated column header sorts with `aria-sort`, `escalated_at` is in the `filter-sort` select as **"Recently escalated"**, and `?sort=escalated_at` round-trips through `ticketQuery.ts` while `?sort=colour` still falls back.
- [ ] The dashboard's escalated card links to `?escalated=true` for an admin and **`?escalated=true&assignee=me`** for an agent, keyed on `scope` and not on the role — and the list's total **matches the card's number for both**.
- [ ] Story 25's `DashboardView.spec.ts` escalated-href assertion is **amended**, not duplicated; the unassigned card's link is identical in both scopes and is asserted to be.
- [ ] **The escalated filter, the stats `escalated` figure and the card itself were not rebuilt** — Stories 20 and 25 own them, four regression tests prove they still work, and the PR description says so.
- [ ] `docs/api-contract.md` documents `escalated_at` in the sort enum, the NULL ordering, and that the sort does not filter.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+9 backend and +12 frontend tests**; no new failures; no new endpoint, no stats field, no dependency.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 37 (TM-43, flag stale tickets on a schedule).**
