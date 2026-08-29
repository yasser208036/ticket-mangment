# Story 21 — Search tickets (Story: TM-25)

## Prerequisites

- **Story 19 (TM-23) and Story 20 (TM-24) — both PLANNED, NEITHER IMPLEMENTED. This story is hard-blocked on both, in that order.** Verified on disk while planning: `TicketController` has **no `index()`**, `backend/app/Http/Requests/Api/V1/IndexTicketRequest.php` **does not exist**, and neither does `frontend/src/lib/ticketQuery.ts`, `frontend/src/components/TicketFilterBar.vue`, `frontend/src/views/TicketListView.vue` or `frontend/src/lib/relativeTime.ts`. **Every file this story edits is created by Story 19 or Story 20.** Read [`19-story-paginated-ticket-list-TM-23.md`](19-story-paginated-ticket-list-TM-23.md) and [`20-story-filter-and-sort-the-ticket-queue-TM-24.md`](20-story-filter-and-sort-the-ticket-queue-TM-24.md) before this plan. **Gate: do not start until `php artisan test --filter='TicketIndexTest|TicketFilterTest|TicketSortTest'` passes.**
- **Story 17 (TM-21) completed — implemented.** The FULLTEXT index exists: `$table->fullText(['subject', 'description'])` at `backend/database/migrations/2026_08_26_084625_create_tickets_table.php:39`, named **`tickets_subject_description_fulltext`** (confirmed in `EXPLAIN` output during planning). **This story adds no migration** — the index it needs already shipped.
- **Story 17's planned FULLTEXT test was never written.** `grep -rl "MATCH\|fullText\|DatabaseTruncation" backend/tests/` returns **nothing**. This story is the first to test the index at all, so it also carries the burden of proving the index works — task 8.
- **No new composer or npm dependency.** No Scout, no Meilisearch, no Elasticsearch.
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

**One coordination point.** Story 20 explicitly hands this story two things: **add `q` to `IndexTicketRequest` rather than create a second form request**, and **extend its `SORTS` constant** for relevance ordering. Task 2 and task 3 do exactly that.

---

## Story Goal

An agent on the phone with a requester types anything they know — a reference, a word from the subject, the caller's name or email — and lands on the right ticket.

1. A single `q` parameter searches **`reference`, `subject`, `description`, and the requester's `name` and `email`**.
2. `subject` and `description` are matched through the **FULLTEXT index**, not a leading-wildcard `LIKE`.
3. Searching an **exact reference returns that ticket first**.
4. `q` **composes** with every Story 20 filter and with `sort`, rather than replacing them.
5. The SPA input is **debounced at 300 ms**, so typing does not fire a request per keystroke.

**Not in scope.** No search across ticket comments or activity history (`ticket_activities` is TM-45's). No fuzzy matching, synonyms, or "did you mean". No search highlighting in the results table. No saved searches. **No new index and no new endpoint.**

---

## Context — Read These Files First

1. [`20-story-filter-and-sort-the-ticket-queue-TM-24.md`](20-story-filter-and-sort-the-ticket-queue-TM-24.md) — **read tasks 2, 3, 5, 6, 7 and 8 in full.** Task 2 defines the `IndexTicketRequest` you extend, including its `SORTS` constant and `prepareForValidation()`. Task 3 defines the `index()` builder chain and the `applySort()` helper you hook into. Task 7 defines `lib/ticketQuery.ts` (`toQuery` / `fromQuery`) that must learn about `q`. Task 8 defines `TicketFilterBar.vue`, which gains the search input.
2. [`19-story-paginated-ticket-list-TM-23.md`](19-story-paginated-ticket-list-TM-23.md) — task 2's six eager loads and the constant-query-count claim; task 9's `stores/tickets.ts` shape and its `latestRequest` race guard, which matters more here than anywhere else because search fires on a debounce.
3. `backend/database/migrations/2026_08_26_084625_create_tickets_table.php` — **line 39** is the FULLTEXT index. Note also `subject` is `string` (**line 17**) and `description` is `text` (**line 18**); both are `utf8mb4`, which is why Arabic works with no parser option.
4. `backend/app/Services/ActivityRecorder.php` and `backend/app/Services/TicketReferenceGenerator.php` — the service idiom this story follows for `TicketSearch`: a small final-ish class, constructor-injected into the controller action, no facade calls in the hot path.
5. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — **line 29** is the project's existing `LIKE` escaping: `'%'.addcslashes($filters['search'], '%_\\').'%'`. **Reuse this exactly** for the reference and requester branches; do not invent a second escaping helper.
6. `backend/database/seeders/StatusSeeder.php` / `PrioritySeeder.php` — fixtures for the composition tests.
7. `frontend/src/views/AdminUsersView.vue` — **the debounce precedent, lines 9–17.** `let timer: ReturnType<typeof setTimeout> | undefined`, then `clearTimeout(timer)` and `setTimeout(…, 300)` inside a `watch`. AC5 is satisfied by copying this shape; **do not add a debounce library.**
8. `frontend/src/api/errors.ts` — `errorMessage()` (**11–21**). A `422` from an over-long `q` surfaces through the store's existing error state; **write no new strings.**
9. Grep for `whereFullText` in `vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/MySqlGrammar.php` — the method at **line 119** compiles `match (cols) against (? in boolean mode)` when passed `['mode' => 'boolean']`, and `in natural language mode` otherwise. Task 3 depends on the boolean form.

---

## Measured facts that decide these tasks

All measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** — server variables read directly, and the full query shape exercised through Eloquent on a 25-ticket fixture. Do not re-derive them.

- **`MATCH` returns zero rows inside the transaction `RefreshDatabase` holds open. This is the single most expensive trap in this story.** Reproduced exactly: with `DB::transactionLevel() === 1`, a ticket whose subject is "Kerberos login failure" is found by `LIKE '%Kerberos%'` (**1 row**) and by **neither** `MATCH … AGAINST('kerberos')` in natural language mode (**0 rows**) nor in boolean mode (**0 rows**). InnoDB updates a FULLTEXT index at **commit** time. There is no error and no warning — the assertion just fails on an empty result. **Every test in this story that exercises `MATCH` must use `Illuminate\Foundation\Testing\DatabaseTruncation`, not `RefreshDatabase`.** With `DatabaseTruncation` (`transactionLevel` measured at **0**) every search below behaves correctly.

- **`MATCH … OR <anything>` silently abandons the FULLTEXT index, which would break AC2.** Measured `EXPLAIN`:
  | Query shape | type | key | rows |
  |---|---|---|---|
  | `WHERE MATCH(subject,description) AGAINST(…)` | **`fulltext`** | `tickets_subject_description_fulltext` | 1 |
  | `WHERE MATCH(…) OR reference = ?` | **`ALL`** | **NULL** | 30 |
  | `WHERE id IN (SELECT id … WHERE MATCH(…)) OR reference = ?` | `ALL` (outer) / **`fulltext`** (inner `t2`) | inner: the FULLTEXT index | 1 (inner) |
  | `WHERE subject LIKE '%printer%'` | `ALL` | NULL | 30 |

  So the obvious `->where(fn($q) => $q->whereFullText(...)->orWhere('reference', ...))` is **exactly wrong**: it produces the same full scan as the `LIKE` that AC2 forbids. **The fulltext branch must be a `whereIn('id', <subquery>)`** so the inner query keeps the index. The outer access path is still a scan over the candidate set — that is inherent to OR-ing four different access paths, and it is bounded by whatever Story 20 filters are also applied. Task 3 encodes this; task 9's verification step proves it.

- **Natural-language mode cannot match a partial word, so boolean mode with a trailing `*` is mandatory.** Measured on a fixture containing "Printer" and "Printing": `'printer'` → 1 row in both modes; **`'print'` → 0 rows in both modes**; `'print*'` (boolean) → **2 rows**; `'printer*'` → 1 row. An agent typing "print" while a requester is on the phone must not get nothing, so task 3 builds `+token*` per token in boolean mode.

- **Raw user input in boolean mode is a 500, not a 422 — six ways.** Measured, each raising `SQLSTATE[42000] … 1064 syntax error`: `'foo +'`, `'+-'`, `'***'`, `'a)b('`, `'@distance'`, `'<>'`. A user typing **`C++`**, **`Q&A (urgent)`** or an email fragment starting **`@`** would crash the endpoint. (`'"unclosed'`, `'foo -bar'` and `'~tilde'` happened to survive — do **not** infer from that that only some operators need stripping.) **`q` must never reach `AGAINST` unsanitised.** Task 2's `TicketSearch::booleanQuery()` strips `+ - > < ( ) ~ * " @`, splits on whitespace, drops tokens shorter than 3 characters, and rebuilds `+token*`. Re-measured through that sanitiser: `'C++'`, `'Q&A (urgent)'` and `'on'` all return **`200` with zero rows and no error**.

- **`innodb_ft_min_token_size = 3` and stopwords are ON with 36 default words** — read straight off the server. Measured consequences: `'vpn'` (3 chars) → 1 row; **`'on'` (2 chars) → 0 rows**; **`'the'` (stopword) → 0 rows from `MATCH`**; the Arabic `'لا'` (2 chars) → 0 rows. This is why the sanitiser drops sub-3-character tokens rather than sending them: a 2-character token contributes nothing and, combined with `+`, would make the whole boolean query match nothing.

- **A stopword query can still return rows through the other branches, and that is fine.** Measured `q='the'` → **1 row**, because the requester email `bob@other.test` contains "the" and the requester branch is a `LIKE`. Do not "fix" this; it is AC1 working. It does mean a test asserting "stopwords find nothing" must pick a term absent from every reference, name and email.

- **Arabic needs no parser option.** Measured with the default parser on utf8mb4: `'الطابعة'` → 1 row, `'المحاسبة'` → 1 row against an Arabic subject and description. **No `WITH PARSER ngram`.**

- **Relevance scores are real numbers and the ordering composes.** Measured `MATCH(subject,description) AGAINST('printer' IN BOOLEAN MODE)` as a select expression → **4.3637742996216** for the matching row. And `ORDER BY (reference = ?) DESC, score DESC, id DESC` measured correct end to end: `q='TKT-2026-000002'` returns that ticket **first**; `q='kerberos'` returns the subject match first, ahead of two tickets that matched only because the requester is named "Alice Kerberos".

- **Search adds zero queries.** Both subqueries are inline, so the endpoint stays at Story 19's count: measured **7** queries on a page where no ticket is assigned, **8** where at least one is — the same assignee-batch-skip Story 19 documented. A zero-result search issues **1** query, because `paginate()` short-circuits on `count(*) = 0` (Story 20's finding, re-confirmed here). **Any query-count test must use a search that matches rows.**

---

## Baselines — read this before you start

**These are Story 20's projected end state, not measured reality.** Neither Story 19 nor Story 20 is implemented. Re-measure:

```bash
cd backend  && php artisan test 2>&1 | tail -5
cd frontend && npm test 2>&1 | tail -5
```

- Story 20 is expected to leave the backend at **141 tests / 138 passing** and the frontend at **62 tests across 12 files**.
- **Two failures are expected to still be red and are out of scope**: `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (TM-14) and `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` (TM-21).
- **`DatabaseTruncation` is slower than `RefreshDatabase`** — it truncates every table instead of rolling back a transaction. Expect this story's two search suites to add a few seconds to `composer test`. That is the price of testing a FULLTEXT index at all; do not switch them back.
- **Expected end state: 141 → 165 backend tests (162 passing) and 62 → 78 frontend tests across 13 files.**

---

## Backend Tasks

### 1 — The search service

**Create file:** `backend/app/Services/TicketSearch.php`

Two pure static methods (unit-testable with no database) plus one builder method.

```php
<?php

namespace App\Services;

use App\Models\Requester;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

class TicketSearch
{
    /**
     * InnoDB ignores tokens shorter than `innodb_ft_min_token_size`, measured
     * at 3 on this server. A shorter token combined with `+` would make the
     * whole boolean query match nothing, so drop them instead of sending them.
     */
    public const MIN_TOKEN_LENGTH = 3;

    /**
     * Boolean-mode operators. Passing raw user input to AGAINST() raises
     * MySQL error 1064 — measured for `foo +`, `+-`, `***`, `a)b(`,
     * `@distance` and `<>`. `C++` and `Q&A (urgent)` are realistic inputs
     * that would 500 the endpoint. Strip, never escape: there is no escape
     * syntax for these inside AGAINST().
     */
    private const OPERATORS = '/[+\-><()~*"@]+/';

    /** @return list<string> */
    public static function tokens(string $raw): array
    {
        $cleaned = preg_replace(self::OPERATORS, ' ', $raw) ?? '';

        return array_values(array_filter(
            preg_split('/\s+/', trim($cleaned)) ?: [],
            fn (string $token): bool => mb_strlen($token) >= self::MIN_TOKEN_LENGTH,
        ));
    }

    /** `printer tray` becomes `+printer* +tray*`; returns '' when nothing survives. */
    public static function booleanQuery(string $raw): string
    {
        return implode(' ', array_map(fn (string $t): string => '+'.$t.'*', self::tokens($raw)));
    }

    /**
     * The fulltext branch MUST be a subquery. Measured: putting MATCH directly
     * in an OR makes MySQL abandon the FULLTEXT index entirely (type=ALL),
     * which is the leading-wildcard LIKE scan that AC2 forbids. As a subquery
     * the inner plan stays type=fulltext.
     *
     * @param  Builder<Ticket>  $query
     */
    public function apply(Builder $query, string $raw): void
    {
        $boolean = self::booleanQuery($raw);
        $like = '%'.addcslashes($raw, '%_\\').'%';

        $query->where(function (Builder $inner) use ($boolean, $like): void {
            if ($boolean !== '') {
                $inner->whereIn('id', Ticket::query()->select('id')
                    ->whereFullText(['subject', 'description'], $boolean, ['mode' => 'boolean']));
            }
            $inner->orWhere('reference', 'like', $like);
            $inner->orWhereIn('requester_id', Requester::query()->select('id')
                ->where(fn (Builder $r) => $r->whereLike('name', $like)->orWhereLike('email', $like)));
        });
    }

    /**
     * Relevance ordering. Exact reference first (AC3), then FULLTEXT score.
     * The score expression is repeated here because it is not available from
     * the WHERE clause, which uses the subquery form.
     *
     * @param  Builder<Ticket>  $query
     */
    public function applyRelevanceOrder(Builder $query, string $raw): void
    {
        $query->orderByRaw('(reference = ?) desc', [$raw]);
        if (($boolean = self::booleanQuery($raw)) !== '') {
            $query->orderByRaw('match (subject, description) against (? in boolean mode) desc', [$boolean]);
        }
    }
}
```

- **`addcslashes($raw, '%_\\')` matches `UserController.php:29` exactly.** The `LIKE` branches take the **raw** `q`, not the sanitised tokens — someone searching `C++` should still find a reference or requester containing `C++`.
- **`whereLike` (not `where(…, 'like', …)`) on the requester branch** matches the project's existing usage at `UserController.php:30`.
- **`orderByRaw` with bindings, never interpolation.** `(reference = ?)` and the `AGAINST(?)` both bind. Nothing from `q` is concatenated into SQL anywhere in this file.
- **Do not add a `->orderByDesc('id')` here** — `index()` already appends it as the final tiebreak on every path.

### 2 — Extend `IndexTicketRequest`

**File: `backend/app/Http/Requests/Api/V1/IndexTicketRequest.php`** — the class Story 20 creates. **Do not create a second form request.**

Add `q` to `rules()`:

```php
'q' => ['sometimes', 'string', 'max:255'],
```

Extend the `SORTS` constant with relevance. **Append; do not reorder** — Story 20's tests assert its keys:

```php
public const SORTS = ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'priority' => 'level', 'relevance' => 'relevance'];
```

Add a rule that makes `sort=relevance` meaningless without a search, and trim `q` in `prepareForValidation()`:

```php
protected function prepareForValidation(): void
{
    // ... Story 20's scalar-to-array normalisation stays exactly as it is ...
    if (is_string($q = $this->input('q'))) {
        $this->merge(['q' => trim($q)]);
    }
}
```

```php
public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $validator): void {
        if ($this->input('sort') === 'relevance' && ! filled($this->input('q'))) {
            $validator->errors()->add('sort', 'Sorting by relevance requires a search term.');
        }
    });
}
```

Add to `messages()`: `'q.max' => 'A search term cannot exceed 255 characters.'`.

- **`trim` then `filled`**: `?q=%20%20` becomes `''`, which is treated as no search at all rather than a search for nothing. Task 3 keys off `filled()`.
- **`q` is not validated against the token rules.** A `q` of `on` (too short) or `+++` (all operators) is a perfectly valid **`200` with zero fulltext hits** — the reference and requester branches still run. Rejecting it with a `422` would be wrong; measured, it returns cleanly.

### 3 — Wire search into `index()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`** — the `index()` Story 20 leaves behind.

Inject the service and add one `when()` to the chain, immediately **before** the sort:

```php
public function index(IndexTicketRequest $request, TicketSearch $search): AnonymousResourceCollection
{
    $q = $request->string('q')->value();
    $searching = filled($q);
    // With a search and no explicit sort, relevance is the only sensible
    // default; an explicit sort always wins, because AC4 requires search to
    // compose with sorting rather than override it.
    $sort = $request->string('sort', $searching ? 'relevance' : 'created_at')->value();

    $tickets = Ticket::query()
        ->with([...])                       // Story 19, unchanged
        ->when(...)                          // Story 20's five filters, unchanged
        ->when($searching, fn ($query) => $search->apply($query, $q))
        ->tap(fn ($query) => $this->applySort($query, $sort, $direction, $q, $search))
        ->orderByDesc('id')
        ->paginate($request->integer('per_page', 15))->withQueryString();

    return TicketResource::collection($tickets);
}
```

Extend `applySort()` with the relevance branch:

```php
private function applySort(Builder $query, string $sort, string $direction, string $q, TicketSearch $search): void
{
    if ($sort === 'relevance') {
        $search->applyRelevanceOrder($query, $q);

        return;
    }
    // ... Story 20's `priority` subquery branch and default branch, unchanged ...
}
```

- **`direction` is ignored for `relevance`.** Ascending relevance means "worst match first", which no user wants; the request still accepts `direction` because the parameter is global, and relevance simply does not consult it. Document this in task 4.
- **The search `when()` goes before the sort and after the filters** so the `where` group nests inside whatever the filters produced. Order matters for the generated parentheses: the search's `orWhere` branches must not escape their group and OR away a status filter. The closure form in `TicketSearch::apply()` is what guarantees that; **never inline the `orWhere` calls into `index()`.**
- **`->orderByDesc('id')` stays last on every path**, relevance included — measured relevance ties are common (every non-matching row scores 0).

### 4 — Document `q`

**File: `docs/api-contract.md`**

Add to the `GET /api/v1/tickets` parameter table Story 20 creates:

```markdown
| `q` | string | Max 255. Searches `reference`, `subject`, `description` and the requester's `name` and `email`. |
```

And a paragraph after it:

```markdown
`q` composes with every filter. `subject` and `description` are matched through
the `tickets_subject_description_fulltext` index in **boolean mode** with a
trailing wildcard per token, so `print` matches "Printer" and "Printing".
Tokens shorter than 3 characters and MySQL stopwords contribute nothing
(`innodb_ft_min_token_size = 3`); `reference` and the requester fields are
matched with `LIKE` and have no such limit. Boolean-mode operators in `q` are
stripped, so `C++` is a valid search that simply has no fulltext tokens.

When `q` is present and `sort` is omitted, results are ordered by relevance:
exact `reference` match first, then FULLTEXT score, then newest. `sort=relevance`
without `q` is a `422`. `direction` does not apply to `relevance`.
```

---

## Frontend Tasks

### 5 — Query type

**File: `frontend/src/api/tickets.ts`**

Add `q?: string` to `TicketListQuery`, and `'relevance'` to `TicketSort`:

```ts
export type TicketSort = 'created_at' | 'updated_at' | 'priority' | 'relevance'
```

### 6 — Store state

**File: `frontend/src/stores/tickets.ts`** — Story 20's store.

Add `const q = ref('')`, include it in `load()`'s query **only when non-empty** (`...(q.value ? { q: q.value } : {})`), count it in `activeFilterCount`, and reset it in `clearAll()`.

Add one action:

```ts
/** Debounced by the view, not here — the store stays synchronous to test. */
async function applySearch(term: string): Promise<void> {
  q.value = term
  // A new search invalidates the page, and relevance replaces the default sort.
  page.value = 1
  if (term === '' && sort.value === 'relevance') {
    sort.value = 'created_at'
    direction.value = 'desc'
  }
  await load()
}
```

- **Clearing the search must move off `relevance`**, or the next request is a `422` (`sort=relevance` without `q`). This is the single most likely runtime bug in this story.
- **The debounce lives in the view, not the store.** A timer inside the store makes every store test await real time; `AdminUsersView.vue:9–17` already puts it in the view.
- **`q` counts toward `activeFilterCount`**, so the Clear-all button enables on a search alone.

### 7 — URL round-trip

**File: `frontend/src/lib/ticketQuery.ts`** — Story 20's helper.

Add `q: string` to `TicketQueryState`; `toQuery` emits `q` when non-empty; `fromQuery` reads it with `String(...)` and defaults to `''`. Extend the `SORTS` guard array with `'relevance'`.

**`fromQuery` must drop `sort=relevance` when `q` is empty** — otherwise a hand-edited or stale bookmarked URL sends a request the API rejects with a `422`:

```ts
const sort = SORTS.includes(raw) ? raw : 'created_at'
return { ...rest, q, sort: sort === 'relevance' && q === '' ? 'created_at' : sort }
```

### 8 — Search input

**File: `frontend/src/components/TicketFilterBar.vue`** — Story 20's component.

Add the input as the **first** control, with the `AdminUsersView.vue:9–17` debounce:

```ts
const search = ref(store.q)
let timer: ReturnType<typeof setTimeout> | undefined
watch(search, (term) => {
  clearTimeout(timer)
  timer = setTimeout(() => void store.applySearch(term.trim()), 300)
})
onBeforeUnmount(() => clearTimeout(timer))
```

```html
<input
  v-model="search"
  data-testid="filter-search"
  type="search"
  placeholder="Reference, subject, or requester"
  aria-label="Search tickets"
/>
```

- **`onBeforeUnmount(() => clearTimeout(timer))`** — without it, navigating away mid-debounce fires `load()` against a torn-down store. `AdminUsersView.vue` omits this and has the latent bug; **do not copy that part.**
- **A local `ref` mirrors `store.q` rather than `v-model`-ing the store directly**, so keystrokes do not mutate shared state before the debounce settles. Re-sync it when `clearAll()` runs: `watch(() => store.q, (value) => { if (value !== search.value.trim()) search.value = value })`.
- **`type="search"`** gives the native clear affordance; the Clear-all button remains the AC5 control.

### 9 — Nothing to do

**`frontend/src/views/TicketListView.vue`** — **no change.** Story 20's URL-sync watcher already serialises whatever `toQuery` returns, and `q` is now part of that. Confirm no change is needed rather than adding one.

### 10 — Formatting

```bash
cd frontend && npx prettier --write src/api/tickets.ts src/stores/tickets.ts src/lib/ticketQuery.ts src/components/TicketFilterBar.vue src/lib/ticketQuery.spec.ts src/stores/tickets.spec.ts src/components/TicketFilterBar.spec.ts
```

Same policy as Stories 19 and 20: **format only what you touch.**

---

## Edge Cases & Failure Modes

- **`q` containing boolean operators (`C++`, `Q&A (urgent)`, `+`, `***`, `<>`, `@corp`)** — sanitised to nothing by `TicketSearch::tokens()`, so only the reference and requester `LIKE` branches run. Measured: `200`, zero rows, **no 1064**. This is the failure mode that would otherwise 500 the endpoint.
- **`q` shorter than 3 characters (`on`, `VP`)** — no fulltext tokens survive; reference and requester still match. `200`, not `422`.
- **`q` that is a MySQL stopword (`the`, `and`)** — contributes nothing to `MATCH`, but may still return rows via the requester branch (measured: `bob@other.test` matches `the`). Not a bug.
- **`q` of only whitespace** — `prepareForValidation()` trims to `''`, `filled()` is false, no search is applied at all. The response is the unfiltered queue, not an empty list.
- **`q` over 255 characters** → `422` naming `q`, surfaced by the store's existing error state.
- **`sort=relevance` with no `q`** → `422` from `withValidator()`. The frontend prevents it two ways: `applySearch('')` moves the sort back to `created_at`, and `fromQuery` strips it from a stale URL. **Both are needed** — one covers the user, the other covers a bookmark.
- **Clearing the search box while relevance-sorted** is the most likely runtime bug: without the reset in task 6, the next request is a `422`. Tested in frontend test 27.
- **A search matching nothing** issues **1 query** (measured — `paginate()` short-circuits on a zero count) and renders `tickets-empty`, not an error. A query-count assertion here would prove nothing.
- **A search matching rows** stays at Story 19's **7 or 8** queries depending on whether the page holds an assigned ticket. Search itself adds **zero** — both subqueries are inline.
- **An exact reference in different case** (`tkt-2026-000002`) still sorts first: the column collation is `utf8mb4_unicode_ci`, so `(reference = ?)` is case-insensitive.
- **A reference fragment (`000002`)** matches through the `LIKE '%000002%'` branch but scores no relevance boost, so it is ordered by `id` among its peers. Only a **full** exact reference gets the AC3 first-place guarantee.
- **`%` or `_` typed into the search box** — escaped by `addcslashes($raw, '%_\\')`, so they are literal characters, not wildcards. A user searching `100%` does not match everything.
- **Arabic and emoji in `q`** — tokens of 3+ Arabic characters match through the default parser (measured); 2-character Arabic words do not, exactly like English. `mb_strlen` counts characters, so the length rule is not byte-based.
- **Search + filters that exclude the search hits** returns an empty page, not the search hits. The `where` group nesting in `TicketSearch::apply()` is what guarantees the `orWhere` branches cannot escape and OR away a status filter — the single most important structural detail in task 3.
- **Rapid typing** is covered twice: the 300 ms debounce collapses keystrokes, and Story 19's `latestRequest` counter discards an out-of-order response if two requests do overlap. Search is the feature that makes that guard earn its place.

---

## Test Plan

### Backend — `backend/tests/Unit/Services/TicketSearchTest.php` (new, **no database trait**)

Pure functions, so these are fast and need no MySQL. This directory does not exist yet; `tests/Unit/Enums/UserRoleTest.php` is the sibling to model.

1. `test_tokens_drops_tokens_shorter_than_three_characters` — `'on the vpn'` → `['the', 'vpn']`.
2. `test_tokens_strips_boolean_operators` — `'C++'` → `[]`; `'Q&A (urgent)'` → `['Q&A', 'urgent']` (the `&` is not an operator); `'+-'` → `[]`; `'a)b('` → `[]`.
3. `test_boolean_query_builds_prefixed_required_tokens` — `'printer tray'` → `'+printer* +tray*'`.
4. `test_boolean_query_is_empty_when_nothing_survives` — `'C++'`, `'on'`, `'   '`, `'***'` → `''`.
5. `test_tokens_counts_characters_not_bytes` — a 3-character Arabic word survives; a 2-character one does not.

### Backend — `backend/tests/Feature/Tickets/TicketSearchTest.php` (new, **`DatabaseTruncation`**)

> **Use `Illuminate\Foundation\Testing\DatabaseTruncation`, NOT `RefreshDatabase`.** Measured: `MATCH` returns **0 rows** inside the open transaction `RefreshDatabase` holds, with no error. Put that reason in a class docblock so nobody "tidies" it back.

6. `test_fulltext_index_is_actually_used` — the test Story 17 planned and never wrote. Run `EXPLAIN` on a bare `MATCH` query and assert `type` is `fulltext` and `key` is `tickets_subject_description_fulltext`. **This is the AC2 guard**; without it nothing detects a silent fall back to a scan.
7. `test_searches_subject` and `test_searches_description` — separate assertions per column.
8. `test_partial_word_matches_via_prefix_wildcard` — fixtures "Printer jams" and "Printing to wrong tray"; `q=print` returns **both**. **This fails under natural-language mode**, which is the point.
9. `test_searches_reference_exactly` — `q=TKT-2026-000002` returns that ticket.
10. `test_searches_reference_partially` — `q=000002` returns it too.
11. `test_searches_requester_name` and `test_searches_requester_email`.
12. `test_exact_reference_is_ordered_first` — **AC3.** A fixture where another ticket's *subject* contains the reference string; assert the exact-reference ticket is `data.0`.
13. `test_orders_by_relevance_when_no_sort_given` — a subject match ranks above a ticket that matched only through its requester's name.
14. `test_composes_with_status_and_category_filters` — **AC4.** `q` plus two filters returns only the intersection; assert a ticket matching `q` but not the status filter is **absent**. This is the test that catches an `orWhere` escaping its group.
15. `test_composes_with_an_explicit_sort` — `q=print&sort=created_at&direction=asc` orders by date, **not** relevance.
16. `test_relevance_sort_without_q_is_rejected` — `?sort=relevance` → `422` naming `sort`.
17. `test_boolean_operator_input_does_not_error` — parameterised over `C++`, `Q&A (urgent)`, `+-`, `***`, `a)b(`, `@corp`, `<>`, `"unclosed` → every one `200`. **Assert the status, not the row count**; the point is the absence of a 500.
18. `test_short_and_stopword_terms_return_no_fulltext_hits` — `q=on` and `q=zzz` (absent everywhere) → `200` with zero rows. Pick terms absent from every reference, name **and** email, or the requester branch will match.
19. `test_whitespace_only_q_is_treated_as_no_search` — `?q=%20%20` returns the full unfiltered queue.
20. `test_q_over_255_characters_is_rejected` — `422`.
21. `test_like_wildcards_in_q_are_escaped` — `q=%` does **not** return every ticket.
22. `test_searches_arabic_subject` — an Arabic subject matched by an Arabic term.
23. `test_query_count_does_not_grow_with_search` — a search matching **at least two rows**, every ticket assigned (per Story 19's assignee-batch note); assert the count equals the same query with no `q`. Comment that a zero-match search collapses to 1 query.
24. `test_soft_deleted_tickets_are_not_searchable`.

### Frontend

25. **`frontend/src/lib/ticketQuery.spec.ts`** (extend Story 20's) — `toQuery` emits `q` when set and omits it when empty; round-trips `q`; **`fromQuery({sort: 'relevance'})` with no `q` returns `sort: 'created_at'`**; `fromQuery({sort: 'relevance', q: 'x'})` keeps relevance. **4 tests.**
26. **`frontend/src/stores/tickets.spec.ts`** (extend) — `applySearch('printer')` sends `q` and resets `page` to 1; an empty term omits `q` entirely (`expect.not.objectContaining`); **`applySearch('')` while `sort === 'relevance'` resets the sort to `created_at`/`desc`**; `q` counts toward `activeFilterCount`; `clearAll()` clears `q`. **5 tests.**
27. **`frontend/src/components/TicketFilterBar.spec.ts`** (extend) — use `vi.useFakeTimers()`. Typing does **not** call `applySearch` before 300 ms; it calls it **once** after `vi.advanceTimersByTime(300)`; three fast keystrokes produce **exactly one** call (**AC5**); unmounting mid-debounce never calls it; `store.q` changing externally (Clear all) re-syncs the input. **5 tests.**
28. **`frontend/src/views/TicketListView.spec.ts`** (extend) — mounting at `/tickets?q=printer` hydrates `store.q` and renders the term in the input; clearing the search rewrites the URL without `q`. **2 tests.**

**Story 19's and Story 20's suites must pass unchanged** — that is what proves this story is additive. In particular Story 20's `test_defaults_to_newest_first` must stay green, which it only does because the relevance default is gated on `filled($q)`.

---

## Verification Steps

1. **Stories 19 and 20 are actually done.** `php artisan test --filter='TicketIndexTest|TicketFilterTest|TicketSortTest'` passes and `backend/app/Http/Requests/Api/V1/IndexTicketRequest.php` exists. **If not, stop.**
2. **Backend formats and passes:** from `backend/`, `./vendor/bin/pint --test`, then `composer test`. Expect **165 tests / 162 passing**, only TM-14's and TM-21's failures red.
3. **The new suites alone:** `php artisan test --filter='TicketSearchTest'` — both the unit and feature classes.
4. **Prove the `DatabaseTruncation` requirement is real, and leave a witness.** Temporarily change `TicketSearchTest` to `use RefreshDatabase;` and run `--filter=test_searches_subject`. It must **fail with zero rows found** — no error, no warning. Restore `DatabaseTruncation`. This is the trap that costs a day if nobody has seen it fail.
5. **Prove the FULLTEXT index is really being used.** Run `--filter=test_fulltext_index_is_actually_used`, then temporarily rewrite `TicketSearch::apply()` to put `whereFullText` directly in the `orWhere` chain instead of the `whereIn` subquery, and confirm by hand:
   ```bash
   docker exec tm-mysql-test mysql -uroot -proot_secret ticket_management_test -e \
     "EXPLAIN SELECT * FROM tickets WHERE MATCH(subject,description) AGAINST('+print*' IN BOOLEAN MODE) OR reference='X'\G" | grep -E 'type|key:'
   ```
   Expect **`type: ALL`, `key: NULL`** — the scan AC2 forbids. Restore the subquery and re-run the same `EXPLAIN` without the `OR` to see `type: fulltext`.
6. **Prove the sanitiser prevents a 500.** Temporarily bypass `booleanQuery()` and pass raw `q` to `whereFullText`, then `curl` with `?q=foo%20%2B` (i.e. `foo +`). Expect **`1064 syntax error`** surfacing as a 500. Restore the sanitiser and confirm the same request is a `200`.
7. **Backend by hand**, with a token:
   - `…/tickets?q=print` → both "Printer" and "Printing" tickets.
   - `…/tickets?q=TKT-2026-000002` → that ticket as `data.0`.
   - `…/tickets?q=C%2B%2B` and `…/tickets?q=Q%26A%20(urgent)` → `200`, **no 500**.
   - `…/tickets?q=print&status_id[]=<id>` → intersection only.
   - `…/tickets?q=print&sort=created_at&direction=asc` → date order, not relevance.
   - `…/tickets?sort=relevance` → `422`.
   - `…/tickets?q=%25` → not every ticket.
8. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npx prettier --check` on task 10's list, `npm test` — **78 tests across 13 files**.
9. **Frontend by hand:** `npm run dev`, sign in, go to `/tickets`.
   - Type `print` **slowly**, watching the network tab: **one** request, ~300 ms after you stop. *(AC5.)*
   - Confirm the URL becomes `/tickets?q=print` and a new tab on that URL reproduces the search with the input filled.
   - Add a status filter on top of the search → both apply, and the URL carries both.
   - **Clear the search box while relevance-sorted** → the list reloads with no `422` in the console. *(The task 6 reset.)*
   - Click **Clear all** → URL returns to a bare `/tickets` and the input empties.
   - Type `C++` → results (probably empty), **no 500** in the network tab.
10. **Regression:** with an empty search, confirm the default order is still newest-first (Story 19's contract), and file a ticket from `/tickets/new`.

---

## Done Criteria

- [ ] A single `q` searches `reference`, `subject`, `description` and the requester's `name` and `email`.
- [ ] `subject` and `description` go through `tickets_subject_description_fulltext`, proven by a test asserting `EXPLAIN` reports `type=fulltext` — **not** by a passing search alone.
- [ ] The fulltext branch is a `whereIn('id', <subquery>)`; `MATCH` never appears directly in an `OR`.
- [ ] `q=print` matches both "Printer" and "Printing" (boolean mode, trailing wildcard).
- [ ] An exact `reference` is `data.0`, case-insensitively.
- [ ] With `q` and no `sort`, order is exact-reference → FULLTEXT score → newest; with an explicit `sort`, that sort wins.
- [ ] `sort=relevance` without `q` is a `422`, and neither the UI nor a stale bookmarked URL can send it.
- [ ] `q` composes with all five Story 20 filters; a ticket matching `q` but failing a filter is absent.
- [ ] `C++`, `Q&A (urgent)`, `+-`, `***`, `a)b(`, `@corp`, `<>` all return `200` — **no `1064` reaches the client**.
- [ ] Terms under 3 characters and stopwords return `200` with no fulltext hits; whitespace-only `q` is no search at all.
- [ ] `%` and `_` in `q` are literal, not wildcards.
- [ ] An Arabic term matches an Arabic subject, with no parser option.
- [ ] Search adds **zero** queries versus the same request without `q`.
- [ ] Every test touching `MATCH` uses `DatabaseTruncation`, with the reason recorded in a class docblock.
- [ ] The SPA input is debounced at 300 ms — three fast keystrokes produce exactly one request — and the timer is cleared on unmount.
- [ ] `q` appears in the URL, restores from it, and is cleared by **Clear all**.
- [ ] `docs/api-contract.md` documents `q`, the 3-character floor, the stopword caveat, the operator stripping, and the relevance rules.
- [ ] Stories 19's and 20's suites pass **unchanged**.
- [ ] Backend **165 / 162 passing**; frontend **78 across 13 files**; `pint --test`, `lint`, `typecheck` clean.
- [ ] No new dependency, no new migration, no new endpoint.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 22 (TM-26, ticket detail page).**
