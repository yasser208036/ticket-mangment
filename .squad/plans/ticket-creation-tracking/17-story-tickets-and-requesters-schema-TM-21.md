# Story 17 — Tickets and requesters schema with a readable reference (Story: TM-21)

## Prerequisites

- **Story 13 (TM-16) is implemented.** Verified 2026-08-26: `categories`, `priorities` and `statuses` exist as tables and as models. Every foreign key in this story points at one of them or at `users`, so **nothing here migrates without it**. Confirm with `php artisan db:table categories priorities statuses`.
- **Story 06 (TM-8) is implemented** — `users` exists, and `assigned_to`, `created_by` and `escalated_by` all reference it.
- **Nothing else blocks this story.** It adds no route, no controller, no request, no resource and no policy, so it cannot conflict with in-flight work in **categories-priorities-statuses** or **authentication-agent**.
- **This story unblocks four others and opens one hole.** [`../categories-priorities-statuses/15-story-protect-referential-integrity-on-category-delete-TM-18.md`](../categories-priorities-statuses/15-story-protect-referential-integrity-on-category-delete-TM-18.md) is hard-blocked on this one, and **the moment this story lands an admin can orphan tickets** by soft-deleting a category — `ON DELETE RESTRICT` does not stop it, because a soft delete is an `UPDATE`. **TM-18 must ship immediately after this story, in the same sprint.** TM-22, TM-23 and TM-45 are also blocked on it.
- **Docker services running:** repo root — `docker compose up -d`; all three containers `healthy`. Everything below was measured against `mysql:8.4.11` in `tm-mysql-test` on **3307**, which reports `REPEATABLE-READ`, `utf8mb4` / `utf8mb4_unicode_ci`, and `innodb_ft_min_token_size = 3`.
- **Measured baselines, 2026-08-26.** Backend: `php artisan test` → **93 tests, 92 passing, 274 assertions**. The one red test is `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (expects `422`, gets `429`) — **TM-14's defect, out of scope, do not fix it and do not let it mask a new failure.** Frontend: **24 tests across 6 files**; this story changes nothing under `frontend/`.

---

## Story Goal

The two tables everything after this story writes to, plus a reference number that survives concurrency.

1. `requesters` — contact records with a unique email and **no login capability**: no password, no role, no relationship to `users`.
2. `tickets` — every column TM-22 through TM-45 will write, all foreign keys constrained, soft deletes, and the five indexes the queue and the dashboard read through.
3. A `TKT-YYYY-NNNNNN` reference that is unique, gap-free and **collision-free under concurrent creates** — which, as measured below, the obvious implementation is not.
4. A `FULLTEXT` index over `subject` and `description` that works in English and Arabic.

**Not in scope:** the create endpoint, the `TicketResource`, `TicketPolicy` and any route (**TM-22**); the list, filters and search (**TM-23**, **TM-24**, **TM-25**); `ticket_activities` and `ActivityRecorder` (**Story 15 / TM-45**); factories and the demo seeder (**TM-59**); the workflow transition table (**TM-37**). This story writes **schema, models and one service** — nothing under `backend/routes/`, `backend/app/Http/` or `frontend/`.

---

## Product rules (from story)

### `SELECT MAX(reference) … FOR UPDATE` does not work, and it fails loudly

The obvious implementation of criterion 3 is to read the highest reference for the year inside a transaction with a locking read, add one, and insert. **It deadlocks.** Measured on `mysql:8.4.11`, two concurrent sessions against an empty year:

```
S1: START TRANSACTION;
    SELECT IFNULL(MAX(reference),'none') FROM tickets WHERE reference LIKE 'TKT-2027-%' FOR UPDATE;  → 'none'
S2: (1 s later, same statement)                                                                      → 'none'   ← not blocked
S1: INSERT … VALUES ('TKT-2027-000001', …)                                                           → OK
S2: INSERT … VALUES ('TKT-2027-000001', …)                                                           → ERROR 1213 (40001) Deadlock found
```

Both sessions read `none`, because **InnoDB gap locks do not conflict with each other** — a gap lock only blocks an *insert* into the gap, never another gap lock. So two callers compute the same number and collide on the unique index, and because each is already holding a gap lock the collision surfaces as a **deadlock**, not a clean `1062`. A retry loop would paper over it; the plan does not use one.

`EXPLAIN` on that query also shows `type: index` — a **full scan of `tickets_reference_unique`** on every create, which gets slower with every ticket ever filed.

### What does work: a one-row-per-year sequence table and a single atomic statement

Measured, same two-session setup:

```sql
INSERT INTO ticket_sequences (year, next_number) VALUES (?, LAST_INSERT_ID(1))
  ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1);
SELECT LAST_INSERT_ID();
```

| Scenario | Measured result |
|---|---|
| Two sessions, **row already exists** | S1 got `1` and held the row lock; **S2 blocked for 2 s** until S1 committed, then got `2`. No duplicates. |
| Two sessions, **first ticket of the year**, row does not exist | One got `1`, the other got `2`, `next_number` ended at `2`. **No deadlock** — a single unique key means the duplicate-key path is not deadlock-prone. |
| Through Laravel, three sequential calls | `TKT-2026-000001`, `TKT-2026-000002`, `TKT-2026-000003` — `DB::statement` and `DB::selectOne('SELECT LAST_INSERT_ID()')` share one PDO session. |
| Allocate, then throw inside `DB::transaction` | `next_number` went back to `3` after allocating `4`. **The number is returned, not burned.** |

The last row is why the whole thing must sit inside the caller's transaction: the row lock is held for the duration of the ticket insert, so a rollback gives the number back and no gap appears. **One statement, no read-modify-write window, no retry loop.**

### The FULLTEXT index is invisible inside an open transaction — and `RefreshDatabase` opens one

This is the finding most likely to waste a day. InnoDB updates a `FULLTEXT` index at **commit** time. Measured:

```
BEGIN;
INSERT INTO tickets … ('TKT-2099-000001', 'Kerberos ticket expired', 'The kerberos handshake fails …');
SELECT COUNT(*) … WHERE subject LIKE '%Kerberos%'                                → 1
SELECT COUNT(*) … WHERE MATCH(subject, description) AGAINST('kerberos' …)        → 0     ← index not updated yet
COMMIT;
SELECT COUNT(*) … WHERE MATCH(subject, description) AGAINST('kerberos' …)        → 1
```

`RefreshDatabase` wraps every test in a transaction it rolls back. **Any test that inserts a ticket and then searches it with `MATCH … AGAINST` finds nothing, silently.** It does not error; it returns zero rows and the assertion fails with a message that points nowhere.

So the one test in this story that proves the index actually works uses **`Illuminate\Foundation\Testing\DatabaseTruncation`** instead — it truncates between tests and runs no wrapping transaction. Every other test in this story keeps `RefreshDatabase`. **TM-25's entire search suite inherits this**; it is recorded in the overview.

### FULLTEXT behaviour, measured — what TM-25 can and cannot promise

Against the seeded shape, with two English and two Arabic tickets:

| Query | Result |
|---|---|
| `AGAINST('ThinkPad')` | matches — English works |
| `AGAINST('الطابعة')` | matches — **Arabic works with the default parser**, no `ngram` needed |
| `AGAINST('لا')` — 2 characters | **no match** — below `innodb_ft_min_token_size = 3` |
| `AGAINST('on')` — 2 characters | **no match**, same reason |
| `AGAINST('VPN*' IN BOOLEAN MODE)` | matches — prefix search works |
| `AGAINST('the')` | **no match** — `the` is in `INNODB_FT_DEFAULT_STOPWORD` |
| A non-stopword present in **every** row | matches all 4 — **InnoDB has no 50 % rule**; that is MyISAM behaviour and does not apply here |

Two consequences for **TM-25**: a search term shorter than three characters returns nothing from `MATCH`, and common English stopwords are unsearchable. Its `q` parameter also covers `reference` and the requester's name and email, neither of which is in this index — those stay exact-match and `LIKE`.

### The escalation and lifecycle columns come from stories that are not written yet

Criterion 2 says "the escalation columns and the resolved/closed timestamps" without naming them. They are named in `tools/jira/backlog.json`:

- **`E6-S5` (TM-41)** — "increments `escalation_level` and stores `escalated_at`, `escalated_by` and `escalation_reason`".
- **`E6-S3` (TM-39)** — "`resolved_at` is stamped … Transitioning into Closed stamps `closed_at` … `first_responded_at` is stamped the first time a ticket leaves the New status".

All seven ship here, nullable, so those stories are a `->update()` and not a migration.

### Indexes: declare the five criterion 4 asks for, and no more

Measured: declaring `$table->index('status_id')` alongside `$table->foreignId('status_id')->constrained()` produces **one** index, not two — MySQL uses the explicit index to satisfy the foreign key instead of creating its own, and it is order-independent because Laravel emits indexes in the `CREATE TABLE` and foreign keys in a following `ALTER TABLE`. The explicit form is preferred here because it gives stable, assertable names (`tickets_status_id_index`, not `tickets_status_id_foreign`).

**Do not declare a standalone index on `assigned_to`.** Measured on the full table: with `(assigned_to, status_id)` present, no `tickets_assigned_to_foreign` key is created at all — the composite's leftmost prefix satisfies the foreign key. Adding one would be pure duplication.

**Do not index `deleted_at`.** Almost every row is `NULL`, so the index would never be chosen, and it would be written on every insert.

### Neither `reference` nor `created_by` is fillable — and that does not break TM-59

TM-27 requires `reference` and `created_by` to be immutable through the API, and TM-22 requires `created_by` to come from the authenticated user and never from the request body. Both are therefore **excluded from `#[Fillable]`** and set as properties.

That does not break **TM-59**'s factory: `Factory::makeInstance()` wraps model construction in `Model::unguarded(…)` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php:523–525`), so factories bypass `$fillable` entirely. This is unlike `is_default_unique`, which TM-16's overview warns TM-59 about: that column *physically* rejects writes with `ERROR 3105`, whereas these two are only guarded at the application layer.

---

## Context — Read These Files First

1. `backend/database/migrations/2026_08_26_073219_create_statuses_table.php` — 37 lines. The repo's migration idiom: `Schema::create` with a closure, `up()` / `down()` doc blocks, `dropIfExists` in `down()`. Match it exactly.
2. `backend/database/migrations/2026_08_26_073217_create_categories_table.php` — 33 lines. Note `$table->softDeletes()` at **26** and `$table->char('color', 7)` at **20** — the precedent for using `char` on a fixed-width value, which `reference` follows.
3. `backend/app/Models/Category.php` — 34 lines. `#[Fillable]` as a class attribute (**12**), `casts()` as a method (**18–21**), `SoftDeletes` (**16**), and scopes as `scopeXxx(Builder $query)` with a `@param Builder<Category>` docblock (**23–33**). **`Ticket` and `Requester` match this shape; do not use `protected $fillable`.**
4. `backend/app/Models/Status.php` — **line 17**, `'bucket' => StatusBucket::class`. How a backed enum is cast. `Ticket` has no enum column, but the `casts()` placement is the same.
5. `backend/app/Models/User.php` — read `#[Fillable]` / `#[Hidden]` and the `casts()` method. `assigned_to`, `created_by` and `escalated_by` all point at this table.
6. `backend/tests/Feature/Database/UsersTableSchemaTest.php` — whole file, 55 lines. **The schema-test idiom this story copies**: `Schema::hasColumns` (**22**), `Schema::getColumns(...)` for defaults (**32**), and `$this->expectException(QueryException::class)` to prove a constraint bites (**43–47, 49–54**).
7. `backend/tests/Feature/Database/AdminUserSeederTest.php` — the other test in that directory; match its naming.
8. `tools/jira/backlog.json` — stories `E6-S3` and `E6-S5` for the exact escalation and lifecycle column names, and `E4-S3` through `E4-S8`, `E5-S1`, `E6-S2` for what reads them. **Read them before renaming anything below.**
9. [`../categories-priorities-statuses/15-story-protect-referential-integrity-on-category-delete-TM-18.md`](../categories-priorities-statuses/15-story-protect-referential-integrity-on-category-delete-TM-18.md) — its **task 5** adds `Category::tickets()`. **Task 4 below adds the same method.** Whichever lands second must check before writing it; this plan claims it because this story creates the foreign key.
10. `backend/phpunit.xml` — **lines 27–42**. The comment there explains why the suite runs against real MySQL: this story's `FULLTEXT`, `ENUM` and `ON DELETE RESTRICT` are exactly what SQLite would accept and production would reject. **Do not point anything at SQLite.**
11. `docs/erd.md` — **lines 14–53** (the `erDiagram` block) and **57–62** (the table-notes table). Task 8 extends both.

---

## Backend Tasks

### 1 — The requesters migration

**Create file: `backend/database/migrations/<timestamp>_create_requesters_table.php`** — generate with `php artisan make:migration create_requesters_table`. It must sort **before** the tickets migration, because `tickets.requester_id` references it.

```php
Schema::create('requesters', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('phone', 32)->nullable();
    $table->string('company')->nullable();
    $table->timestamps();
});
```

Criterion 1 says "**no login capability**", and that is a statement about what this table must *not* have: **no `password`, no `role`, no `is_active`, no `remember_token`, and no foreign key to `users`.** A requester is a contact record. `Requester` must not extend `Authenticatable` and must never be added to a guard in `config/auth.php`.

`email` is `NOT NULL` and unique because **TM-22** matches an existing requester by email and **TM-53** emails them a confirmation. `phone` at 32 characters holds an international number with separators; `company` is a plain string.

No soft deletes: nothing deletes a requester, and `tickets.requester_id` is `RESTRICT`, so a requester with tickets cannot be removed even by hand.

### 2 — The ticket sequence migration

**Create file: `backend/database/migrations/<timestamp>_create_ticket_sequences_table.php`**

```php
Schema::create('ticket_sequences', function (Blueprint $table) {
    // One row per calendar year. The primary key is the year itself, so the
    // upsert in TicketReferenceGenerator has exactly one unique key to
    // collide on — which is what keeps the first-ticket-of-the-year race
    // deadlock-free (measured on mysql:8.4).
    $table->unsignedSmallInteger('year')->primary();
    $table->unsignedInteger('next_number')->default(0);
});
```

**No `timestamps()`.** This is a counter, not a record.

**Do not add a second unique index.** The deadlock-freedom measured above depends on there being exactly one.

### 3 — The tickets migration

**Create file: `backend/database/migrations/<timestamp>_create_tickets_table.php`** — timestamp must sort after `create_requesters_table`, after `create_ticket_sequences_table`, and after TM-16's three master-data migrations (`2026_08_26_0732…`).

```php
Schema::create('tickets', function (Blueprint $table) {
    $table->id();
    // TKT-YYYY-NNNNNN is exactly 15 characters, so char rather than varchar —
    // same reasoning as categories.color being char(7).
    $table->char('reference', 15)->unique();
    $table->string('subject');
    $table->text('description');

    $table->foreignId('requester_id')->constrained()->restrictOnDelete();
    $table->foreignId('category_id')->constrained()->restrictOnDelete();
    $table->foreignId('priority_id')->constrained()->restrictOnDelete();
    $table->foreignId('status_id')->constrained()->restrictOnDelete();
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

    // Escalation — TM-41 writes all four.
    $table->unsignedTinyInteger('escalation_level')->default(0);
    $table->timestamp('escalated_at')->nullable();
    $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('escalation_reason')->nullable();

    // Lifecycle — TM-39 writes all three.
    $table->timestamp('first_responded_at')->nullable();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamp('closed_at')->nullable();

    $table->timestamps();
    $table->softDeletes();

    // Criterion 4. status_id, category_id and priority_id are declared
    // explicitly rather than left to the foreign keys: measured, MySQL uses
    // the explicit index to satisfy the constraint instead of creating its
    // own, so this costs no extra index and gives assertable names.
    // assigned_to is deliberately absent — the composite's leftmost prefix
    // already satisfies its foreign key, measured.
    $table->index('status_id');
    $table->index('category_id');
    $table->index('priority_id');
    $table->index('created_at');
    $table->index(['assigned_to', 'status_id']);

    // Criterion 5. Arabic works with the default parser; ngram is not needed.
    $table->fullText(['subject', 'description']);
});
```

The four `restrictOnDelete()` calls are criterion 6 and they matter beyond this story: **TM-18's whole reason for existing is that `RESTRICT` does not stop a soft delete.** Do not weaken any of them to `cascadeOnDelete()`.

`assigned_to` and `escalated_by` are `nullOnDelete()`: users are deactivated rather than deleted (`UserPolicy::delete()` returns `false`), so this never fires today, but a hard delete must unassign rather than take the ticket with it.

`description` is `text`, not `longText` — 64 KB is a support ticket, and `FULLTEXT` behaves identically on both (measured on `longtext`, and `text` is the same index type).

**Verify the emitted DDL after running the migration.** `SHOW CREATE TABLE tickets` must show exactly 11 keys: `PRIMARY`, `tickets_reference_unique`, `tickets_status_id_index`, `tickets_category_id_index`, `tickets_priority_id_index`, `tickets_created_at_index`, `tickets_assigned_to_status_id_index`, `tickets_requester_id_foreign`, `tickets_created_by_foreign`, `tickets_escalated_by_foreign`, and `FULLTEXT KEY tickets_subject_description_fulltext`. **There must be no `tickets_assigned_to_foreign` key** — if one appears, a standalone `assigned_to` index was added by mistake.

### 4 — The models

**Create file: `backend/app/Models/Requester.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contact record, not a login. This model deliberately does not extend
 * Authenticatable, has no password and belongs to no guard: requesters are
 * people tickets are about, not people who sign in.
 */
#[Fillable(['name', 'email', 'phone', 'company'])]
class Requester extends Model
{
    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
```

**Create file: `backend/app/Models/Ticket.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `reference` and `created_by` are absent from #[Fillable] on purpose: TM-22
 * takes created_by from the authenticated user and TM-27 requires both to be
 * immutable through the API. Set them as properties. TM-59's factories are
 * unaffected — Factory::makeInstance() builds models inside Model::unguarded().
 */
#[Fillable([
    'subject', 'description', 'requester_id', 'category_id',
    'priority_id', 'status_id', 'assigned_to',
])]
class Ticket extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'escalation_level' => 'integer',
            'escalated_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Requester, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Requester::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Priority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    /** @return BelongsTo<Status, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }
}
```

`escalation_reason` is **not** in `#[Fillable]` — TM-41's escalate endpoint sets it alongside `escalated_at` and `escalated_by`, and TM-27 must not let a plain `PATCH` rewrite an escalation reason. Neither are the four timestamp columns; TM-27's criterion 4 says all timestamps are immutable through the API.

**File: `backend/app/Models/Category.php`** — add the inverse relation after `scopeOrdered()` (**30–33**) and the `HasMany` import:

```php
/**
 * Soft-deleted tickets are still tickets — TM-28 can restore one, and a
 * restored ticket must still have a category. Callers that count or reassign
 * add withTrashed(); the relation itself stays conventional.
 *
 * @return HasMany<Ticket, $this>
 */
public function tickets(): HasMany
{
    return $this->hasMany(Ticket::class);
}
```

**This is the same method Story 15 (TM-18) task 5 specifies.** If Story 15 has already landed, it is already there — check first and do not duplicate it. Change nothing else in that file.

**Do not add** `Priority::tickets()`, `Status::tickets()`, `User::assignedTickets()` or `User::createdTickets()`. Nothing in this story or the next reads them; **TM-33** (my tickets) and **TM-35** (agent workload) add the two on `User` when they need them.

**Do not add `#[UsePolicy]` to `Ticket`.** `TicketPolicy` arrives with the endpoints in **TM-22**, which `docs/api-contract.md:28` already promises.

### 5 — The reference generator

**Create file: `backend/app/Services/TicketReferenceGenerator.php`** — if `backend/app/Services/` does not exist yet, create it. Story 15 (TM-18) puts `ActivityRecorder` in the same namespace; whichever lands first creates the directory.

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocates the next TKT-YYYY-NNNNNN.
 *
 * The obvious implementation — SELECT MAX(reference) … LIKE 'TKT-2027-%' FOR
 * UPDATE — does not work. Measured on mysql:8.4: InnoDB gap locks do not
 * conflict with one another, so two concurrent callers both read the same
 * maximum, both compute the same number, and the pair of inserts fails with
 * ERROR 1213 (deadlock) rather than a clean duplicate-key error.
 *
 * One row per year and one atomic upsert removes the read-modify-write window
 * entirely. The row lock is held until the caller's transaction commits, so a
 * rollback returns the number instead of burning it — measured.
 */
class TicketReferenceGenerator
{
    public function next(?int $year = null): string
    {
        // The row lock is only useful for as long as the caller's transaction
        // lives. Called outside one, this would autocommit and leak a gap on
        // any later failure. Same contract as ActivityRecorder (TM-18/TM-45).
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TicketReferenceGenerator must be called inside a database transaction.');
        }

        $year ??= (int) now()->year;

        DB::statement(
            'INSERT INTO ticket_sequences (year, next_number) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)',
            [$year]
        );

        // useReadPdo: false. There is no read replica configured today, but
        // LAST_INSERT_ID() is per-session and reading it from a replica would
        // silently return 0.
        $number = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n', [], false)->n;

        if ($number > 999999) {
            throw new LogicException("Ticket references for {$year} are exhausted: the TKT-YYYY-NNNNNN format holds 999999 per year.");
        }

        return sprintf('TKT-%04d-%06d', $year, $number);
    }
}
```

**`LAST_INSERT_ID(1)` in the `VALUES` clause is load-bearing.** On the insert branch — the first ticket of a year — it sets the session value to `1`; without it `LAST_INSERT_ID()` would return whatever the previous statement left there, because `ticket_sequences` has no `AUTO_INCREMENT` column. Measured: both branches return the right number.

**Do not add a retry loop.** There is nothing to retry — the upsert is a single statement and callers serialise on the row lock.

`next()` does **not** write to `tickets`. TM-22 calls it inside its own transaction and assigns the result to `$ticket->reference`.

### 6 — What this story does not touch

- **`backend/routes/api.php`** — byte-identical. No route, so `RouteAuthorizationTest`'s `ACCESS` manifest (**line 16**) needs no entry and the file is not edited.
- **`backend/app/Http/`** — nothing added. No controller, request, resource or policy.
- **`backend/database/seeders/`** — nothing added and `DatabaseSeeder` is unchanged. **TM-59** owns factories and the demo seeder; this story's tests build rows with `Model::create([...])` and `DB::table(...)->insert(...)`, as TM-16's and TM-17's do.
- **`frontend/`** — nothing.

### 7 — Migration ordering, checked

After `php artisan migrate`, `php artisan migrate:status` must list the three new files **after** `2026_08_26_073219_create_statuses_table` and in this relative order: `create_requesters_table`, `create_ticket_sequences_table`, `create_tickets_table`. `make:migration` timestamps them in creation order, so **create them in that order**.

**Story 15 (TM-18)'s `create_ticket_activities_table` must sort after `create_tickets_table`** — its `ticket_id` foreign key needs this table. Its plan already says so; this note is the other half of the handshake.

### 8 — The ERD

**File: `docs/erd.md`**

Add `requesters`, `tickets` and `ticket_sequences` to the `erDiagram` block (**14–53**), and the relationships: `requesters ||--o{ tickets`, `categories ||--o{ tickets`, `priorities ||--o{ tickets`, `statuses ||--o{ tickets`, and `users ||--o{ tickets` three times over (`assigned_to`, `created_by`, `escalated_by`).

Add three rows to the table-notes table (**57–62**), matching its existing format:

| `requesters` | Contact records for the people tickets are about. **No login capability** — no password, no role, no guard. | TM-21 |
| `tickets` | The core record. Soft-deleted; every foreign key is `RESTRICT` except the three user references, which are `SET NULL`. | TM-21 |
| `ticket_sequences` | One row per year backing `TKT-YYYY-NNNNNN`. Written only by `TicketReferenceGenerator`. | TM-21 |

Add a note under the table recording the two measured constraints future readers will otherwise rediscover the hard way: **a `FULLTEXT` index is only updated at commit, so it is invisible inside an open transaction**, and **`innodb_ft_min_token_size = 3`, so terms shorter than three characters never match**.

**Do not touch `docs/api-contract.md`** — this story adds no endpoint.

---

## Edge Cases & Failure Modes

- **Two tickets created in the same millisecond.** Serialised on the `ticket_sequences` row lock (task 5). Measured: the second caller blocked 2 s until the first committed, then received the next number. The rejected alternative deadlocks — see Product rules.

- **The first ticket of a new year, created by two requests at once.** Both take the `ON DUPLICATE KEY UPDATE` path against a single primary key; measured, one got `1`, the other `2`, and no deadlock occurred. A second unique index on `ticket_sequences` would break this, which is why task 2 forbids one.

- **The ticket insert fails after the reference was allocated.** The number is returned, not burned — measured: `next_number` went back to `3` after allocating `4` and throwing inside `DB::transaction`. This only holds because `next()` refuses to run outside a transaction.

- **`TicketReferenceGenerator::next()` called outside a transaction.** `LogicException`, immediately. Without the guard the upsert autocommits and every later rollback leaves a permanent gap.

- **The 1,000,000th ticket of a single year.** `LogicException` naming the format limit (task 5). Without the guard, `sprintf('%06d', 1000000)` produces a 16-character string and MySQL rejects it on a `char(15)` column with "Data too long for column" — a `QueryException` that says nothing about references.

- **A test inserts a ticket and searches it with `MATCH … AGAINST`.** **Zero rows, no error**, under `RefreshDatabase` — measured. Use `DatabaseTruncation` for that test only. This is the single most likely way to lose an afternoon in this story and in TM-25.

- **A search term shorter than three characters.** No `MATCH` results, in English or Arabic — `innodb_ft_min_token_size = 3`, measured with `on` and `لا`. TM-25 must fall back to `LIKE` on `reference` for short queries or say so in the UI.

- **A search for an English stopword such as `the`.** No results — it is in `INNODB_FT_DEFAULT_STOPWORD`, confirmed by querying that table. This is not the MyISAM 50 % rule, which does not apply to InnoDB: a non-stopword present in all four rows matched all four, measured.

- **An Arabic subject and description.** Indexed and searchable with the default parser, measured (`الطابعة` matched). Both containers run `--character-set-server=utf8mb4`, and every new column here inherits `utf8mb4_unicode_ci`.

- **Deleting a category, priority or status that has tickets.** `ERROR 1451` from `ON DELETE RESTRICT` on a hard delete — but a **soft** delete is an `UPDATE` and passes straight through, and a soft-deleted category still accepts new tickets because the foreign key cannot see `deleted_at`. Both measured during Story 15's planning. **This story opens that hole; TM-18 closes it.**

- **Deleting a user who has tickets.** `created_by` is `RESTRICT`, so a creator with tickets cannot be removed; `assigned_to` and `escalated_by` are `SET NULL`. Nothing deletes users today — `UserPolicy::delete()` returns `false` — so this is a guard, not a behaviour.

- **A requester email that already exists.** `1062` on `requesters_email_unique`, surfacing as a `QueryException`. **TM-22** must match on email *before* inserting rather than catching this; the constraint is the backstop.

- **A soft-deleted ticket.** Stays in the `FULLTEXT` index and in every foreign-key relationship; only Eloquent's global scope hides it. **TM-25 must add `deleted_at IS NULL` to its search query** — `MATCH … AGAINST` does not do it for you, and `Ticket::search(...)` through the model does.

- **`migrate:fresh` with the three new migrations.** Runs clean only if the timestamps order them after the master-data tables. Task 7 says to check `migrate:status`; a wrong order fails on the first foreign key with `errno 150`.

---

## Test Plan

All from `backend/`, against `tm-mysql-test` on **3307** — never SQLite (`phpunit.xml:27–42` explains why, and this story is the reason). Fixtures use `Model::create([...])` and `DB::table(...)->insert(...)`; there is no `TicketFactory` (**TM-59**).

1. **Create `backend/tests/Feature/Database/RequestersTableSchemaTest.php`** — 4 tests, following `UsersTableSchemaTest.php`.
   - `test_it_has_required_columns` — `Schema::hasColumns('requesters', ['name','email','phone','company','created_at','updated_at'])`.
   - `test_it_has_no_login_columns` — `password`, `role`, `is_active` and `remember_token` all absent. **This is criterion 1's real assertion**; column presence alone does not prove "no login capability".
   - `test_email_is_unique` — second insert raises `QueryException`.
   - `test_phone_and_company_are_nullable` — a row with both null inserts cleanly.
2. **Create `backend/tests/Feature/Database/TicketsTableSchemaTest.php`** — 9 tests.
   - `test_it_has_every_column` — all 21 columns from task 3.
   - `test_escalation_level_defaults_to_zero`.
   - `test_lifecycle_timestamps_are_nullable` — `first_responded_at`, `resolved_at`, `closed_at`, `escalated_at`, `escalated_by`, `escalation_reason`, `assigned_to` all accept null.
   - `test_reference_is_unique` — `QueryException` on a duplicate.
   - `test_required_indexes_exist` — via `Schema::getIndexes('tickets')`, assert `tickets_status_id_index`, `tickets_category_id_index`, `tickets_priority_id_index`, `tickets_created_at_index` and `tickets_assigned_to_status_id_index` with columns `['assigned_to','status_id']`. Criterion 4, one test.
   - `test_no_redundant_assigned_to_index` — no index whose columns are exactly `['assigned_to']`. Guards the measurement in Product rules.
   - `test_fulltext_index_covers_subject_and_description` — the entry named `tickets_subject_description_fulltext` has `type` `fulltext` and columns `['subject','description']`. `Schema::getIndexes()` reports both — verified during planning.
   - `test_foreign_keys_and_delete_rules` — via `Schema::getForeignKeys('tickets')`, assert `restrict` for `requester_id`, `category_id`, `priority_id`, `status_id` and `created_by`, and `set null` for `assigned_to` and `escalated_by`. Criterion 6.
   - `test_soft_delete_column_exists_and_works` — `$ticket->delete()`, then `Ticket::count()` is 0 and `Ticket::withTrashed()->count()` is 1.
3. **Create `backend/tests/Feature/Database/TicketReferenceTest.php`** — 6 tests, `RefreshDatabase`.
   - `test_first_reference_of_a_year` — inside `DB::transaction`, `next(2026)` returns `TKT-2026-000001`.
   - `test_references_increment` — three calls give `…000001`, `…000002`, `…000003`.
   - `test_years_have_independent_sequences` — `next(2026)` then `next(2027)` gives `TKT-2027-000001`.
   - `test_reference_is_zero_padded_to_six_digits` — set `next_number` to 41, assert `TKT-2026-000042`.
   - `test_calling_outside_a_transaction_throws` — `expectException(LogicException::class)`.
   - `test_rollback_returns_the_number` — allocate inside a transaction that throws, then allocate again and get the **same** number; `ticket_sequences.next_number` is unchanged. This is the criterion 3 test that matters.
4. **Create `backend/tests/Feature/Database/TicketFullTextSearchTest.php`** — 4 tests. **This class uses `Illuminate\Foundation\Testing\DatabaseTruncation`, not `RefreshDatabase`** — put the reason in a class-level docblock quoting the measurement, or the next person will "fix" it back and get four silent zero-row failures.
   - `test_english_subject_is_searchable` — insert, then `MATCH(subject, description) AGAINST('ThinkPad')` finds it.
   - `test_arabic_description_is_searchable` — a ticket described in Arabic is found by an Arabic term.
   - `test_terms_below_the_minimum_token_size_do_not_match` — a two-character term returns nothing, documenting the limit for TM-25 rather than asserting a bug.
   - `test_soft_deleted_tickets_are_excluded_when_queried_through_the_model` — a trashed ticket still sits in the index but `Ticket::whereRaw('MATCH…')->get()` does not return it.
5. **Create `backend/tests/Feature/Models/TicketRelationsTest.php`** — 4 tests, `RefreshDatabase`.
   - `test_ticket_belongs_to_its_master_data` — `requester`, `category`, `priority`, `status` all resolve.
   - `test_assignee_and_creator_resolve_to_users` — including a null `assigned_to` returning null.
   - `test_category_has_many_tickets` — `$category->tickets()->count()`, the relation Story 15 depends on.
   - `test_reference_and_created_by_are_not_mass_assignable` — `Ticket::create([... 'reference' => 'HACK', 'created_by' => 999])` leaves both unset, proving TM-22's and TM-27's guard.
6. **Do not touch** `tests/Feature/Auth/PasswordThrottleTest.php` or `tests/Feature/Authorization/RouteAuthorizationTest.php`. This story registers no route; if the manifest test goes red, a route was added by mistake.

Expected totals: **93 → 120 backend tests** (27 new; the one TM-14 failure still red). No frontend change, so `npm test` stays at 24 across 6 files.

---

## Migration / Rollback

Three new tables, no change to an existing one.

- **Forward:** `php artisan migrate` creates `requesters`, `ticket_sequences`, `tickets` in that order.
- **Back:** `php artisan migrate:rollback --step=3` drops them in reverse. `tickets` must go first — the other two are its parents — which the reverse batch order gives for free.
- **Half-applied state.** Each migration is a single `Schema::create`, so each either exists or does not. A failure on `create_tickets_table` means a parent table is missing or ordered wrong; `php artisan migrate:status` shows which.
- **Rolling back after TM-18 has shipped.** `ticket_activities.ticket_id` references `tickets`; drop that table first or the rollback fails on the foreign key.
- **Data.** Dropping `ticket_sequences` and re-migrating restarts every year's numbering at 1, which will collide with existing references on `tickets_reference_unique`. **Never roll back `create_ticket_sequences_table` alone on an environment with tickets in it.**

---

## Verification Steps

1. **Services up:** repo root — `docker compose up -d && docker compose ps`; all three `healthy`.
2. **Migrations apply in order:** `backend/` — `php artisan migrate`, then `php artisan migrate:status` shows the three new files after `create_statuses_table` and in the order `requesters`, `ticket_sequences`, `tickets`.
3. **The DDL is what task 3 specifies:** `backend/` — `php artisan db:table tickets`, and `docker exec -i tm-mysql mysql -uticket_user -psecret ticket_management -e "SHOW CREATE TABLE tickets\G"`. Confirm the 11 keys listed in task 3, the `FULLTEXT KEY`, the five `ON DELETE RESTRICT` constraints, the two `ON DELETE SET NULL`, and **no `tickets_assigned_to_foreign` key**.
4. **Round-trip:** `backend/` — `php artisan migrate:rollback --step=3 && php artisan migrate` completes with no error.
5. **Reference generation by hand:** `backend/` — `php artisan tinker --execute="DB::transaction(fn () => print(app(App\Services\TicketReferenceGenerator::class)->next()));"` prints `TKT-2026-000001`; running it again prints `…000002`.
6. **The guard bites:** `php artisan tinker --execute="app(App\Services\TicketReferenceGenerator::class)->next();"` — **outside** a transaction — throws `LogicException`.
7. **FULLTEXT works against real data:** `backend/` — insert two tickets through tinker (one English, one Arabic), **commit**, then `php artisan tinker --execute="print_r(DB::select(\"SELECT reference FROM tickets WHERE MATCH(subject, description) AGAINST('الطابعة' IN NATURAL LANGUAGE MODE)\"));"` returns the Arabic one.
8. **Backend tests:** `backend/` — `composer test`. Expect **120 tests, 119 passing**, the single failure being `PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (`422` vs `429`). Any other failure is this story's.
9. **The FULLTEXT test is not silently empty:** `backend/` — `php artisan test --filter=TicketFullTextSearchTest` passes; then temporarily swap `DatabaseTruncation` for `RefreshDatabase` in that class, re-run, and confirm it **fails with zero rows**. Revert. This proves the test is actually exercising the index.
10. **Nothing leaked into the HTTP layer:** `backend/` — `php artisan route:list --path=api/v1` still prints exactly the routes that existed before this story, and `git status --porcelain backend/routes backend/app/Http frontend` is empty.
11. **Formatting:** `backend/` — `./vendor/bin/pint --test` clean.
12. **Docs:** `git diff docs/erd.md` shows the three entities, the six relationships and the three table-note rows. `git diff docs/api-contract.md` is empty.

---

## Done Criteria

- [ ] `requesters` stores `name`, unique `email`, nullable `phone` and `company`, and has **no** `password`, `role`, `is_active` or `remember_token` column and no foreign key to `users`; `Requester` does not extend `Authenticatable`.
- [ ] `tickets` carries all 21 columns: `reference`, `subject`, `description`, the four master-data foreign keys, nullable `assigned_to`, `created_by`, the four escalation columns, `first_responded_at`, `resolved_at`, `closed_at`, timestamps and `deleted_at`.
- [ ] `reference` is `char(15)`, unique, and formatted `TKT-YYYY-NNNNNN` with six zero-padded digits.
- [ ] `TicketReferenceGenerator::next()` allocates through a **single** upsert against a one-row-per-year `ticket_sequences` table, throws `LogicException` outside a transaction, and **returns the number on rollback** rather than burning it — proven by `test_rollback_returns_the_number`.
- [ ] No `SELECT MAX(reference) … FOR UPDATE` and no retry loop appears anywhere; the plan's measurement of why is recorded in the generator's docblock.
- [ ] Indexes exist on `status_id`, `category_id`, `priority_id`, `created_at` and `(assigned_to, status_id)`, with **no redundant standalone `assigned_to` index** — asserted by `test_required_indexes_exist` and `test_no_redundant_assigned_to_index`.
- [ ] A `FULLTEXT` index named `tickets_subject_description_fulltext` covers `subject` and `description`, and is proven to match English **and Arabic** terms against committed data.
- [ ] `TicketFullTextSearchTest` uses `DatabaseTruncation` with the reason recorded in the class docblock, and fails with zero rows if switched to `RefreshDatabase` — verified both ways.
- [ ] Every foreign key is constrained: `RESTRICT` on `requester_id`, `category_id`, `priority_id`, `status_id` and `created_by`; `SET NULL` on `assigned_to` and `escalated_by`. `tickets` uses soft deletes.
- [ ] `reference`, `created_by`, `escalation_reason` and the four lifecycle timestamps are absent from `Ticket`'s `#[Fillable]`, proven by `test_reference_and_created_by_are_not_mass_assignable`.
- [ ] `Category::tickets()` exists exactly once — check whether Story 15 already added it before writing it.
- [ ] `backend/routes/api.php`, `backend/app/Http/`, `backend/database/seeders/` and everything under `frontend/` are unchanged, and `RouteAuthorizationTest` is untouched and still green.
- [ ] `composer test` is **120 tests, 119 passing**, the only failure being TM-14's `PasswordThrottleTest`; `./vendor/bin/pint --test` is clean.
- [ ] `docs/erd.md` carries `requesters`, `tickets` and `ticket_sequences` with their relationships, plus the note recording that a `FULLTEXT` index is invisible inside an open transaction and that `innodb_ft_min_token_size = 3`.
- [ ] The overview records that **TM-18 must ship immediately after this story** — this story opens the orphaning window — and that **TM-25 inherits the `DatabaseTruncation` requirement and the three-character minimum**.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 18.**
