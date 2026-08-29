# Story 23 — Edit a ticket (Story: TM-27)

## Prerequisites

- **Story 22 (TM-26) — PLANNED, NOT IMPLEMENTED. It is this story's hard blocker.** Story 22 defines `TicketPolicy::update()`, the `can.update` flag this story's button reads, `TicketDetailView.vue` and the disabled `action-edit` button that task 9 wires up. Read [`22-story-ticket-detail-page-TM-26.md`](22-story-ticket-detail-page-TM-26.md) — its tasks 1, 3, 8 and 9 are the surfaces this story modifies. **Gate: do not start until `php artisan test --filter='TicketShowTest|TicketPolicyTest'` passes and `/tickets/:id` renders in the SPA.**
- **Story 19 (TM-23) — PLANNED, NOT IMPLEMENTED**, transitively required by Story 22.
- **Stories 15 (TM-18), 17 (TM-21) and 18 (TM-22) completed — implemented.** This story depends on three things they left behind and **must not re-create any of them**:
  - `ActivityRecorder::record()` (`backend/app/Services/ActivityRecorder.php:13–19`) — Story 18's wrapper that fills all five `$attributes` defaults.
  - `ticket_activities` with its `field` / `old_value` / `new_value` / `meta` columns (`backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php:15–19`) — AC2 needs exactly these.
  - `TicketActivityEvent` (`backend/app/Enums/TicketActivityEvent.php`) with `Created` and `CategoryChanged`.
- **No new composer or npm dependency, and no migration.** Measured directly against the test database: `ticket_activities.event` is **`varchar(50)`**, not a MySQL `ENUM`, so adding an enum case is a pure PHP change. (`field` is `varchar(50)`; `old_value` and `new_value` are `text`; `meta` is `json`.)
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An agent fixes an intake mistake without losing the record of what it used to say.

1. `PATCH /api/v1/tickets/{ticket}` accepts a **partial** body and validates only the keys that are present.
2. Each **changed** field writes its **own** activity row carrying the old and new values.
3. `status_id` and `assigned_to` are **rejected** here — they have dedicated endpoints.
4. `reference`, `created_by` and every timestamp are **immutable through the API**.
5. The SPA edit form pre-populates from the ticket and **warns before discarding unsaved changes**.

**Not in scope.** The four editable fields are exactly **`subject`, `description`, `category_id`, `priority_id`** — the ones the story names. Changing status is **TM-38**, assignment is **TM-31**, escalation is **TM-41**, deletion is **TM-28**. Editing the **requester** (name, email, phone, company) is not in this story and not in any current backlog item: the requester is a shared record, so editing it from one ticket would silently rewrite history on every other ticket that requester owns. **Do not add it.** Rendering the activity trail is **TM-45**; this story writes rows nobody displays yet.

---

## Product rules (from story)

| Field | Current behaviour (after Story 18) | New behaviour in this story |
|---|---|---|
| `subject` | Set at create, `max:255` | Editable. `sometimes|required`, same `max:255`. |
| `description` | Set at create, `max:16000` | Editable. `sometimes|required`, **same `max:16000`** — see the measured TEXT limit below. |
| `category_id` | Set at create; must be **active** and not soft-deleted | Editable under the **same** rule. You may not move a ticket *into* a deactivated category. |
| `priority_id` | Defaulted at create from `is_default` | Editable, any existing priority. |
| `status_id` | Accepted at create (`sometimes`) | **`prohibited`** — TM-38 owns it. |
| `assigned_to` | Already `prohibited` at create (`StoreTicketRequest.php:39`) | **Stays `prohibited`** — TM-31 owns it. |
| `reference`, `created_by` | Already `prohibited` at create (`StoreTicketRequest.php:38`) | **Stay `prohibited`.** |
| `created_at`, `updated_at`, `deleted_at`, the three lifecycle and four escalation columns | Not accepted | **`prohibited`** — AC4 says "all timestamps". |

---

## Context — Read These Files First

1. [`22-story-ticket-detail-page-TM-26.md`](22-story-ticket-detail-page-TM-26.md) — **task 1** defines `TicketPolicy::update()` as "any staff" and records that this story owns the endpoint; **task 3** defines the `can` block whose `update` flag gates the button; **task 8** defines `TicketActionToolbar.vue` with `action-edit` shipped `disabled` and titled *"Editing arrives with TM-27"* — task 9 below is the story that deletes both; **task 9** defines `TicketDetailView.vue`.
2. `backend/app/Http/Requests/Api/V1/UpdateCategoryRequest.php` — **the partial-update precedent, 23 lines.** Every editable key is `['sometimes', 'required', …]` (**line 21**) and the derived column is `['prohibited']`. **`sometimes` is what satisfies AC1** ("validates only what is sent"); `required` is what stops `{"subject": ""}` from blanking a field. Note it reads the bound model with `$this->route('category')` (**line 19**).
3. `backend/app/Http/Requests/Api/V1/Admin/UpdateUserRequest.php` — **lines 19–22**, the same `sometimes|required` idiom on four fields. Two precedents, one shape; match it.
4. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php` — the create rules this story mirrors. `description` at `max:16000` (**line 34**), `category_id` with `Rule::exists('categories','id')->where('is_active', true)->whereNull('deleted_at')` (**line 35**), `priority_id` (**36**), and the three `prohibited` keys at **38–39** with their custom message at **46**. `authorize()` uses `Gate::allows` (**12–15**).
5. `backend/app/Services/ActivityRecorder.php` — **read the whole file, 40 lines.** `record()` (**13–19**) fills `user_id`, `field`, `old_value`, `new_value`, `meta` defaults via `$attributes + [...]`, so **keys you pass win**. `recordMany()` (**21–39**) reads all five keys **unconditionally** (**32–34**) and throws `LogicException` when `DB::transactionLevel() === 0` (**26–28**). **Call `record()`, not `recordMany()`** — Story 18's overview says so explicitly.
6. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — **`reassignTickets()` at lines 86–98 is the activity-row precedent.** It writes `field => 'category_id'`, `old_value`/`new_value` as **stringified ids** (`(string) $category->getKey()`), and `meta` carrying `['reason' => 'category_deleted', 'from_name' => …, 'to_name' => …]`. Copy the shape: **stringified values in the columns, human context in `meta`.** Also read `destroy()` (**58–76**) for the `DB::transaction` + `$request->user()->getKey()` idiom.
7. `backend/app/Http/Controllers/Api/V1/TicketController.php` — `store()` (**23–43**) shows the transaction shape and the `$request->safe()->only([...])` + property-assignment split. `update()` goes after `show()`.
8. `backend/app/Models/Ticket.php` — `#[Fillable([...])]` at **line 12** includes `subject`, `description`, `category_id`, `priority_id` **and** `status_id`, `assigned_to`, `requester_id`. **Fillable is not the guard here** — the form request is. Do not narrow the attribute; `store()` and future stories rely on it.
9. `backend/app/Models/TicketActivity.php` — `public const UPDATED_AT = null` (**line 12**), so rows are insert-only; `meta` is cast to `array` (**16**).
10. `frontend/src/components/CategoryFormDialog.vue` — the form precedent: `reactive` pre-populated from a prop (**9–15**), `validationErrors(e)` into an `errors` ref and `errorMessage(e)` into a `message` ref (**24–27**), `emit('saved')`. Task 8 follows the field/error/message shape but **not** the dialog packaging — see its rationale.
11. `frontend/src/views/NewTicketView.vue` — the four ticket fields already have client-side validation at **line 12**, including `max:255` on subject and `max:16000` on description. **Reuse those exact limits and messages** so create and edit agree.

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** through Eloquent.

- **`getDirty()` is exactly the right primitive for AC2, including the numeric-string trap.** Measured on a saved ticket:
  | Action | `getDirty()` |
  |---|---|
  | `fill(['subject' => 'Original'])` — the value it already has | **`[]`** |
  | `fill(['category_id' => '1'])` — int column, **numeric string of the same value** | **`[]`**, and `isDirty('category_id')` is `false` |
  | `fill(['subject' => 'Changed', 'category_id' => 2, 'description' => <unchanged>])` | **`{"subject":"Changed","category_id":2}`** — the unchanged key is absent |
  | `fill(['priority_id' => '2'])` — a genuinely **different** numeric string | `{"priority_id":"2"}` |

  So "only changed fields write rows" comes free, and a JSON body sending `"category_id": "1"` when the row already holds `1` writes **no** activity row — Eloquent's equivalence check is numeric, not strict. **Do not hand-roll a comparison loop.**

- **`getDirty()` returns the value as filled; `getOriginal()` returns it typed.** Measured: after `fill(['priority_id' => '2'])` the dirty value is the **string** `"2"`, while `getOriginal('category_id')` returned the **int** `1`. Both must be cast with `(string)` before they reach `old_value` / `new_value`, exactly as `CategoryController.php:93` already does. Reading the new value from `$ticket->{$field}` after `fill()` gives the same string; cast either way.

- **A full 16,000-character description fits in `old_value` and `new_value`, with 1,535 bytes to spare.** Measured at the worst case, 4 bytes per character: **16,000 emoji (64,000 bytes) inserted successfully** into both columns on the same row; **16,384 emoji (65,536 bytes) was rejected** with `SQLSTATE[22001] … 1406 Data too long for column 'old_value'`. `old_value` and `new_value` are separate `text` columns, so each gets its own 65,535-byte budget. **Keep `max:16000` on `description`** — it is what makes storing the complete before-and-after safe. Raising that cap without widening these columns to `MEDIUMTEXT` would turn a long edit into a **500**.

- **`ticket_activities.event` is `varchar(50)`, not a MySQL `ENUM`.** Read from `information_schema`. Adding a `TicketActivityEvent` case needs **no migration** — which is exactly why Story 18's overview says "add cases, never rename them": the old strings are already in rows.

- **`DB::transactionLevel()` is `1` under `RefreshDatabase`, so `ActivityRecorder`'s own guard cannot fire in the test suite.** Re-confirmed this session. The consequence is the one Story 18 recorded and it applies with full force here: **an `update()` that forgot `DB::transaction()` would still pass every naive test**, because the surrounding test transaction makes the guard inert and each write succeeds individually. Atomicity has to be proven by forcing a failure between the `save()` and the activity writes and asserting **nothing** persisted — test 16.

- **`ActivityRecorder::record()` merges with `+`, so the caller's keys win and omitted keys get safe defaults.** `$attributes + ['user_id' => null, 'field' => null, 'old_value' => null, 'new_value' => null, 'meta' => []]` (**lines 15–18**). Passing four of the five is safe. Calling `recordMany()` directly with fewer than five keys raises `Undefined array key` and `json_encode(null)` writes the literal string `"null"` into `meta` — Story 18's warning, still true.

- **`onBeforeRouteLeave` is available.** Confirmed exported by the installed **vue-router 4.6.4** with signature `onBeforeRouteLeave(leaveGuard: NavigationGuard): void`. This is what makes task 8's route-based form the right choice for AC5.

---

## Decision — one `Updated` event for all four fields

`TicketActivityEvent` already has `CategoryChanged`, and its **only** call site is `CategoryController::reassignTickets()` (**line 91**), where it means *"this ticket's category moved because an admin deleted the old category"* — with `meta.reason = 'category_deleted'` baked in.

**This story adds a single case, `Updated = 'updated'`, and uses it for all four editable fields — including `category_id`.** The row already carries `field`, `old_value` and `new_value`, so the event name does not need to encode which column moved, and one uniform rule is trivially testable. The alternative — reusing `CategoryChanged` for a category edit and adding `SubjectChanged` / `DescriptionChanged` / `PriorityChanged` — was rejected: it grows the enum by three, splits one code path into two shapes, and makes TM-45 write a five-case switch to render what `field` already tells it.

**The cost, stated plainly for TM-45:** "the category changed" is recorded under **two** event values — `category_changed` for a delete-driven bulk move, `updated` with `field = 'category_id'` for an edit. TM-45's timeline must handle both. `meta.reason` distinguishes them (`'category_deleted'` vs `'edited'`). **TM-38 and TM-31 should still add their own cases** (`StatusChanged`, `Assigned`) — those are distinct user actions with their own endpoints, not field edits.

---

## Backend Tasks

### 1 — One new event case

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append. **Do not reorder or rename existing cases** — `created` and `category_changed` are already stored in the `event` column.

```php
case Updated = 'updated';
```

### 2 — `UpdateTicketRequest`

**Create file:** `backend/app/Http/Requests/Api/V1/UpdateTicketRequest.php`

Mirrors `StoreTicketRequest` but with `sometimes|required` per `UpdateCategoryRequest.php:21`.

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    /** The four fields this endpoint may change. Everything else is prohibited. */
    public const EDITABLE = ['subject', 'description', 'category_id', 'priority_id'];

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            // `sometimes` satisfies AC1 — absent keys are not validated at all.
            // `required` stops {"subject": ""} from blanking a field that must have one.
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            // 16000 is not arbitrary: `ticket_activities.old_value` is TEXT, and a
            // 16,000-character emoji description is 64,000 of its 65,535 bytes.
            // Raising this without widening that column turns a long edit into a 500.
            'description' => ['sometimes', 'required', 'string', 'max:16000'],
            // Same rule as create: you may not move a ticket INTO a dead category.
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'priority_id' => ['sometimes', 'required', 'integer', Rule::exists('priorities', 'id')],
            // AC3 — dedicated endpoints own these two.
            'status_id' => ['prohibited'],
            'assigned_to' => ['prohibited'],
            // AC4 — immutable through the API.
            'reference' => ['prohibited'], 'created_by' => ['prohibited'],
            'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'deleted_at' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'escalation_level' => ['prohibited'], 'escalated_at' => ['prohibited'],
            'escalated_by' => ['prohibited'], 'escalation_reason' => ['prohibited'],
            'first_responded_at' => ['prohibited'], 'resolved_at' => ['prohibited'], 'closed_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status_id.prohibited' => 'Change the status from the status action instead.',
            'assigned_to.prohibited' => 'Change the assignee from the assign action instead.',
            'requester_id.prohibited' => 'A ticket cannot be moved to a different requester.',
            'category_id.exists' => 'That category does not exist or is no longer active.',
        ];
    }
}
```

- **`authorize()` returns `Gate::allows('update', $this->route('ticket'))`** — the bound model, per `UpdateCategoryRequest.php:19`'s use of `$this->route(…)`. Story 22's `TicketPolicy::update()` is what answers it.
- **`requester_id` is prohibited** for the reason in *Not in scope*: the requester is a shared record.
- **The four escalation and three lifecycle columns are prohibited explicitly**, not merely omitted. `Ticket`'s `#[Fillable]` does not list them, so `fill()` would ignore them silently — but AC4 asks for immutability *through the API*, and a silent drop tells the caller their change succeeded when it did not. A `422` is the honest answer.
- **Do not narrow `Ticket::$fillable`.** `store()` and TM-31/TM-38 need `status_id` and `assigned_to` fillable; the guard belongs in the request.

### 3 — `TicketController::update()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add after `show()`. Add `use App\Http\Requests\Api\V1\UpdateTicketRequest;` to the imports.

```php
public function update(UpdateTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
{
    $actorId = $request->user()->getKey();
    DB::transaction(function () use ($request, $ticket, $recorder, $actorId): void {
        $ticket->fill($request->safe()->only(UpdateTicketRequest::EDITABLE));
        // `getDirty()` is the whole of criterion 2. Measured: filling a field with
        // the value it already holds leaves it absent — including an int column
        // filled with a numeric string of the same value — so an unchanged field
        // writes no row without any comparison of our own.
        $changes = $ticket->getDirty();
        if ($changes === []) {
            return;
        }
        $originals = array_map(fn (string $field) => $ticket->getOriginal($field), array_combine(array_keys($changes), array_keys($changes)));
        $ticket->save();
        foreach ($changes as $field => $newValue) {
            $recorder->record($ticket->getKey(), TicketActivityEvent::Updated, [
                'user_id' => $actorId,
                'field' => $field,
                // getOriginal() is typed, getDirty() is as-filled; cast both,
                // matching CategoryController::reassignTickets().
                'old_value' => $originals[$field] === null ? null : (string) $originals[$field],
                'new_value' => $newValue === null ? null : (string) $newValue,
                'meta' => ['reason' => 'edited'],
            ]);
        }
    });

    return TicketResource::make($ticket->fresh()->load([
        'requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy',
    ]))->response();
}
```

Five things that are load-bearing:

- **Capture `getOriginal()` before `save()`.** After `save()`, Eloquent syncs the original state and `getOriginal($field)` returns the **new** value — every activity row would then read `old_value === new_value`. This is the single easiest way to get AC2 subtly wrong, and test 6 is written to catch it.
- **`DB::transaction` is mandatory and the test suite cannot tell you if you drop it.** Measured: `transactionLevel` is `1` under `RefreshDatabase`, so `ActivityRecorder`'s guard is inert and each write would succeed on its own. Test 16 forces a failure between the save and the writes.
- **Return early when `$changes === []`.** A no-op `PATCH` must not call `save()` (which would bump `updated_at` for nothing) and must not write rows. It still returns `200` with the unchanged ticket.
- **`record()`, not `recordMany()`** — Story 18's wrapper fills the five defaults; `recordMany()` requires all five keys and writes the string `"null"` into `meta` if you omit one.
- **`->fresh()` before `->load()`** so the response carries the saved state and a `category` relation matching the new `category_id` rather than the stale one. The eager-load list matches Story 22's `show()` exactly, so the response shape is identical — including `description` and, because this is not `tickets.show`, **no `can` block**. Note that in a comment; the SPA re-reads the detail after saving.

### 4 — Route

**File: `backend/routes/api.php`**

After the `tickets.show` line Story 22 adds:

```php
Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
```

Inside the `['auth:sanctum', 'active']` group (opens **line 32**), **outside** the `admin` group (**line 45** before Stories 19/22 add their lines).

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add `'tickets.update' => 'staff-write'` to `ACCESS`. `staff-write` (Story 19's level) asserts *an agent is not `403`* — the right level here, because an agent **is** allowed and an empty `PATCH` body is a **`200` no-op**, not a `422`. `routesFor()` already resolves `{ticket}` after Story 22's task 4, so no further change to that helper.

### 5 — Document it

**File: `docs/api-contract.md`**

Add after the `GET /api/v1/tickets/{ticket}` row:

```markdown
| `PATCH` | `/api/v1/tickets/{ticket}` | Correct subject, description, category or priority. Partial body. | bearer (TicketPolicy) | TM-27 |
```

And a subsection:

```markdown
### `PATCH /api/v1/tickets/{ticket}`

Partial update. Only the keys present in the body are validated, and only the
fields whose value actually changes are written — an unchanged field is a no-op,
and a body with no changes returns `200` without touching `updated_at`.

Editable: `subject` (max 255), `description` (max 16000), `category_id` (must be
an active, non-deleted category), `priority_id`.

Rejected with `422`: `status_id` and `assigned_to` (dedicated actions own them),
`requester_id`, `reference`, `created_by`, and every timestamp and escalation
column. Sending `""` for an editable field is also `422` — use a value or omit
the key.

Each changed field writes one `ticket_activities` row with `event = 'updated'`,
`field`, the stringified `old_value` and `new_value`, and `meta.reason = 'edited'`.
A category change from an admin deleting its old category is recorded instead as
`event = 'category_changed'` with `meta.reason = 'category_deleted'`, so a
timeline must handle both.

`description` is capped at 16000 characters because `ticket_activities.old_value`
is `TEXT`: at 4 bytes per character that is 64,000 of its 65,535 bytes. Raising
the cap requires widening that column first.
```

---

## Frontend Tasks

### 6 — The request

**File: `frontend/src/api/tickets.ts`**

```ts
export interface UpdateTicketPayload {
  subject?: string
  description?: string
  category_id?: number
  priority_id?: number
}

export async function updateTicket(
  id: number,
  payload: UpdateTicketPayload,
): Promise<TicketDetail> {
  const { data } = await client.patch<{ data: TicketDetail }>(
    `/tickets/${id}`,
    payload,
  )
  return data.data
}
```

Every key optional, mirroring the partial body. **`TicketDetail`** is Story 22's type; the response omits `can`, so treat the returned object as data to re-fetch from rather than as a complete replacement — see task 7.

### 7 — Store action

**File: `frontend/src/stores/tickets.ts`**

Add beside Story 22's detail state:

```ts
const saving = ref(false)

async function saveTicket(id: number, payload: UpdateTicketPayload): Promise<void> {
  saving.value = true
  try {
    await updateTicket(id, payload)
    // The PATCH response has no `can` block (it is not the show route), so
    // re-read the detail rather than assigning the response to `current`.
    await loadTicket(id)
  } finally {
    saving.value = false
  }
}
```

- **Do not catch here.** The view needs the error to map `validationErrors` onto fields, exactly as `CategoryFormDialog.vue:24–27` does. `loadTicket` owns the detail error state; `saveTicket` owns only `saving`.
- **Re-read via `loadTicket`** — assigning the PATCH response directly would leave `current.can` undefined and blank the toolbar.

### 8 — The edit form

**Create file:** `frontend/src/views/TicketEditView.vue`

**A routed view at `/tickets/:id/edit`, not a dialog.** The two existing edit forms are dialogs (`CategoryFormDialog.vue`, `UserFormDialog.vue`), and this diverges for a concrete reason: **AC5's warning must catch every way a user discards changes**, and `onBeforeRouteLeave` — confirmed exported by vue-router 4.6.4 — only exists for a routed component. A dialog can guard its own close button but not the browser back button, which on a detail page with a modal open navigates away and drops the edit silently. A 16,000-character description also needs more room than a dialog gives.

```ts
const route = useRoute()
const router = useRouter()
const store = useTicketsStore()
const id = Number(route.params.id)

const form = reactive({ subject: '', description: '', category_id: 0, priority_id: 0 })
const initial = ref('')            // JSON snapshot taken once the ticket loads
const errors = reactive<Record<string, string>>({})
const message = ref('')

const dirty = computed(() => JSON.stringify(form) !== initial.value)

onMounted(async () => {
  await store.loadTicket(id)
  const ticket = store.current
  if (!ticket) return            // not-found / error states render instead
  Object.assign(form, {
    subject: ticket.subject,
    description: ticket.description,
    category_id: ticket.category.id,
    priority_id: ticket.priority.id,
  })
  initial.value = JSON.stringify(form)
})

// AC5. Fires for in-app navigation: the toolbar, a nav link, the back button.
onBeforeRouteLeave(() => {
  if (!dirty.value || saved.value) return true
  return window.confirm('Discard your unsaved changes to this ticket?')
})

// `onBeforeRouteLeave` does not fire on reload or tab close; this does.
const warnOnUnload = (event: BeforeUnloadEvent) => {
  if (dirty.value && !saved.value) event.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', warnOnUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnOnUnload))
```

`submit()` sends **only changed fields**, so the backend's `getDirty()` never even sees a no-op:

```ts
async function submit(): Promise<void> {
  if (store.saving || !validate()) return
  const payload: UpdateTicketPayload = {}
  if (form.subject !== base.subject) payload.subject = form.subject
  if (form.description !== base.description) payload.description = form.description
  if (form.category_id !== base.category_id) payload.category_id = form.category_id
  if (form.priority_id !== base.priority_id) payload.priority_id = form.priority_id
  try {
    await store.saveTicket(id, payload)
    saved.value = true            // suppresses the leave guard for our own redirect
    await router.replace({ name: 'ticket-detail', params: { id } })
  } catch (error) {
    const fields = validationErrors(error)
    Object.entries(fields).forEach(([key, value]) => { errors[key] = value[0] })
    if (!Object.keys(fields).length) message.value = errorMessage(error)
  }
}
```

- **The `saved` flag is mandatory.** Without it, a successful save leaves `dirty` true, the leave guard fires on your own `router.replace`, and the user is asked to discard the changes they just saved. This is the most likely visible bug in this story; frontend test 22 covers it.
- **`router.replace`, not `push`** — the edit form should not sit in history behind the detail page it just updated.
- **Client validation reuses `NewTicketView.vue:12`'s limits exactly**: subject required and `max:255`, description required and `max:16000`, category and priority required. Same messages, so create and edit agree.
- **The category select lists `masterData.activeCategories`**, matching `NewTicketView.vue:26` and the backend's `where('is_active', true)` rule. **This differs from Story 20's filter bar**, which lists *all* categories on purpose — you may filter by a dead category but not move a ticket into one. If the ticket's current category has since been deactivated, it will not be in the list: render it as a **disabled selected option** so the form does not silently reset a field the user never touched.
- Test ids: `ticket-edit-form`, `ticket-edit-subject`, `ticket-edit-description`, `ticket-edit-category`, `ticket-edit-priority`, `ticket-edit-submit`, `ticket-edit-cancel`, `ticket-edit-error-<field>`, `ticket-edit-message`, `ticket-edit-dirty` (a marker rendered while `dirty`).
- Reuse Story 22's `detailLoading` / `detailNotFound` / `detailError` states so `/tickets/999999/edit` renders the not-found panel rather than an empty form.

### 9 — Route, and switch on Story 22's button

**File: `frontend/src/router/index.ts`**

```ts
{ path: '/tickets/:id/edit', name: 'ticket-edit', component: TicketEditView },
```

Two segments, so it cannot collide with `/tickets/:id` or `/tickets/new`. No `meta.role` — `TicketPolicy::update()` is any-staff, and the API is the real gate.

**File: `frontend/src/components/TicketActionToolbar.vue`** (Story 22's)

`action-edit` becomes live: **remove `disabled`, remove the `title="Editing arrives with TM-27"`**, and navigate to `{ name: 'ticket-edit', params: { id: ticket.id } }`. Leave `action-assign`, `action-status` and `action-escalate` exactly as they are — **they stay disabled** for TM-31, TM-38 and TM-41. Story 22's toolbar test asserting "every button is disabled" must be updated to exempt `action-edit`.

### 10 — Formatting

```bash
cd frontend && npx prettier --write src/api/tickets.ts src/stores/tickets.ts src/views/TicketEditView.vue src/components/TicketActionToolbar.vue src/router/index.ts src/views/TicketEditView.spec.ts src/stores/tickets.spec.ts src/components/TicketActionToolbar.spec.ts
```

Format only what you touch, as in Stories 19–22.

---

## Edge Cases & Failure Modes

- **An empty body (`PATCH` with `{}`)** → `200`, no `save()`, no activity row, `updated_at` untouched. The `$changes === []` early return is what makes this true; without it `save()` on a clean model is a no-op anyway but the guard also skips the `getOriginal` work.
- **A field sent with its current value** → no activity row. Measured: `getDirty()` is `[]`, **including an int column receiving a numeric string of the same value** (`"1"` against `1`).
- **A field sent as `""`** → `422` from `required`. `sometimes|required` is precisely "if you send it, mean it".
- **A field sent as `null`** → `422` from `required`. None of the four is nullable in the schema.
- **`status_id` or `assigned_to` in the body** → `422` with the message naming the dedicated action, not a silent drop. Same for `requester_id`, `reference`, `created_by` and every timestamp.
- **A prohibited key sent alongside a valid one** → the whole request is `422` and **nothing** is written. Validation runs before the controller; there is no partial application.
- **`category_id` pointing at a deactivated or soft-deleted category** → `422`. You may not move a ticket into a dead category, matching create.
- **A ticket whose current category was deactivated after filing** → the edit form must still show it, as a disabled selected option. Otherwise saving a subject-only change silently rewrites `category_id` to whatever the select defaulted to.
- **A 16,000-character description change** stores both old and new in full: 64,000 bytes each, inside `TEXT`'s 65,535. Measured, with 16,384 emoji rejected at `1406 Data too long`. **Raising `max:16000` without widening `old_value`/`new_value` to `MEDIUMTEXT` converts a long edit into a 500.**
- **`getOriginal()` after `save()` returns the new value.** Capturing originals before the save is the only thing standing between AC2 and a table full of rows where `old_value === new_value`.
- **A missing or soft-deleted ticket** → `404` from route-model binding, before validation or policy, exactly as Story 22 measured for `show()`.
- **An update that partially fails** must leave nothing behind. `DB::transaction` provides it, and no test can tell you it is missing — `transactionLevel` is `1` under `RefreshDatabase`, so the recorder's own guard is inert and each write succeeds independently.
- **Concurrent edits** are last-write-wins. There is no optimistic-locking column in the schema and no story asks for one; two agents editing the same subject means the second overwrites the first, and **both** get an activity row, so the trail shows what happened. Do not add a version column here.
- **Discarding via the browser back button** is caught by `onBeforeRouteLeave`; **reload and tab close** are caught by `beforeunload`. Neither fires after a successful save because of the `saved` flag.
- **A save that 422s** must leave the form dirty and populated — never redirect. The field errors come from `validationErrors(error)`, keyed by the same names the API returns.
- **Navigating from `/tickets/5/edit` to `/tickets/9/edit`** reuses the component. `id` is read once at setup, so this would edit the wrong ticket — there is no in-app link that does it, but the leave guard fires first and the component re-mounts on a genuine route change. Assert the guard's behaviour rather than relying on it.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketUpdateTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Uses Story 19's `TicketFactory` and `RequesterFactory`.

1. `test_unauthenticated_request_is_rejected` — `401`.
2. `test_agent_can_update_subject` — `200`, and `data.subject` is the new value.
3. `test_updates_all_four_editable_fields_at_once` — subject, description, category and priority in one body; all four persisted.
4. `test_validates_only_what_is_sent` — **AC1.** A body containing only `{"subject": "x"}` succeeds even though `description`, `category_id` and `priority_id` are absent; `description` is unchanged in the database.
5. `test_writes_one_activity_row_per_changed_field` — **AC2.** Change three fields; assert exactly **3** `ticket_activities` rows with `event = 'updated'`, one per `field`, and no fourth.
6. `test_activity_row_captures_old_and_new_values` — **AC2, and the `getOriginal()`-after-`save()` trap.** Assert `old_value` is the pre-edit string and `new_value` the post-edit string, and explicitly `assertNotSame($row->old_value, $row->new_value)`.
7. `test_activity_meta_records_the_edit_reason` — `meta.reason === 'edited'`, distinguishing it from `category_deleted`.
8. `test_category_change_uses_the_updated_event_not_category_changed` — pins the Decision above: `event = 'updated'`, `field = 'category_id'`, and **no** `category_changed` row.
9. `test_unchanged_field_writes_no_activity_row` — send `subject` with its current value; **zero** rows.
10. `test_numeric_string_id_matching_current_value_writes_no_row` — send `category_id` as the **string** of its current id; zero rows. Pins the measured numeric-equivalence behaviour.
11. `test_empty_body_is_a_no_op` — `200`, zero activity rows, and `updated_at` **unchanged**.
12. `test_rejects_status_id_and_assigned_to` — **AC3.** Each `422`, and assert the message names the dedicated action.
13. `test_rejects_immutable_fields` — **AC4.** Parameterised over `reference`, `created_by`, `created_at`, `updated_at`, `deleted_at`, `requester_id`, `escalation_level`, `escalated_at`, `escalated_by`, `escalation_reason`, `first_responded_at`, `resolved_at`, `closed_at` → all `422`.
14. `test_prohibited_key_alongside_a_valid_one_writes_nothing` — `{"subject":"new","status_id":2}` → `422`, and `subject` is unchanged in the database.
15. `test_rejects_blank_and_null_editable_fields` — `""` and `null` for each of the four → `422`.
16. `test_update_is_atomic` — **the test the suite cannot imply.** Bind a throwing `ActivityRecorder` (or throw from a `saving` event) and assert the ticket's `subject` is **unchanged** and zero activity rows exist. Then Verification step 4 has the reader delete `DB::transaction` to watch this go red.
17. `test_rejects_a_deactivated_or_soft_deleted_category` — both `422` on `category_id`.
18. `test_rejects_an_over_long_description` — 16,001 characters → `422`; **and** 16,000 characters succeeds with both activity values stored in full (assert `mb_strlen($row->new_value) === 16000`). This is the guard on the TEXT-capacity finding.
19. `test_missing_and_soft_deleted_tickets_return_404`.
20. `test_response_matches_the_show_shape` — the updated ticket comes back with `requester`, `category`, `priority`, `status`, `creator` and `description`, and the `category` reflects the **new** id (proving the `fresh()` before `load()`).

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

21. `test_every_api_route_is_classified` / `test_every_classified_route_exists` — green with `tickets.update` added.
22. `test_agent_reaches_staff_write_routes` — the existing Story 19 loop now covers `tickets.update`; confirm an agent is **not** `403`.

### Frontend

23. **`frontend/src/stores/tickets.spec.ts`** (extend) — `saveTicket` sends the payload and then re-reads via `loadTicket` (assert `getTicket` called after `updateTicket`); `saving` is true during and false after; a rejection **re-throws** and still clears `saving`. **3 tests.**
24. **`frontend/src/views/TicketEditView.spec.ts`** (new) — pre-populates all four fields from the loaded ticket; `submit` sends **only** changed fields (`expect.not.objectContaining` on the untouched ones); a `422` maps field errors and does **not** navigate; a success navigates to `ticket-detail`; **the leave guard does not fire after a successful save** (the `saved` flag); the guard returns `false`/prompts while dirty and `true` when clean; a deactivated current category still renders as a selected option; `/tickets/999999/edit` renders the not-found panel. **8 tests.**
25. **`frontend/src/components/TicketActionToolbar.spec.ts`** (modify) — Story 22's "every button is disabled" assertion is **replaced**: `action-edit` is now enabled and links to `ticket-edit`, while `action-assign`, `action-status` and `action-escalate` remain disabled. **2 tests.**
26. **`frontend/src/router/index.ts`** coverage in `TicketDetailView.spec.ts` (extend) — `router.resolve('/tickets/5/edit').name` is `'ticket-edit'`, and `/tickets/new` still resolves to `'new-ticket'`. **1 test.**

**Stories 19's, 20's, 21's and 22's suites must otherwise pass unchanged** — the only deliberate edit is Story 22's toolbar assertion in test 25.

---

## Verification Steps

1. **Story 22 is done.** `php artisan test --filter='TicketShowTest|TicketPolicyTest'` passes and `php artisan route:list --name=tickets` shows `tickets.show`. **If not, stop.**
2. **Backend formats and passes:** from `backend/`, `./vendor/bin/pint --test`, then `composer test`. Expect **+22 tests** over your measured baseline, with only TM-14's and TM-21's known failures red.
3. **The new class alone:** `php artisan test --filter=TicketUpdateTest` — 20 passing.
4. **Prove the transaction is load-bearing.** Delete the `DB::transaction(...)` wrapper in `update()` (call the closure body directly) and run `--filter=test_update_is_atomic`. It must **fail**, showing the ticket saved while the activity rows were not. Restore it. Measured: `transactionLevel` is `1` under `RefreshDatabase`, so **no other test in the suite will notice the wrapper is gone** — this step is the only thing that does.
5. **Prove the originals are captured before the save.** Move the `$originals = …` line to **after** `$ticket->save()` and run `--filter=test_activity_row_captures_old_and_new_values`. It must **fail** with `old_value` equal to `new_value`. Restore the order.
6. **Prove the no-op path.** `--filter='test_unchanged_field_writes_no_activity_row|test_numeric_string_id_matching_current_value_writes_no_row|test_empty_body_is_a_no_op'` — all three green.
7. **Backend by hand**, with an agent token on ticket `<id>`:
   - `curl -X PATCH … -d '{"subject":"Corrected subject"}'` → `200`; then `SELECT event, field, old_value, new_value, meta FROM ticket_activities WHERE ticket_id = <id>` shows **one** `updated` row for `subject` with both values and `meta.reason = 'edited'`.
   - Repeat the identical request → `200`, and **no second row**.
   - `-d '{}'` → `200`, no row, `updated_at` unchanged.
   - `-d '{"status_id":2}'` → `422` naming the status action. `-d '{"assigned_to":1}'` → `422`. `-d '{"reference":"X"}'` → `422`.
   - `-d '{"subject":""}'` → `422`.
   - `-d '{"description":"<16001 chars>"}'` → `422`; at 16,000 → `200`, and the stored `new_value` is 16,000 characters.
   - `PATCH …/tickets/999999` → `404`.
8. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npx prettier --check` on task 10's list, `npm test` — **+14 tests across 1 new file**.
9. **Frontend by hand:** `npm run dev`, sign in, open a ticket.
   - The **Edit** button is now enabled; the other three are still greyed out.
   - The form arrives pre-filled with all four current values. *(AC5.)*
   - Change the subject, then click a nav link → **you are warned**. Cancel, then press the browser **back button** → warned again.
   - Press **⌘R / F5** while dirty → the browser's own leave prompt appears.
   - Revert your change by hand so the form matches the original → navigating away is **no longer** warned.
   - Save → you land on the detail page, the new subject is shown, and **no discard prompt appeared**. *(The `saved` flag — the most likely bug.)*
   - Edit again, submit a 300-character subject → the field error renders and you stay on the form.
   - Visit `/tickets/999999/edit` → the not-found panel, not an empty form.
10. **Regression:** `/tickets` lists and pages; `/tickets/new` files a ticket and redirects to its detail page; the detail page's other three actions remain disabled.

---

## Done Criteria

- [ ] `PATCH /api/v1/tickets/{ticket}` accepts a partial body and validates only the keys present.
- [ ] `subject`, `description`, `category_id` and `priority_id` are editable; `category_id` must be an active, non-deleted category.
- [ ] Each **changed** field writes one `ticket_activities` row with `event = 'updated'`, `field`, stringified `old_value` and `new_value`, and `meta.reason = 'edited'`.
- [ ] `old_value` is the pre-edit value — proven by a test that fails if `getOriginal()` is read after `save()`.
- [ ] An unchanged field writes **no** row, including an int field sent as a numeric string of its current value.
- [ ] An empty body is `200` with no row and no change to `updated_at`.
- [ ] `status_id` and `assigned_to` are `422` with messages naming their dedicated actions.
- [ ] `reference`, `created_by`, `requester_id` and every timestamp and escalation column are `422`.
- [ ] A prohibited key alongside a valid one writes **nothing**.
- [ ] `""` and `null` are `422` for all four editable fields.
- [ ] A 16,000-character description succeeds and both activity values are stored in full; 16,001 is `422`.
- [ ] The update is atomic — proven by a test that fails when `DB::transaction` is removed.
- [ ] `TicketActivityEvent::Updated` is **appended**; `Created` and `CategoryChanged` are untouched; no migration was added.
- [ ] `tickets.update` is classified `staff-write` and `RouteAuthorizationTest` is fully green.
- [ ] The SPA edit form pre-populates all four fields and sends only what changed.
- [ ] Navigating away, going back, or reloading while dirty warns; doing so after a successful save does **not**.
- [ ] A `422` keeps the user on the form with per-field errors.
- [ ] A ticket whose category was deactivated still shows that category as its selected option.
- [ ] Story 22's `action-edit` is enabled and routes to the form; `action-assign`, `action-status` and `action-escalate` remain disabled.
- [ ] `docs/api-contract.md` documents the partial-update semantics, the prohibited fields, the two category-change event values, and why `description` is capped at 16000.
- [ ] Stories 19–22's suites pass unchanged apart from Story 22's toolbar assertion.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+22 backend and +14 frontend tests** over the measured baseline.
- [ ] No new dependency, no migration, and no status, assignment, escalation or delete endpoint.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 24 (TM-28, soft-delete a ticket).**
