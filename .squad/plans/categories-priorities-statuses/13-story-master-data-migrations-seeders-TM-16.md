# Story 13 — Master data migrations and seeders (Story: TM-16)

## Prerequisites

- **Story 06 (TM-8) implemented** — [`../authentication-agent/06-story-users-table-role-enum-seeded-admin-TM-8.md`](../authentication-agent/06-story-users-table-role-enum-seeded-admin-TM-8.md). Verified present: `backend/app/Enums/UserRole.php` exists, `users.role` is a real MySQL `ENUM`, and `AdminUserSeeder` is wired into `DatabaseSeeder`. This story copies three of its decisions wholesale — the PHP enum as the column domain, the explicit `ENUM` default, and the "an existing row is left completely alone" seeder contract — so read it before writing a line.
- **The environment blockers TM-8 listed are gone,** but the suite is not green. Measured on 2026-08-26 in `backend/`: `composer test` reports **90 tests, 256 assertions, 89 passing and 1 failing**, and `./vendor/bin/pint --test` exits `0`. Those are the regression baseline for this story.
- **One pre-existing failure, and it is not yours.** `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (`backend/tests/Feature/Auth/PasswordThrottleTest.php:14`) expects `422` and gets `429` — the password throttle fires one attempt earlier than the test allows. It fails **in isolation** (`php artisan test --filter=PasswordThrottleTest`), so it is a real defect in TM-14's in-flight work, not cross-test rate-limiter bleed. **TM-14 owns it; do not fix it here and do not let it block this story.** Re-measure the baseline before you start in case TM-14 has since landed a fix.
- **Docker services running:** repo root — `docker compose up -d`; `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all `healthy`. Confirmed `mysql:8.4` (server reports `8.4.11`); every behaviour measured below was measured against that container, not read from documentation.
- **This is the first story in the `categories-priorities-statuses` feature.** `00-overview.md` is still the empty template and `.squad/plans/00-index.md` has no row for this slug — task 9 fills in both.
- **No story blocks on TM-16 finishing first, but three block on it being right.** **TM-17** (category CRUD) and **TM-19** (Pinia master-data store) consume these tables directly; **TM-21** hangs `category_id`, `priority_id` and `status_id` foreign keys off them; **TM-37** builds `status_transitions` on the status ids and reads `is_terminal`; **TM-41** reads `priorities.level` to raise a priority by one step. The column decisions here are the contract those five stories code against.

---

## Story Goal

Create the three master-data tables that classify a ticket, seed each with the defaults the acceptance criteria name, and make **"exactly one default"** a guarantee the database enforces rather than a convention the seeder observes.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | `categories` has `name`, `slug`, `description`, `color`, `is_active`, `sort_order`, timestamps, soft deletes | ❌ **Not met.** `backend/database/migrations/` holds four files (`create_users_table`, `create_cache_table`, `create_jobs_table`, `create_personal_access_tokens_table`) and none of them is `categories`. |
| 2 | `priorities` has `name`, `slug`, `level`, `color`, `is_default`, seeded Low/Medium/High/Urgent | ❌ **Not met.** No table, no model, no seeder. |
| 3 | `statuses` has `name`, `slug`, `bucket`, `color`, `is_default`, `is_terminal`, `sort_order`, seeded with seven rows | ❌ **Not met.** No table, and no `StatusBucket` enum — `backend/app/Enums/` contains exactly one file, `UserRole.php`. |
| 4 | Exactly one priority and one status are marked `is_default` | ❌ **Not met.** See **Product rules** below: this is the only criterion that cannot be satisfied by a column list, and the mechanism that satisfies it is not obvious. |
| 5 | Seeders are idempotent and keyed on slug so re-running does not duplicate rows | ❌ **Not met.** `backend/database/seeders/` holds `DatabaseSeeder.php` (18 lines, calling one seeder on line 17) and `AdminUserSeeder.php`. |

Seven outcomes:

1. `categories`, `priorities` and `statuses` exist with the exact column sets criteria 1–3 name, on `utf8mb4`/`utf8mb4_unicode_ci`, and `categories` soft-deletes.
2. `app/Enums/StatusBucket.php` is the single definition of the bucket values, and the `statuses` migration builds its `ENUM` column from `StatusBucket::values()` — the same anti-drift arrangement TM-8 built for `users.role`.
3. **A unique index on a generated column makes a second default physically impossible** on both `priorities` and `statuses`. `INSERT` and `UPDATE` alike fail with `SQLSTATE 23000` / `1062`; there is no window in which two rows are both flagged.
4. `CategorySeeder` seeds six categories and, on every later run, **leaves every existing row exactly as the admin left it** — including a renamed, recoloured, reordered or soft-deleted one.
5. `PrioritySeeder` and `StatusSeeder` treat the code as the source of truth (`updateOrCreate`), stand the old default down before promoting the new one, and write **zero rows** when nothing changed.
6. `php artisan migrate:fresh --seed` on a clean database yields one admin, six categories, four priorities and seven statuses, with exactly one default priority (**Medium**) and one default status (**New**).
7. `docs/erd.md` carries all three entities and their owning story.

**Not in scope:** every endpoint (`/api/v1/categories` is **TM-17**), the delete-with-reassignment guard (**TM-18**), the Pinia store and any file under `frontend/` (**TM-19**), the `tickets` and `requesters` tables and the foreign keys into these three (**TM-21**), `status_transitions` and the workflow guard (**TM-37**), escalation reading `priorities.level` (**TM-41**), factories for these models and the demo seeder (**TM-59**), and hex-colour *validation* (**TM-17** — see task 3's note on why no `CHECK` constraint is added here).

---

## Product rules — three MySQL behaviours measured before planning

Each of the following was run against `tm-mysql-test` (`mysql:8.4`, server `8.4.11`) during planning. They are the reason three tasks below look the way they do; none of them is guessable from the acceptance criteria.

### 1. "Exactly one default" is enforceable, and a plain unique index will not do it

`UNIQUE (is_default)` allows only **two rows in the whole table** — one flagged, one not. `UNIQUE` over a **generated column that is `1` when flagged and `NULL` otherwise** is the shape that works, because MySQL's unique indexes do not collide on `NULL`:

```sql
CREATE TABLE probe_default (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug varchar(255) NOT NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  is_default_unique tinyint(1) GENERATED ALWAYS AS (IF(is_default = 1, 1, NULL)) VIRTUAL,
  UNIQUE KEY probe_slug_unique (slug),
  UNIQUE KEY probe_default_unique (is_default_unique)
);
INSERT INTO probe_default (slug, is_default) VALUES ('low',0),('medium',1),('high',0),('urgent',0);
```
```
slug     is_default   is_default_unique
low      0            NULL
medium   1            1
high     0            NULL
urgent   0            NULL
```

Both ways of creating a second default are rejected, and the error is identical:

```sql
UPDATE probe_default SET is_default = 1 WHERE slug = 'high';
-- ERROR 1062 (23000): Duplicate entry '1' for key 'probe_default.probe_default_unique'
INSERT INTO probe_default (slug, is_default) VALUES ('critical', 1);
-- ERROR 1062 (23000): Duplicate entry '1' for key 'probe_default.probe_default_unique'
```

Three consequences carried through the rest of the plan:

- **Zero defaults is legal.** Measured: clearing every flag leaves seven rows and `SUM(is_default) = 0` with no error. That is deliberate — it is the only thing that lets a seeder stand the old default down before promoting the new one (task 6).
- **The generated column may never be written to.** `INSERT ... (is_default_unique) VALUES (1)` fails with `ERROR 3105 (HY000): The value specified for generated column 'is_default_unique' in table 'g' is not allowed.` So keeping it out of `#[Fillable]` (task 5) is **load-bearing, not tidiness** — a factory or a mass-assign that included it would produce a 500, not a silently wrong row. **TM-59 must not add it to any factory `definition()`.**
- **The column is real and `SELECT *` returns it.** It therefore has to be listed in `#[Hidden]`, or `is_default_unique` leaks into every API resource TM-17 and TM-19 build.

Laravel expresses this without a raw `DB::statement`. `MySqlGrammar::modifyNullable()` (`vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/MySqlGrammar.php`) emits **no** nullability clause at all for a generated column unless `nullable(false)` is passed, which is exactly what is wanted here. Rendered from a real `Blueprint` during planning:

```
`is_default` tinyint(1) not null default '0',
`is_default_unique` tinyint(1) as (if(`is_default` = 1, 1, null)),
...
alter table `priorities` add unique `priorities_single_default_unique`(`is_default_unique`)
```

### 2. `statuses.bucket` needs an explicit default, for the reason TM-8 documented

TM-8 measured that a `NOT NULL` MySQL `ENUM` with no default silently takes **its first enumerated value** on an insert that omits it, while `information_schema.COLUMN_DEFAULT` still reports `NULL`, so nothing warns you. The same trap applies here. It is less dangerous than TM-8's (a mis-bucketed status, not an accidental administrator), but the rule is the house rule: **every `ENUM` column in this schema gets an explicit default.** Task 4 writes `->default(StatusBucket::Open)`.

Confirmed against MySQL 8.4 that the resulting `COLUMN_TYPE` is reported without spaces — `enum('open','pending','done')` — which is the exact string the schema test asserts, matching `backend/tests/Feature/Database/UsersTableSchemaTest.php:27`.

### 3. Soft deletes and a unique `slug` collide, and it breaks seeder idempotency

MySQL enforces a unique index across soft-deleted rows; `deleted_at` means nothing to it. Measured:

```sql
INSERT INTO categories (slug, name, ...) VALUES ('billing','Billing',...);
UPDATE categories SET deleted_at = NOW() WHERE slug = 'billing';
INSERT INTO categories (slug, name, ...) VALUES ('billing','Billing',...);
-- ERROR 1062 (23000): Duplicate entry 'billing' for key 'categories.categories_slug_unique'
```

`Category::firstOrCreate(['slug' => 'billing'], …)` applies the `SoftDeletingScope`, so it **does not see** the trashed row, decides the category is missing, and tries to insert — straight into that 1062. Acceptance criterion 5 therefore fails the moment TM-18's soft delete is used in anger. Task 6 fixes it with `Category::withTrashed()->firstOrCreate(...)`.

**`restoreOrCreate()` is the wrong tool** even though it exists (`SoftDeletingScope::$extensions` lists it, and `addRestoreOrCreate()` calls `restore()` on whatever it finds). It would resurrect a category an admin deliberately deleted on the next deploy. The seeder must find the trashed row and leave it trashed.

---

## Context — Read These Files First

1. `backend/database/migrations/0001_01_01_000000_create_users_table.php` — all 52 lines, but line **3** (`use App\Enums\UserRole;`) and line **21** (`$table->enum('role', UserRole::values())->default(UserRole::Agent);`) are the pattern tasks 1 and 4 repeat for `StatusBucket`. Note there is **one** migration file creating three tables here; this story deliberately does the opposite — see task 2.
2. `backend/app/Enums/UserRole.php` — all 15 lines. `values(): array` on **11–14** is `array_column(self::cases(), 'value')`. `StatusBucket` is the same file with different cases; do not invent a different shape.
3. `backend/app/Models/User.php` — all 51 lines. The house idiom is **PHP attributes, not properties**: `#[Fillable([...])]` on **18**, `#[Hidden([...])]` on **19**, `#[UsePolicy(...)]` on **20**. Casts stay in `casts()` (**31–39**), and the local scope is the classic prefix form, `public function scopeActive(Builder $query): Builder` (**47–50**) — match that, do **not** introduce the `#[Scope]` attribute here.
4. `backend/database/seeders/AdminUserSeeder.php` — all 33 lines. **This is the idempotency contract to copy for `CategorySeeder`**: `firstOrNew` on line **21**, the bare `return` on **22–24** when the row already exists, and the non-fillable column set directly on **31**. It never overwrites.
5. `backend/database/seeders/DatabaseSeeder.php` — all 18 lines. `run()` is **15–18** and calls exactly `[AdminUserSeeder::class]` on line **17**. `use WithoutModelEvents;` on **10** stays.
6. `backend/tests/Feature/Database/UsersTableSchemaTest.php` — all 55 lines, and the closest precedent for this story's schema tests. Copy three techniques: `Schema::getColumnType('users', 'role', true)` for the full `ENUM` type string (**27**), reading `default` out of `Schema::getColumns(...)` (**32**), and `expectException(QueryException::class)` around a raw `DB::table()->insert()` to prove the *database* rejects bad data (**43–47**).
7. `backend/tests/Feature/Database/AdminUserSeederTest.php` — all 74 lines. `test_it_is_idempotent` (**32–37**) and `test_it_preserves_changed_password` (**39–47**) are the shape of this story's seeder tests. Note the `setUp()` on **17–21** overriding config so no assertion can pass by accident.
8. `backend/tests/Unit/Enums/UserRoleTest.php` — all 29 lines, four tests. `StatusBucketTest` mirrors it exactly.
9. `backend/phpunit.xml` — the comment at **27–35** names **ENUM columns** and **`ON DELETE RESTRICT`** as reasons the suite runs against real MySQL. This story adds the second `ENUM` and a generated column with a unique index, none of which SQLite models. Lines **36–41** pin the connection to `127.0.0.1:3307`.
10. `backend/config/database.php` — **line 60**, `'strict' => true` on the `mysql` connection. That is what turns an out-of-range `bucket` into a `QueryException` instead of a warning that stores `''`. `charset`/`collation` on **56–57** are why an ampersand and Arabic are both storable in a category name.
11. `docs/erd.md` — all 31 lines. The Mermaid `erDiagram` is **13–25** with `users` as its only entity; the table-notes table is **29–31** with a single `users` row owned by TM-8. Task 8 extends both.
12. Grep before you write:
    - `grep -rn "categories\|priorities\|statuses" backend/app backend/database backend/routes` — must return **nothing** before you start. If it does, someone has already begun.
    - `grep -rn "is_default" backend/` — must return nothing outside this story's own files when you are done, except tests.
13. Framework facts confirmed during planning, worth re-confirming rather than trusting this plan:
    - `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php`, `save()` — `if ($this->exists) { $saved = $this->isDirty() ? $this->performUpdate($query) : true; }`. The dirty check happens **before** `updateTimestamps()` is reached, so an `updateOrCreate` whose values are unchanged issues **no `UPDATE` and does not touch `updated_at`**. This is what makes task 6's re-run genuinely free.
    - `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:744` — `updateOrCreate()` is `firstOrCreate()` plus a `fill()->save()` when the row was not just created. `fill()` **respects `$fillable`**, so anything the seeder needs to write has to be in `#[Fillable]` or set as a property (the way `AdminUserSeeder.php:31` sets `role`).
    - `vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletingScope.php:17` — `$extensions` includes `WithTrashed`, `RestoreOrCreate` and `CreateOrRestore`; `addWithTrashed()` (**122**) simply removes the global scope, so `Category::withTrashed()->firstOrCreate(...)` sees trashed rows and leaves their `deleted_at` alone.
    - `Illuminate\Support\Str::plural()` gives `Statuses`, `Categories` and `Priorities`. All three models resolve to the intended table name with **no `#[Table]` attribute**; do not add one.

---

## Backend Tasks

**No frontend changes.** Nothing under `frontend/` is read or written — TM-19 owns the Pinia store that consumes these tables. **No route, controller, form request or API resource** — TM-17 owns those, and `backend/routes/api.php` must be byte-identical when you are done.

### 1 — The bucket enum

**Create file: `backend/app/Enums/StatusBucket.php`**

```php
<?php

namespace App\Enums;

/**
 * The coarse grouping a status belongs to. Statuses live in a table so the
 * workflow stays configurable (TM-37); the bucket is an enum because the
 * dashboard, the queue filters and the escalation guard all branch on it and
 * none of them should have to know the seven status names.
 *
 * This enum is the single definition of the bucket values: the statuses
 * migration builds its ENUM column from self::values(). A value added here
 * reaches the database through a migration, never by editing a string twice.
 */
enum StatusBucket: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Done = 'done';

    /**
     * The backing values, in declaration order — the column domain.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`Open` is declared **first** on purpose: it is the value task 4 sets as the column default, and — per **Product rule 2** — it is also what MySQL would fall back to if that default were ever dropped. The two agreeing means the mistake is survivable.

**`is_terminal` is a separate column, not a fourth bucket.** `Resolved` and `Closed` are both `done` but only some transitions treat them alike: TM-40 reopens from either, while TM-41 refuses to escalate either. Folding the two concepts together would make TM-37's transition table read `bucket !== 'done'` in one place and `status.slug === 'closed'` in another. Keep them orthogonal.

### 2 — Three migrations, not one

**Create file: `backend/database/migrations/<timestamp>_create_categories_table.php`**
**Create file: `backend/database/migrations/<timestamp>_create_priorities_table.php`**
**Create file: `backend/database/migrations/<timestamp>_create_statuses_table.php`**

Generate them with `php artisan make:migration create_categories_table` (and so on) so the timestamps are real and monotonic. Exact timestamps do not matter — the three tables have no foreign keys between them — but keep them in the order above so `php artisan migrate:status` reads in the same order as this plan.

**One table per file, unlike `0001_01_01_000000_create_users_table.php`.** That file bundles three tables because Laravel ships it that way, not because it is the pattern. TM-18 will need to add a foreign key touching `categories` alone, and TM-37 will add `status_transitions` alongside `statuses`; separate files mean each of those is a one-file diff.

### 3 — `categories`

**File: the `create_categories_table` migration**

```php
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // The stable identifier. TM-17 generates it from the name on
            // create; the seeders match on it, which is what makes re-running
            // them idempotent. Renaming a category must not change its slug.
            $table->string('slug')->unique();

            $table->text('description')->nullable();

            // #RRGGBB, uppercase. Fixed width because every value is exactly
            // seven characters; the format is validated in TM-17's form
            // request, not here (see below).
            $table->char('color', 7)->default('#6B7280');

            // Deactivating beats deleting: TM-17 hides an inactive category
            // from the new-ticket dropdown while leaving it on the tickets
            // that already carry it.
            $table->boolean('is_active')->default(true);

            // Seeded in steps of ten so TM-17 can insert between two
            // categories without renumbering the table.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
```

Four things deliberately **not** done here:

- **No `CHECK` constraint on `color`.** MySQL 8 would accept `CHECK (color REGEXP '^#[0-9A-Fa-f]{6}$')`, but a bad colour breaks a badge, not the data model, and a constraint violation surfaces to the SPA as a 500 rather than the 422 TM-17's acceptance criteria ask for. Hex validation belongs in TM-17's form request. Adding both would mean the same rule in two places with two different error shapes.
- **No index beyond the two uniques.** TM-8 refused an index on `users.role` because the column has two values; the same argument is stronger here — this table holds **six rows**. MySQL will table-scan whatever you build, and an unused index still costs every write. If TM-17's list endpoint ever shows a real cost, add `(is_active, sort_order)` there with a measurement attached.
- **No `ON DELETE` behaviour.** There is nothing to point at yet; **TM-21** adds `tickets.category_id` with `ON DELETE RESTRICT`, and **TM-18** builds the 422-with-reassignment flow on top of it.
- **No foreign key to `users`.** Categories are not owned by whoever created them, and no story asks who did.

**`softDeletes()` is on `categories` only.** TM-18's last acceptance criterion ("a category with no tickets deletes cleanly as a soft delete") is the only place in the backlog that asks for it. `priorities` and `statuses` have no delete story at all and are referenced by TM-37's transition table by id; a soft-deleted status would be a status the workflow still points at but no query returns.

### 4 — `priorities` and `statuses`

**File: the `create_priorities_table` migration**

```php
use App\Enums\StatusBucket;   // statuses migration only
```

```php
        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();

            // Ordinal rank, 1 = lowest. Unique because TM-41 raises a ticket's
            // priority by looking up level + 1, and two priorities sharing a
            // level would make that lookup ambiguous. This is also the sort key
            // TM-24 offers on the queue, which is why there is no sort_order.
            $table->unsignedTinyInteger('level')->unique();

            $table->char('color', 7)->default('#6B7280');
            $table->boolean('is_default')->default(false);

            // Exactly-one-default, enforced by MySQL rather than by the code
            // that writes the flag. The generated column is 1 for the default
            // row and NULL for every other, and unique indexes do not collide
            // on NULL — so a second default fails with SQLSTATE 23000 (1062)
            // on INSERT and on UPDATE alike, and zero defaults stays legal
            // just long enough for a seeder to move the flag.
            //
            // Never write to this column: MySQL answers ERROR 3105. It is
            // excluded from #[Fillable] and #[Hidden] for that reason.
            $table->boolean('is_default_unique')
                ->virtualAs('if(`is_default` = 1, 1, null)')
                ->unique('priorities_single_default_unique');

            $table->timestamps();
        });
```

**File: the `create_statuses_table` migration** — same shape, plus the bucket, the terminal flag and an explicit sort order:

```php
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();

            // Column domain comes from the enum, so the two cannot drift.
            // The explicit default is the house rule TM-8 established: a
            // NOT NULL MySQL ENUM with no default silently takes its FIRST
            // value while information_schema still reports NULL, so state the
            // fallback rather than inheriting it by accident.
            $table->enum('bucket', StatusBucket::values())->default(StatusBucket::Open);

            $table->char('color', 7)->default('#6B7280');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_default_unique')
                ->virtualAs('if(`is_default` = 1, 1, null)')
                ->unique('statuses_single_default_unique');

            // A ticket in a terminal status is finished. TM-41 refuses to
            // escalate one; TM-40 reopens from one. Orthogonal to bucket on
            // purpose — see app/Enums/StatusBucket.php.
            $table->boolean('is_terminal')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
```

`virtualAs`, not `storedAs`: the value is derived from a column in the same row, so there is nothing to gain from materialising it, and MySQL indexes virtual generated columns perfectly well — that is exactly what the measurement in **Product rule 1** proves.

Name both unique indexes explicitly. Left to Laravel they come out `priorities_is_default_unique_unique`, which reads like a typo in every `SHOW INDEX` and stack trace from here on.

### 5 — Three models

**Create file: `backend/app/Models/Category.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'color', 'is_active', 'sort_order'])]
class Category extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @param Builder<Category> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<Category> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
```

**Create file: `backend/app/Models/Priority.php`** and **`backend/app/Models/Status.php`** on the same pattern:

- `Priority`: `#[Fillable(['name', 'slug', 'level', 'color', 'is_default'])]`, `#[Hidden(['is_default_unique'])]`, casts `level => 'integer'` and `is_default => 'boolean'`, and `scopeOrdered` on `level`.
- `Status`: `#[Fillable(['name', 'slug', 'bucket', 'color', 'is_default', 'is_terminal', 'sort_order'])]`, `#[Hidden(['is_default_unique'])]`, casts `bucket => StatusBucket::class`, `is_default`/`is_terminal` to `boolean`, `sort_order` to `integer`, and `scopeOrdered` on `sort_order` then `name`.

Three constraints on all three models:

- **`is_default_unique` appears in `#[Hidden]` and never in `#[Fillable]`.** `SELECT *` returns it, so without `#[Hidden]` it lands in every response TM-17 and TM-19 build. Putting it in `#[Fillable]` makes any mass-assign fail with `ERROR 3105` — see **Product rule 1**.
- **No `HasFactory`.** **TM-59** owns factories for these models; adding the trait now leaves an import that resolves to nothing anyone calls. Tests in this story build rows with `Category::create([...])`.
- **No `#[Table]`, no `#[UsePolicy]`, no relationships.** Laravel pluralises all three class names correctly (verified). `CategoryPolicy` arrives with TM-17, and `tickets` does not exist until TM-21, so there is no `hasMany` to declare.

### 6 — Three seeders

**Create file: `backend/database/seeders/CategorySeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * What a fresh install starts with. Keyed on slug: the seeder matches on
     * it, so editing a name here renames nothing — it creates a second row.
     * Changing a slug is a data migration, not an edit to this array.
     *
     * @var list<array<string, mixed>>
     */
    public const CATEGORIES = [
        ['slug' => 'hardware', 'name' => 'Hardware', 'color' => '#EF4444', 'sort_order' => 10, 'description' => 'Laptops, desktops, phones, printers and peripherals.'],
        ['slug' => 'software', 'name' => 'Software', 'color' => '#3B82F6', 'sort_order' => 20, 'description' => 'Installed applications, licences and updates.'],
        ['slug' => 'network', 'name' => 'Network', 'color' => '#8B5CF6', 'sort_order' => 30, 'description' => 'Connectivity, VPN, Wi-Fi and shared drives.'],
        ['slug' => 'account-access', 'name' => 'Account & Access', 'color' => '#F59E0B', 'sort_order' => 40, 'description' => 'Passwords, permissions and account lifecycle.'],
        ['slug' => 'billing', 'name' => 'Billing', 'color' => '#10B981', 'sort_order' => 50, 'description' => 'Invoices, subscriptions and purchase requests.'],
        ['slug' => 'other', 'name' => 'Other', 'color' => '#6B7280', 'sort_order' => 60, 'description' => 'Anything that does not fit the categories above.'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            // withTrashed() is load-bearing. `slug` and `name` are unique
            // indexes and MySQL enforces them across soft-deleted rows, so
            // without it a re-seed after an admin deleted a category dies with
            // SQLSTATE 23000 (1062) instead of doing nothing.
            //
            // firstOrCreate, not updateOrCreate: TM-17 lets admins rename,
            // recolour, reorder and deactivate categories, and a seeder that
            // ran on every deploy would silently undo their work. Same
            // contract as AdminUserSeeder — an existing row is left alone,
            // and a deleted one stays deleted.
            Category::withTrashed()->firstOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
```

**Create file: `backend/database/seeders/PrioritySeeder.php`**

```php
    public const PRIORITIES = [
        ['slug' => 'low',    'name' => 'Low',    'level' => 1, 'color' => '#10B981', 'is_default' => false],
        ['slug' => 'medium', 'name' => 'Medium', 'level' => 2, 'color' => '#F59E0B', 'is_default' => true],
        ['slug' => 'high',   'name' => 'High',   'level' => 3, 'color' => '#F97316', 'is_default' => false],
        ['slug' => 'urgent', 'name' => 'Urgent', 'level' => 4, 'color' => '#EF4444', 'is_default' => false],
    ];

    public function run(): void
    {
        $default = collect(self::PRIORITIES)->firstWhere('is_default', true)['slug'];

        DB::transaction(function () use ($default): void {
            // Stand the outgoing default down before promoting the new one.
            // The guarantee is a unique index (see the migration), so two rows
            // can never both be flagged — not even mid-loop — and moving the
            // default in the array above would otherwise kill the next deploy
            // with SQLSTATE 23000 (1062). This matches no rows in the steady
            // state, so a re-run still writes nothing.
            Priority::query()
                ->where('is_default', true)
                ->where('slug', '!=', $default)
                ->update(['is_default' => false]);

            foreach (self::PRIORITIES as $priority) {
                // updateOrCreate, not firstOrCreate: no story lets an admin
                // edit a priority, so this array is the source of truth and a
                // re-seed should restore a hand-edited row. Eloquent's save()
                // short-circuits on a clean model, so an unchanged re-run
                // issues zero UPDATEs and does not touch updated_at.
                Priority::updateOrCreate(['slug' => $priority['slug']], $priority);
            }
        });
    }
```

**Create file: `backend/database/seeders/StatusSeeder.php`** — identical control flow over `Status`, with:

```php
    public const STATUSES = [
        ['slug' => 'new',         'name' => 'New',         'bucket' => StatusBucket::Open,    'color' => '#3B82F6', 'is_default' => true,  'is_terminal' => false, 'sort_order' => 10],
        ['slug' => 'open',        'name' => 'Open',        'bucket' => StatusBucket::Open,    'color' => '#6366F1', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 20],
        ['slug' => 'in-progress', 'name' => 'In Progress', 'bucket' => StatusBucket::Open,    'color' => '#8B5CF6', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 30],
        ['slug' => 'pending',     'name' => 'Pending',     'bucket' => StatusBucket::Pending, 'color' => '#F59E0B', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 40],
        ['slug' => 'resolved',    'name' => 'Resolved',    'bucket' => StatusBucket::Done,    'color' => '#10B981', 'is_default' => false, 'is_terminal' => true,  'sort_order' => 50],
        ['slug' => 'closed',      'name' => 'Closed',      'bucket' => StatusBucket::Done,    'color' => '#6B7280', 'is_default' => false, 'is_terminal' => true,  'sort_order' => 60],
        ['slug' => 'reopened',    'name' => 'Reopened',    'bucket' => StatusBucket::Open,    'color' => '#EF4444', 'is_default' => false, 'is_terminal' => false, 'sort_order' => 70],
    ];
```

The seven rows are laid out to match **TM-37**'s edges — `New → Open → In Progress → Resolved → Closed`, `Pending` reachable from the open bucket, `Reopened` reachable from the two terminal rows. Do not reorder or re-bucket them without reading TM-37's acceptance criteria first.

**Note the deliberate asymmetry between the three seeders**, and keep it: categories are admin-owned after install (`firstOrCreate`), priorities and statuses are code-owned (`updateOrCreate`). Two of this story's tests exist only to pin it.

### 7 — Wire the seeders up

**File: `backend/database/seeders/DatabaseSeeder.php`**

Replace line 17 with:

```php
        $this->call([
            AdminUserSeeder::class,
            CategorySeeder::class,
            PrioritySeeder::class,
            StatusSeeder::class,
        ]);
```

Leave `use WithoutModelEvents;` (line 10) and the rest of the file alone. Master data is **production** seed data, not demo data — `DatabaseSeeder` is the right home, and **TM-59**'s demo seeder will be a separate class that never runs in production.

### 8 — Document the schema

**File: `docs/erd.md`**

Add three entities to the Mermaid block (lines 13–25) after `users`, in the shape the existing `users` entity uses:

```
    categories {
        bigint id PK
        string name UK
        string slug UK
        text description "nullable"
        char color "#RRGGBB"
        boolean is_active "default true"
        smallint sort_order "default 0"
        timestamp deleted_at "soft delete"
    }
    priorities {
        bigint id PK
        string name UK
        string slug UK
        tinyint level UK "1 = lowest"
        char color "#RRGGBB"
        boolean is_default "exactly one row, enforced by a unique index"
    }
    statuses {
        bigint id PK
        string name UK
        string slug UK
        enum bucket "open | pending | done"
        char color "#RRGGBB"
        boolean is_default "exactly one row, enforced by a unique index"
        boolean is_terminal "resolved and closed"
        smallint sort_order
    }
```

Add three rows to the table-notes table (lines 29–31):

| `categories` | Ticket classification. Admin-managed from TM-17; soft-deleted so TM-18 can block a delete that would orphan tickets. | TM-16 |
| `priorities` | Low/Medium/High/Urgent. `level` is the ordinal TM-41 increments when escalating. | TM-16 |
| `statuses` | The workflow's states, in a table rather than an enum so TM-37 can make transitions configurable. | TM-16 |

Also add a short paragraph under the diagram recording **why `is_default` is enforced by a generated column**, so the next reader does not "simplify" it away. `docs/api-contract.md` is **not** touched — this story adds no endpoint. `backend/.env.example` is **not** touched — the seed values are code constants, not configuration; nothing here is deployment-specific.

**Do not edit `CLAUDE.md`.** Two of its statements are now stale (it says the repo is not yet a git repository and that `docs/` is empty), but TM-6 owns that file this sprint. Record the drift in the overview instead.

### 9 — Plan bookkeeping

**File: `.squad/plans/categories-priorities-statuses/00-overview.md`** — replace the empty template with the Stories table (this story's row) and dependency notes, matching the columns in `../authentication-agent/00-overview.md` (`NN | File | Title | Tracker id | Depends on | Status`).

**File: `.squad/plans/00-index.md`** — add the `categories-priorities-statuses` row with NN range `13–13`.

---

## Edge Cases & Failure Modes

- **A second default is attempted** — `INSERT` or `UPDATE` setting `is_default = 1` while another row holds it. MySQL raises `SQLSTATE 23000` / `1062` naming `priorities_single_default_unique` or `statuses_single_default_unique`; Laravel surfaces it as `QueryException`. Enforced by the unique index in the `create_priorities_table` / `create_statuses_table` migrations. **TM-17-style admin screens must clear the old flag first**, in a transaction, exactly as task 6's seeders do.
- **The default moves in code.** Editing `is_default` from `medium` to `high` in `PrioritySeeder::PRIORITIES` and re-seeding without the clearing `update()` fails with the same 1062 — reproduced during planning. The `->where('is_default', true)->where('slug', '!=', $default)` update in task 6 is the whole fix.
- **Zero defaults exists briefly, inside a transaction.** Between the clearing update and the loop, no priority is flagged. Measured as legal in MySQL. `DB::transaction()` means no other connection observes it, and a failure mid-loop rolls the whole thing back rather than leaving the table with no default.
- **A soft-deleted category is re-seeded** — `Category::firstOrCreate` would not see it and would insert into the live unique index, failing with 1062 on `categories_slug_unique` (reproduced during planning). `withTrashed()` in `CategorySeeder::run()` finds it and leaves `deleted_at` set. **A category an admin deleted must stay deleted.**
- **An admin renames or recolours a category, then a deploy re-seeds.** `firstOrCreate` matches on `slug` and returns the existing row untouched — no update, no timestamp bump. Same contract as `AdminUserSeeder.php:22-24`.
- **Someone hand-edits a status colour in the database.** `updateOrCreate` restores the seeded value on the next run. That is the intended asymmetry, not a bug: no story gives statuses an admin UI, so the array is the source of truth.
- **`is_default_unique` is written to** — by a factory `definition()`, a mass-assign, or a raw insert. MySQL raises `ERROR 3105 (HY000)`, which reaches the caller as a 500, not a validation error. Prevented by keeping the column out of every `#[Fillable]`. **The most likely future offender is TM-59's factories.**
- **`is_default_unique` leaks into an API response.** `SELECT *` returns it, so a resource built with `$this->resource->toArray()` would expose an internal enforcement detail. `#[Hidden(['is_default_unique'])]` on `Priority` and `Status` prevents it; the model test asserts it.
- **A bucket outside the enum** — `DB::table('statuses')->insert([... 'bucket' => 'archived'])` raises `QueryException` (`Data truncated for column 'bucket'`), and only because `backend/config/database.php:60` sets `'strict' => true`. On a non-strict connection MySQL would store `''` with a warning. Do not "simplify" `bucket` to a `string`.
- **A status insert that omits `bucket`** stores `open`, not an error — the MySQL implicit-first-value behaviour TM-8 measured. The explicit `->default(StatusBucket::Open)` makes the fallback intentional and visible in `information_schema.COLUMN_DEFAULT`.
- **Two priorities given the same `level`** — rejected by `priorities_level_unique`. This keeps TM-41's `level + 1` lookup single-valued.
- **`utf8mb4` content.** `Account & Access` carries an ampersand today; a category named in Arabic or containing an emoji must store and read back byte-identical. Guaranteed by the connection charset (`config/database.php:56-57`) and the container flags in `docker-compose.yml`. Pinned by a test.
- **`migrate:fresh --seed` run twice in a row** — the second run drops every table first, so idempotency is untested by that command. Idempotency is only proved by `db:seed` on an already-seeded database and by the tests; do not accept the former as evidence.
- **`RefreshDatabase` between tests.** Every feature test in this story migrates from scratch, so no test may assume rows another test seeded. Tests that need master data call `$this->seed(StatusSeeder::class)` explicitly.

---

## Test Plan

`composer test` from `backend/`, against `tm-mysql-test` on port 3307. **Baseline before this story: 90 tests, 256 assertions, 89 passing — plus the one pre-existing `PasswordThrottleTest` failure the Prerequisites describe, which belongs to TM-14.** Every feature test needs `Illuminate\Foundation\Testing\RefreshDatabase`, imported explicitly per the existing files; `tests/TestCase.php` is an empty subclass and stays that way.

1. **Create `backend/tests/Unit/Enums/StatusBucketTest.php`** — no database. Mirrors `tests/Unit/Enums/UserRoleTest.php` exactly. **4 tests:**
   - `has exactly three cases` — `StatusBucket::cases()` has 3 elements.
   - `backs its cases with the documented strings` — `'open'`, `'pending'`, `'done'`.
   - `values returns the column domain in declaration order` — `=== ['open', 'pending', 'done']`. **Order matters**: it is what the migration emits and the first element is MySQL's implicit fallback.
   - `tryFrom rejects an unknown bucket` — `StatusBucket::tryFrom('archived')` is `null`.

2. **Create `backend/tests/Feature/Database/MasterDataSchemaTest.php`** — `RefreshDatabase`. Techniques copied from `UsersTableSchemaTest.php`. **10 tests:**
   - `categories has the columns the story requires` — `Schema::hasColumns('categories', ['name','slug','description','color','is_active','sort_order','created_at','updated_at','deleted_at'])`.
   - `priorities has the columns the story requires` — `['name','slug','level','color','is_default']`.
   - `statuses has the columns the story requires` — `['name','slug','bucket','color','is_default','is_terminal','sort_order']`.
   - `types bucket as a MySQL enum over the StatusBucket values` — `Schema::getColumnType('statuses', 'bucket', fullDefinition: true)` equals **`"enum('open','pending','done')"`**. Verified during planning as the exact string MySQL 8.4 reports.
   - `defaults bucket to open` — read `default` from the matching `Schema::getColumns('statuses')` entry **and** assert the behaviour: `DB::table('statuses')->insert([...])` omitting `bucket` stores `'open'`. The behavioural half is the one that catches a dropped default.
   - `rejects a bucket outside the enum` — `expectException(QueryException::class)` around an insert with `'bucket' => 'archived'`. Pins `'strict' => true` as much as the column type.
   - `keeps category slug and name unique` — two `expectException(QueryException::class)` inserts, one per index. Split into two `assertThrows`-style blocks or two tests if PHPUnit's single-exception-per-test rule gets in the way.
   - `keeps priority level unique` — two priorities at level 1 raise `QueryException`.
   - `defaults categories.is_active to true and sort_order to 0` — insert omitting both, assert `1` and `0` come back.
   - **`a soft-deleted category still blocks its slug`** — insert, `UPDATE ... SET deleted_at = NOW()`, insert the same slug again inside `expectException(QueryException::class)`. This is the trap `CategorySeeder` works around; if this test ever starts failing, the unique index was dropped and the `withTrashed()` call became dead code.

3. **Create `backend/tests/Feature/Database/SingleDefaultConstraintTest.php`** — `RefreshDatabase`. The heart of acceptance criterion 4; every assertion is against raw `DB::table()` so it proves the **database** holds the line, not the models. **6 tests:**
   - `rejects a second default priority on insert` — one row with `is_default => 1`, then another; `QueryException`.
   - `rejects a second default priority on update` — two rows, one flagged, then `UPDATE` the other to flagged; `QueryException`. **Both paths matter** — a `UNIQUE` index that only caught inserts would let TM-17-style edits through.
   - `rejects a second default status on insert` — same for `statuses`.
   - `allows zero defaults` — clear the only flag; `SUM(is_default)` is `0` and no exception. This is what makes the seeders' clear-then-set legal.
   - `allows many non-default rows` — four unflagged priorities coexist.
   - `refuses a write to the generated column` — `DB::table('priorities')->insert([... 'is_default_unique' => 1])` raises `QueryException` (`ERROR 3105`). Documents for TM-59 why the column must never enter a factory.

4. **Create `backend/tests/Feature/Database/MasterDataSeederTest.php`** — `RefreshDatabase`. **11 tests:**
   - `seeds the documented rows` — seed all three; `Category::count()` is `6`, `Priority::count()` is `4`, `Status::count()` is `7`.
   - `marks exactly one default priority and it is Medium` — `Priority::where('is_default', true)->sole()->slug === 'medium'`. `sole()` fails loudly if the count is anything but one.
   - `marks exactly one default status and it is New` — same for `statuses`, slug `new`.
   - `marks resolved and closed as the only terminal statuses` — `Status::where('is_terminal', true)->pluck('slug')->sort()->values()->all() === ['closed', 'resolved']`. Pins the contract TM-40 and TM-41 read.
   - `buckets every status as documented` — assert the full slug ⇒ bucket map, comparing against `StatusBucket` cases, not strings.
   - `numbers priority levels 1 to 4 ascending` — `Priority::orderBy('level')->pluck('slug')->all() === ['low','medium','high','urgent']` and `pluck('level')->all() === [1,2,3,4]`.
   - `is idempotent` — seed all three twice; the three counts are unchanged and `Priority::where('is_default', true)->count()` is still `1`.
   - **`does not overwrite an edited category`** — seed, then rename/recolour/reorder `billing` and save, seed again, assert all three edits survived. This is the assertion that rules out `updateOrCreate` for categories.
   - **`does not resurrect a soft-deleted category`** — seed, `Category::where('slug','billing')->delete()`, seed again **without an exception**, then assert `Category::count()` is `5`, `Category::withTrashed()->count()` is `6`, and the row is still trashed. Without `withTrashed()` in the seeder this test fails on a `QueryException`, not on an assertion — which is exactly the bug.
   - **`restores a hand-edited status`** — seed, change `resolved`'s colour and save, seed again, assert the seeded colour is back. Pins the deliberate asymmetry against categories.
   - **`moves the default without colliding`** — seed, then hand-promote `urgent` (clearing `medium` first, since the database will not allow otherwise), seed again, and assert `medium` is default again and exactly one row is flagged. Exercises the clearing `update()` in `PrioritySeeder::run()` on the path a naive seeder dies on.

5. **Create `backend/tests/Feature/Models/MasterDataModelTest.php`** — `RefreshDatabase`. **8 tests:**
   - `casts category flags` — `true === $category->is_active` and `0 === $category->sort_order` after a reload (identity, not `assertTrue`, so a driver `1` fails).
   - `casts priority level and default flag` — `1 === $priority->level`, `false === $priority->is_default`.
   - `casts status bucket to the StatusBucket enum` — `$status->bucket === StatusBucket::Done` after a reload. The identity check is what catches a missing cast; `instanceof` alone would pass on a loose comparison.
   - `hides the enforcement column` — `array_key_exists('is_default_unique', $priority->toArray())` is `false`, same for `Status`. Pins that TM-17's and TM-19's payloads stay clean.
   - `soft-deletes a category` — delete, then `Category::count()` is `0`, `Category::withTrashed()->count()` is `1`, and `deleted_at` is not null.
   - `active scope excludes deactivated categories` — one active, one not; `Category::active()->count()` is `1`.
   - `ordered scope sorts by sort_order then name` — three categories out of order.
   - `stores a category name in Arabic and an emoji byte-identical` — create with `'الشبكة 🌐'`, reload, `assertSame`. The `utf8mb4` claim in `CLAUDE.md` and `docker-compose.yml` is otherwise untested anywhere in the suite.

6. **No test for the seeder constants in isolation.** A test asserting `CategorySeeder::CATEGORIES` has six entries restates the array rather than the behaviour; test 4's `Category::count()` covers it against the database.

7. **Regression.** The 89 currently-passing tests keep passing untouched, and the `PasswordThrottleTest` failure stays exactly as it was — **neither fixed nor made worse**. This story adds no route, no middleware and no config, so `HealthTest`, the `Auth\*` suite and `RouteAuthorizationTest` are unaffected. `AdminUserSeederTest::test_database_seeder_creates_only_admin` (**line 67**) calls `$this->seed()` and asserts `User::count() === 1` — **that assertion survives**, because the three new seeders create no users; confirm it rather than assuming it.

Expected total added: **39 tests** across five files.

---

## Migration / Rollback

Three additive migrations creating three new tables. Nothing existing is altered, so a rollback is clean:

```bash
php artisan migrate:rollback --step=3   # drops statuses, priorities, categories
```

What can go wrong on a half-applied state:

- **The `statuses` migration fails after `categories` and `priorities` applied.** Most likely cause is a typo in the `virtualAs` expression — MySQL reports `ERROR 1054` naming the unknown column, because a generated column may only reference columns **declared before it**. `is_default` must appear above `is_default_unique` in the blueprint. Roll back the two that applied, fix, re-run.
- **`down()` order.** Laravel rolls back in reverse order automatically and there are no foreign keys between the three tables, so drop order is unconstrained today. **TM-21 changes that** — once `tickets` carries foreign keys into all three, `migrate:rollback` must drop `tickets` first, and that is TM-21's `down()` to get right.
- **Re-running the seeders against a database migrated by an earlier draft of these migrations.** If the unique index on the generated column was added later than the table, an existing database may hold two default rows and `ALTER TABLE ... ADD UNIQUE` will fail with 1062 on the index creation itself. The tables are new in this story so this cannot happen on any real database, but it is the failure a future "add a unique index to an existing table" migration will hit — clear the duplicates in the same migration, before the index.
- **No data migration is needed.** No table anywhere currently references categories, priorities or statuses (`grep -rn "category_id\|priority_id\|status_id" backend/` returns nothing).

---

## Verification Steps

Run in this order. Working directory is stated for every command.

1. **Services healthy:** repo root — `docker compose up -d && docker compose ps`; all three containers `healthy`.
2. **Backend builds:** `backend/` — `php artisan config:clear` then `php artisan about` runs without a class-resolution error. `Class "App\Enums\StatusBucket" not found` here means task 1's namespace or path is wrong — the `statuses` migration imports it.
3. **Migrations apply from scratch:** `backend/` — `php artisan migrate:fresh` exits `0` and `php artisan migrate:status` lists the three new migrations as `Ran`.
4. **The shape is what the criteria asked for:** `backend/` — `php artisan db:table categories`, `db:table priorities`, `db:table statuses`. Then cross-check the two things Artisan does not show clearly, straight from MySQL:

    ```bash
    docker compose exec -T mysql mysql --protocol=TCP -h 127.0.0.1 \
      -uticket_user -psecret ticket_management \
      -e "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, EXTRA \
          FROM information_schema.COLUMNS \
          WHERE TABLE_SCHEMA='ticket_management' \
            AND TABLE_NAME IN ('categories','priorities','statuses') \
            AND COLUMN_NAME IN ('bucket','is_default','is_default_unique','color'); \
          SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE \
          FROM information_schema.STATISTICS \
          WHERE TABLE_SCHEMA='ticket_management' \
            AND TABLE_NAME IN ('categories','priorities','statuses') ORDER BY TABLE_NAME, INDEX_NAME;"
    ```

    Expect `bucket` = `enum('open','pending','done')` with `COLUMN_DEFAULT` **`open`** (if it is `NULL`, the explicit default was dropped); `is_default_unique` with `EXTRA` = `VIRTUAL GENERATED`; and `priorities_single_default_unique` / `statuses_single_default_unique` present with `NON_UNIQUE = 0`. **Missing indexes here mean acceptance criterion 4 is unenforced** — fix before going further.
5. **Seeding produces the documented rows:** `backend/` — `php artisan db:seed`, then:

    ```bash
    php artisan tinker --execute="
      echo 'categories=', App\Models\Category::count(),
           ' priorities=', App\Models\Priority::count(),
           ' statuses=', App\Models\Status::count(),
           ' defaultPriority=', App\Models\Priority::where('is_default', true)->sole()->slug,
           ' defaultStatus=', App\Models\Status::where('is_default', true)->sole()->slug,
           ' terminal=', App\Models\Status::where('is_terminal', true)->pluck('slug')->implode(','), PHP_EOL;"
    ```

    Expect `categories=6 priorities=4 statuses=7 defaultPriority=medium defaultStatus=new terminal=resolved,closed`. `sole()` throwing means more than one row is flagged, which would mean the unique index is missing.
6. **Seeders are idempotent:** `backend/` — `php artisan db:seed` a second time; it exits `0` and step 5's counts are byte-identical. Then prove nothing was rewritten:

    ```bash
    php artisan tinker --execute="echo App\Models\Status::orderBy('sort_order')->pluck('updated_at')->implode(' | '), PHP_EOL;"
    ```

    Run it before and after a third `db:seed` — the timestamps must be **unchanged**. A bumped `updated_at` means `updateOrCreate` is writing on every run and Eloquent's dirty check was defeated (usually by a cast mismatch between the seeder array and the column).
7. **An admin's category edit survives a re-seed:** `backend/` —

    ```bash
    php artisan tinker --execute="\$c = App\Models\Category::where('slug','billing')->first(); \$c->name = 'Finance'; \$c->color = '#111827'; \$c->save();"
    php artisan db:seed
    php artisan tinker --execute="\$c = App\Models\Category::where('slug','billing')->first(); echo \$c->name, ' ', \$c->color, PHP_EOL;"
    ```

    Must print `Finance #111827`. `Billing #10B981` means `updateOrCreate` crept into `CategorySeeder`.
8. **A soft-deleted category is not resurrected:** `backend/` —

    ```bash
    php artisan tinker --execute="App\Models\Category::where('slug','network')->delete();"
    php artisan db:seed
    php artisan tinker --execute="echo 'live=', App\Models\Category::count(), ' all=', App\Models\Category::withTrashed()->count(), PHP_EOL;"
    ```

    Must exit `0` and print `live=5 all=6`. **A `SQLSTATE[23000] … Duplicate entry 'network'` here is the exact bug `withTrashed()` prevents.** Restore with `php artisan migrate:fresh --seed`.
9. **A second default is impossible:** `backend/` —

    ```bash
    php artisan tinker --execute="Illuminate\Support\Facades\DB::table('priorities')->where('slug','urgent')->update(['is_default' => 1]);"
    ```

    must raise a `QueryException` naming `priorities_single_default_unique`. **Success here means acceptance criterion 4 is not enforced.**
10. **An out-of-range bucket is rejected by the database:** `backend/` —

    ```bash
    php artisan tinker --execute="Illuminate\Support\Facades\DB::table('statuses')->insert(['slug'=>'x','name'=>'X','bucket'=>'archived','created_at'=>now(),'updated_at'=>now()]);"
    ```

    must raise a `QueryException` mentioning `Data truncated for column 'bucket'`.
11. **utf8mb4 round-trips:** `backend/` —

    ```bash
    php artisan tinker --execute="App\Models\Category::create(['name'=>'الشبكة 🌐','slug'=>'ar-network','color'=>'#111827']); echo App\Models\Category::where('slug','ar-network')->value('name'), PHP_EOL;"
    ```

    Must print `الشبكة 🌐` unchanged. Then `php artisan migrate:fresh --seed` to clean up.
12. **Backend tests:** `backend/` — `composer test` reports **90 + 39 = 129 tests**, with **128 passing and only the pre-existing `PasswordThrottleTest` failure remaining**. (Re-measure the baseline first — if TM-14 has landed its fix, the target is a clean `0` exit.) A lower total means a file was not created; a **new** failure in `AdminUserSeederTest` means `DatabaseSeeder` was wired wrong.
13. **Single test class, for a fast loop:** `backend/` — `php artisan test --filter=SingleDefaultConstraintTest` exits `0`.
14. **Style:** `backend/` — `./vendor/bin/pint --test` exits `0` with no reformatting. It passed over the whole tree before this story.
15. **Regression — nothing outside the backend and docs moved:** repo root — `git status --short` lists no path under `frontend/` and no change to `docker-compose.yml`, `README.md`, `CLAUDE.md`, `backend/routes/api.php`, `backend/bootstrap/app.php`, `backend/phpunit.xml` or `backend/.env.example`. `git diff --stat backend/app/Models/User.php` is empty.
16. **Docs match the schema:** `docs/erd.md` lists `categories`, `priorities` and `statuses` in the Mermaid block and in the table notes, each owned by TM-16, and the Mermaid renders. Every column named there exists in `php artisan db:table <name>`.

---

## Done Criteria

- [ ] `backend/app/Enums/StatusBucket.php` declares a **`string`-backed** enum with exactly `Open = 'open'`, `Pending = 'pending'`, `Done = 'done'` plus `values(): array`, and is the only file where those bucket strings appear as values.
- [ ] Three migrations exist, one table each, creating `categories`, `priorities` and `statuses` with exactly the columns acceptance criteria 1–3 name, on `utf8mb4_unicode_ci`. `categories` soft-deletes; `priorities` and `statuses` do not.
- [ ] `statuses.bucket` is **`enum('open','pending','done')` with `COLUMN_DEFAULT = open`**, built from `StatusBucket::values()`; `'archived'` raises a `QueryException`.
- [ ] `priorities` and `statuses` each carry a **virtual generated column** `is_default_unique` and a **named unique index** over it. `information_schema.STATISTICS` shows both with `NON_UNIQUE = 0`, and a second default is rejected with `1062` on **both** `INSERT` and `UPDATE`.
- [ ] `priorities.level` is unique and `categories.slug`, `categories.name`, `priorities.slug`, `priorities.name`, `statuses.slug` and `statuses.name` are unique.
- [ ] `Category`, `Priority` and `Status` use `#[Fillable]`/`#[Hidden]` **PHP attributes** matching `User.php:18-19`, cast their booleans, integers and `bucket`, and expose `scopeOrdered` (plus `scopeActive` on `Category`). `is_default_unique` is in `#[Hidden]` and in **no** `#[Fillable]`. None of the three uses `HasFactory` — TM-59 owns that.
- [ ] `CategorySeeder` seeds **six** categories with `Category::withTrashed()->firstOrCreate()` keyed on `slug`; a renamed, recoloured, reordered or soft-deleted category survives a re-seed **untouched and un-resurrected**.
- [ ] `PrioritySeeder` seeds **Low/Medium/High/Urgent** at levels 1–4 with **Medium** default; `StatusSeeder` seeds **New/Open/In Progress/Pending/Resolved/Closed/Reopened** with **New** default and **Resolved + Closed** terminal. Both stand the outgoing default down inside a `DB::transaction()` before promoting the new one.
- [ ] A second `php artisan db:seed` creates no row and **bumps no `updated_at`** — verified by comparing timestamps across runs, not by counting rows alone.
- [ ] `DatabaseSeeder::run()` calls `AdminUserSeeder`, `CategorySeeder`, `PrioritySeeder`, `StatusSeeder` in that order, and `php artisan migrate:fresh --seed` on an empty database yields **1 user, 6 categories, 4 priorities, 7 statuses**, with exactly one default priority and one default status.
- [ ] `composer test` reports **39 new tests** across `StatusBucketTest`, `MasterDataSchemaTest`, `SingleDefaultConstraintTest`, `MasterDataSeederTest` and `MasterDataModelTest` (**129 total**), all passing, with the pre-existing TM-14 `PasswordThrottleTest` failure the **only** red test remaining. `./vendor/bin/pint --test` still exits `0`.
- [ ] `docs/erd.md` carries all three entities in the Mermaid `erDiagram`, three table-notes rows owned by TM-16, and a note recording **why** `is_default` is enforced by a generated column. `docs/api-contract.md`, `backend/.env.example` and `CLAUDE.md` are **not** edited.
- [ ] `backend/routes/api.php`, `backend/bootstrap/app.php`, `backend/app/Models/User.php` and everything under `frontend/` are unchanged.
- [ ] The overview records the two contracts downstream stories depend on: **`is_default_unique` must never be written** (a warning aimed at TM-59's factories), and **TM-17 must clear the old default before setting a new one** in a transaction.
- [ ] `00-overview.md` filled in — this is the feature's first story, so its Stories table and dependency notes replace the empty template — and `.squad/plans/00-index.md` has a `categories-priorities-statuses` row.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 14 (TM-17).**
