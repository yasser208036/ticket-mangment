# API Contract

Every endpoint of the ticket-management API. Paths are absolute; the `/api/v1`
prefix is set in `backend/bootstrap/app.php`, so route definitions in
`backend/routes/api.php` are written relative to it.

## Conventions

- Every route is versioned under `/api/v1` from the first endpoint onward.
- Requests and responses are JSON; errors follow Laravel's default validation
  envelope unless a story states otherwise.
- Authentication uses Laravel Sanctum (`laravel/sanctum ^4.0`, see
  `backend/composer.json`).
- Authentication endpoints name their payload (`user`); resource endpoints and
  collections use Laravel's default `data` wrapper.
- List endpoints whose result set grows with usage return paginated `data`, `links`, and `meta` envelopes. Bounded master-data lists such as categories return `data` only.

## Authorization

`auth:sanctum` validates bearer token; `active` rejects inactive accounts and
revokes tokens; `admin` rejects agents with `403`. `UserPolicy` then authorizes
record actions. Unknown user ids return `404` only to authorized callers;
agents receive `403` for every admin endpoint.

`UserPolicy` defines `viewAny`, `view`, `create`, `update`, and `delete`.
Admins may list, read, create, and edit users; users may read themselves;
deletion is denied for everyone. `CategoryPolicy` and `TicketPolicy` authorize
their own records. Every active staff member may list and create tickets.

## Status workflow

The ticket lifecycle lives in the `status_transitions` table, not in code. A
status change is legal only when a row exists for the `(from, to)` pair, and a
row may carry `required_role = admin` to restrict the move. The default graph is
seeded by `StatusTransitionSeeder` and can be edited at runtime without a deploy.

| From | To | Restricted to |
|---|---|---|
| New | Open, Pending | — |
| Open | In Progress, Pending | — |
| In Progress | Resolved, Pending | — |
| Pending | Open, In Progress | — |
| Resolved | Closed | **admin** |
| Resolved | Reopened | — |
| Closed | Reopened | — |
| Reopened | In Progress, Resolved, Pending | — |

Every rejection is a `422` under `errors.status_id`, never a `403` — whether a
transition is available is a property of the request, and `TicketPolicy::changeStatus`
has already answered whether the caller may operate the control at all. Three
messages are possible: `A ticket cannot move from X to Y.` (no such edge),
`Only an administrator can move a ticket from X to Y.` (the edge is admin-only),
and `This ticket is already X.` (the target is the current status).

Creating a ticket is an entry point, not a transition: `POST /api/v1/tickets`
still accepts any existing `status_id` and defaults to New.

`POST /api/v1/tickets/{ticket}/status` is the only way a status changes.
`GET /api/v1/tickets/{ticket}` carries `data.allowed_transitions`: the statuses
this caller may move this ticket to right now, in `sort_order`. It is computed
per request and is absent from every other response, including the status
change's own.

Moving into Reopened requires a `reason` of 10–5000 characters, rejected on any
other move. The move writes **one** activity row whose event is `reopened`
rather than `status_changed`, carrying the reason under `meta.reason`. Both rows
fill `field = 'status_id'` with the two status ids, so **status history is
queried by `field`, never by `event`**: every status change is exactly one row
with `field = 'status_id'`, and only its event name differs.

Reopening clears `resolved_at` and `closed_at` through the same non-terminal
rule as any other move out of a terminal status; there is no reopen-specific
timestamp code. A reopened ticket keeps its assignee, its priority and its
escalation level.

## Endpoints

| Method | Path | Purpose | Auth | Owning story |
|--------|------|---------|------|--------------|
| `GET` | `/api/v1/health` | Liveness + readiness. Probes every dependency rather than assuming it. | none | TM-3 |
| `POST` | `/api/v1/auth/login` | Exchange email and password for a Sanctum bearer token. | none (throttled) | TM-9 |
| `POST` | `/api/v1/auth/logout` | Revoke the token that made the request. | bearer | TM-10 |
| `GET` | `/api/v1/auth/me` | Return the authenticated user. | bearer | TM-10 |
| `PATCH` | `/api/v1/auth/password` | Change your own password. | bearer (throttled) | TM-14 |
| `GET` | `/api/v1/categories` | Every category, active and inactive, ordered. | bearer (CategoryPolicy) | TM-17 |
| `POST` | `/api/v1/categories` | Create category; slug derived from name. | admin bearer (CategoryPolicy) | TM-17 |
| `GET` | `/api/v1/categories/{category}` | Return one category. | bearer (CategoryPolicy) | TM-17 |
| `PATCH` | `/api/v1/categories/{category}` | Rename, recolour, reorder, activate or deactivate. | admin bearer (CategoryPolicy) | TM-17 |
| `DELETE` | `/api/v1/categories/{category}` | Soft delete. Unguarded until TM-18. | admin bearer (CategoryPolicy) | TM-17 |
| `GET` | `/api/v1/priorities` | Every priority, ordered by level. | bearer | TM-19 |
| `GET` | `/api/v1/statuses` | Every status, ordered by sort order. | bearer | TM-19 |
| `GET` | `/api/v1/tickets` | Paginated, filterable ticket queue. Omits `description`. | bearer (TicketPolicy) | TM-23, TM-24 |
| `GET` | `/api/v1/tickets/stats` | Dashboard counts by status and priority; scoped to the caller unless admin. | bearer | TM-29 |
| `POST` | `/api/v1/tickets` | File a ticket; requester matched or created by email. | bearer (TicketPolicy) | TM-22 |
| `GET` | `/api/v1/tickets/{ticket}` | Return one ticket and allowed actions. | bearer (TicketPolicy) | TM-26 |
| `GET` | `/api/v1/tickets/{ticket}/activities` | Paginated audit trail for one ticket, newest first. | bearer (TicketPolicy `view`) | TM-46 |
| `POST` | `/api/v1/tickets/{ticket}/notes` | Add an internal note as a `note_added` activity. | bearer (TicketPolicy `addNote`) | TM-47 |
| `POST` | `/api/v1/tickets/{ticket}/assign` | Assign or unassign a ticket to an active agent. | admin bearer (TicketPolicy) | TM-31 |
| `POST` | `/api/v1/tickets/{ticket}/claim` | Self-claim an unassigned ticket. | bearer, agent only (TicketPolicy) | TM-32 |
| `POST` | `/api/v1/tickets/{ticket}/escalate` | Raise a ticket's escalation level, priority and visibility. | bearer (TicketPolicy) | TM-41 |
| `POST` | `/api/v1/tickets/{ticket}/status` | Move a ticket to a legal next status. | bearer (TicketPolicy) | TM-38 |
| `PATCH` | `/api/v1/tickets/{ticket}` | Edit subject, description, category, or priority. | bearer (TicketPolicy) | TM-27 |
| `DELETE` | `/api/v1/tickets/{ticket}` | Soft delete a ticket. | admin bearer (TicketPolicy) | TM-28 |
| `GET` | `/api/v1/admin/users` | Paginated, searchable staff list. | admin bearer (UserPolicy) | TM-12 |
| `POST` | `/api/v1/admin/users` | Create a staff account. | admin bearer (UserPolicy) | TM-12 |
| `GET` | `/api/v1/admin/users/{user}` | Return one staff account. | admin bearer (UserPolicy) | TM-12 |
| `PATCH` | `/api/v1/admin/users/{user}` | Edit or deactivate a staff account. | admin bearer (UserPolicy) | TM-12 |
| `DELETE` | `/api/v1/admin/users/{user}` | Delete a staff account, reassigning their tickets. | admin bearer (UserPolicy) | TM-12 |
| `PATCH` | `/api/v1/admin/users/{user}/password` | Set another user's password. | admin bearer (UserPolicy) | TM-12 |
| `GET` | `/api/v1/admin/workload` | Open ticket counts per person, split by priority, with load bands. | admin bearer (middleware) | TM-35 |

### `GET /api/v1/tickets`

All parameters are optional and compose on one request. `status_id`,
`priority_id`, and `category_id` accept one id or arrays. Array limits are 20,
20, and 50 respectively. Soft-deleted categories are rejected; deactivated
categories remain filterable.

`assigned_to` accepts `me`, `unassigned`, or a numeric user id. `escalated`
accepts a boolean. `q` accepts up to 255 characters and searches ticket reference,
subject, description, requester name, and requester email. `sort` accepts only
`created_at`, `updated_at`, `priority`, `escalated_at`, or `relevance`;
`direction` accepts `asc` or `desc`. Priority sorting uses priority level, then
ticket id descending as deterministic tiebreak. `per_page` accepts 1-100 and
defaults to 15.

`escalated_at` sorts by when a ticket was last escalated. It is nullable, and
MySQL orders NULL below any value, so `sort=escalated_at&direction=desc` puts the
most recently escalated first and never-escalated tickets last — **without**
filtering them out. Combine it with `escalated=true` to see only escalated
tickets. `tickets.escalated_at` is indexed for this sort.

`q` composes with every filter. Subject and description use the
`tickets_subject_description_fulltext` index in boolean mode with a trailing
wildcard per token, so `print` matches "Printer" and "Printing". Tokens shorter
than 3 characters and MySQL stopwords contribute nothing to fulltext matching;
reference and requester fields use escaped `LIKE` matching. Boolean-mode
operators are stripped before fulltext matching.

When `q` is present and `sort` is omitted, results order by exact reference,
FULLTEXT score, then newest. `sort=relevance` without `q` returns `422`.
`direction` does not apply to relevance sorting.

### `GET /api/v1/priorities` and `GET /api/v1/statuses`

Both identical in shape: bearer token required, any active staff member, no
parameters. Return `{"data": [...]}`, unpaginated, in seeded display order
(`Priority::ordered()`/`Status::ordered()` — level ascending / sort order
ascending).

A priority row is `{id, name, slug, level, color, is_default}`. A status row is
`{id, name, slug, bucket, color, is_default, is_terminal, sort_order}`. See
`docs/ticket-lifecycle.md` for the seven seeded statuses and their legal
transitions.

### `GET /api/v1/tickets/stats`

Bearer token required, any active staff member. No parameters — the response is
scoped by role rather than by query string: an admin sees every ticket, an
agent sees only tickets assigned to them.

Response shape, from `TicketStats::for()`:

```json
{
  "scope": "own",
  "total": 42,
  "unassigned": 5,
  "escalated": 3,
  "mine_open": 7,
  "by_status": [
    { "id": 1, "name": "New", "slug": "new", "color": "#3B82F6", "bucket": "open", "is_terminal": false, "count": 8 }
  ],
  "by_priority": [
    { "id": 2, "name": "Medium", "slug": "medium", "color": "#F59E0B", "level": 2, "count": 20 }
  ]
}
```

`scope` is `"own"` for an agent and `"all"` for an admin — it describes what
`total`, `unassigned`, `escalated`, `by_status`, and `by_priority` are counted
over. `mine_open` is always the **caller's own** open (non-terminal) count,
regardless of `scope` — an admin viewing all-ticket totals still sees their own
open count separately. `by_status` and `by_priority` are zero-filled: every
seeded status and priority appears even with a count of 0, in seeded order.

### `POST /api/v1/tickets`

Requires a bearer token; any active staff member may file a ticket. Body:

```json
{
  "requester": { "name": "<required>", "email": "<required>", "phone": "<optional>", "company": "<optional>" },
  "subject": "<required, max 255>",
  "description": "<required, max 16000>",
  "category_id": "<required, must be active and not soft-deleted>",
  "priority_id": "<optional, defaults to the default priority>",
  "status_id": "<optional, defaults to the default status>"
}
```

The requester is matched by email (`Requester::firstOrCreate`) — an existing
requester's name/phone/company are **not** overwritten by a later submission
under the same email. `reference`, `created_by`, and the `created` activity row
are set by the server and cannot be supplied. `422` on a missing/invalid
`category_id`, a soft-deleted or inactive category, or a missing
`requester.email`. `201` with the full ticket detail shape (no `can` key — that
field is present only on `GET /tickets/{ticket}`).

### `GET /api/v1/tickets/{ticket}`

Requires a bearer token; any active staff member may view any ticket
(`TicketPolicy::view` is unconditional). `404` for an unknown or soft-deleted
id.

`200` with the full `TicketResource` shape, which on this route only also
includes:

- `escalation_reason` — the free-text reason from the most recent escalation.
- `can` — `{update, assign, claim, change_status, escalate, delete, add_note}`,
  each a boolean computed from the caller's policies **and** the ticket's
  current state (`claim` is `false` once assigned; `escalate` is `false` on a
  terminal status).
- `allowed_transitions` — the status rows the caller may legally move this
  ticket to right now, role-filtered. See `docs/ticket-lifecycle.md`.
- `resolution` — `{note, at, by}` or `null`; the current resolution note if the
  ticket is presently resolved, `null` otherwise (a reopen clears it without
  deleting the note from the activity trail).
- `reopen_count` — how many times this ticket has been reopened.

### `POST /api/v1/tickets/{ticket}/assign`

**Admin-only bearer token** — `403` for an agent (`TicketPolicy::assign`). Body:

```json
{ "assigned_to": "<id>|null", "reason": "<optional, max 500>" }
```

`assigned_to` is **required present**, not merely optional-when-absent —
omitting the key entirely is `422`, but sending `null` is a valid unassign.
When non-null it must resolve to an **active agent** id, or the request is
`422`. `200` with the updated ticket. Re-assigning to the ticket's current
assignee is still `200` and writes **zero** activity rows — it is a no-op, not
an error. Assigning writes one `assigned` row; unassigning (`assigned_to:
null`) writes one `unassigned` row instead, both under `field = 'assigned_to'`.

`reason` is optional free text, capped at **500 characters** (characters, not
bytes — a 500-character Arabic reason is accepted and stored unescaped). It is
kept on the activity row as `meta.reason`, and the timeline renders it. Three
details of that contract are load-bearing:

- **`meta.reason` is absent when no reason was given, not `null`**, so a reader
  can tell "no reason offered" from "reason cleared". Note that `meta.reason` on
  a `category_changed` row is a machine string (`'category_deleted'`); here it is
  free text written by a person, stored verbatim and escaped by the renderer.
- **A no-op assignment drops the reason.** Re-assigning the ticket's current
  assignee writes no activity row, so a reason sent with it is silently
  discarded. Nothing changed, so there is nothing to annotate.
- **`assigned_to: ""` unassigns.** Laravel's `ConvertEmptyStringsToNull`
  middleware rewrites `""` to `null` before validation, so the API cannot
  distinguish an empty form field from a deliberate `null`. Clients must send
  `null` on purpose and must never submit an empty assignee field.

**Notifications.** A successful assignment or reassignment dispatches
`App\Events\TicketAssigned` **after the transaction commits**, and its listener
emails the new assignee. **Unassigning dispatches nothing, and the previous
assignee is never notified.** A no-op assignment dispatches nothing either, so a
double submit cannot double-notify, and a self-assignment (including
`/claim`) sends no email.

### `POST /api/v1/tickets/{ticket}/claim`

**Agent-only bearer token** — `403` for an admin (`TicketPolicy::claim`). No
request body.

`200` with the ticket assigned to the caller on success, writing one `claimed`
activity row. Claiming a ticket the caller already holds is also `200` and
writes nothing. On conflict, **two distinct `409` shapes**:

- Someone else already holds it: `{"message": "<name> already claimed this
  ticket.", "assignee": {"id": <id>, "name": "<name>"}}`.
- The ticket was unassigned again between the caller's read and their claim
  attempt (a lost race with no winner to name):
  `{"message": "This ticket's assignment changed while you were claiming it.
  Reload and try again."}`.

### `PATCH /api/v1/tickets/{ticket}`

Requires a bearer token; open to any active staff member
(`TicketPolicy::update`). Body: `subject`, `description`, `category_id`,
`priority_id` — all `sometimes`, so a partial body only changes the fields it
names.

Every other field is `prohibited` and returns `422` if present, including
`status_id`, `assigned_to`, `requester_id`, `reference`, `created_by`,
`created_at`, `updated_at`, `deleted_at`, `escalation_level`, `escalated_at`,
`escalated_by`, `escalation_reason`, `first_responded_at`, `resolved_at`, and
`closed_at` — status and assignment each have their own endpoint, and every
timestamp is server-managed. A request that changes nothing (values identical
to the current row) still returns `200` with the ticket unchanged and writes
**zero** activity rows. Otherwise writes one `updated` activity row **per
changed field**, each carrying that field's own old and new value. `404` for an
unknown or soft-deleted ticket.

### `DELETE /api/v1/tickets/{ticket}`

**Admin-only bearer token** — `403` for an agent (`TicketPolicy::delete`). No
request body.

Soft-deletes the ticket and writes one `deleted` activity row
(`meta.reference`, `meta.subject`) before it does — the activity trail survives
the delete, and remains visible through `GET /tickets/{ticket}/activities` for
an admin who still has the id. `204` on success, `404` for an unknown or
already-deleted ticket.

### `POST /api/v1/tickets/{ticket}/escalate`

Requires a bearer token; every staff member may escalate. Body is
`{"reason": "<10–5000 chars>"}`.

Increments `escalation_level`, stamps `escalated_at`, `escalated_by` and
`escalation_reason` (the column holds the **latest** reason; the activity trail
holds every one), raises the priority by exactly one level and stops at the
highest, and reassigns the ticket to the active admin with the fewest open
tickets — unless it is already held by an active admin, in which case the
assignment is left alone. **The status is never changed.**

Writes one `escalated` activity row (`field = 'escalation_level'`, both levels,
`meta.reason`, `meta.from_priority`, `meta.to_priority`) and, only when the
assignment moved, one `assigned` row in the same shape the assign endpoint uses,
so assignment history stays findable by `field = 'assigned_to'`.

A **terminal** ticket returns `422` under `errors.status` — not `403`;
`TicketPolicy::escalate` is a pure role gate and the state check lives in the
handler, while `can.escalate` on the detail response folds both together so the
UI hides the control. A missing or too-short `reason` returns `422` under
`errors.reason`; a `priority_id` or `assigned_to` in the body returns `422`. If
no active administrator exists the escalation is refused with `422` under
`errors.assigned_to` and nothing is written.

Returns `200` with the ticket in the detail shape minus `can` and the other
per-caller keys. Dispatches `App\Events\TicketEscalated` after commit, which
queues an email to every active admin — see the `Notifications` section below.

### `POST /api/v1/auth/login`

Unauthenticated; limited to 5 requests per minute per IP. Returns `200` with a
plain-text Sanctum token and public user fields. Unknown email, wrong password,
and inactive account return the same `422` credentials error. Malformed input
returns Laravel's validation envelope; excess attempts return `429`.

### `POST /api/v1/auth/logout`

Requires `Authorization: Bearer <token>`. Deletes only token that made request;
other devices stay signed in. Returns `204` with empty body. Missing, malformed,
revoked, or inactive-account tokens return `401` with `Unauthenticated.` An
inactive account also has all remaining tokens revoked.

### `GET /api/v1/auth/me`

Requires `Authorization: Bearer <token>`. Returns `200` with same public user
object login response nests under `user`: `id`, `name`, `email`, `role`,
`is_active`, and `created_at`. Missing, malformed, revoked, or inactive-account
tokens return `401` with `Unauthenticated.`

### `PATCH /api/v1/auth/password`

Requires `Authorization: Bearer <token>`. Limited to 6 requests per minute per
user. Body requires `current_password`, `password`, and
`password_confirmation`. New password must be at least 8 characters, match its
confirmation, and differ from current password.

Returns `204` with empty body. Every other token for user is revoked; requesting
token remains valid. Invalid current password returns `422` under
`errors.current_password`; other validation failures return `422` under
`errors.password`. Missing, revoked, or inactive-account tokens return `401`.
Excess attempts return `429`. This endpoint is for your **own** password only;
an admin setting someone else's uses
`PATCH /api/v1/admin/users/{user}/password`. No email-based self-service reset
exists — `password_reset_tokens` is created by the users migration and nothing
reads it.

### `GET /api/v1/health`

Unauthenticated by design. Returns **`200`** when every check passes and **`503`**
when any check fails.

| Field | Type | Notes |
|---|---|---|
| `status` | string | `ok` or `degraded`. |
| `app` | string | Application name. |
| `environment` | string | Current application environment. |
| `version` | string | App version from `APP_VERSION`. |
| `api` | string | Fixed API version, `v1`. |
| `time` | string | ISO-8601 server time. |
| `checks.database.ok` | bool | Database connectivity result. |
| `checks.database.error` | string | Only on failure; detailed when debug is enabled, otherwise `unavailable`. |

### `GET /api/v1/tickets/{ticket}/activities`

Requires `Authorization: Bearer <token>`. Any active staff user may read any
ticket's timeline (`TicketPolicy::view`). Returns `200` with the paginated
`data` / `links` / `meta` envelope. `per_page` is optional, an integer between
1 and 100, default 20; anything else returns `422`. Ordering is
`created_at DESC, id DESC` -- the tie-break matters because activities
recorded together share one timestamp. An unknown or soft-deleted ticket
returns `404`.

`events` is an optional array of `TicketActivityEvent` values
(`?events[]=note_added&events[]=status_changed`); an unknown value returns
`422` naming it. No `events` parameter means every event type. Selections are
de-duplicated and capped at the number of event types that exist. Filtering
narrows `data`, `meta.total` and `meta.last_page`; it does **not** narrow
`meta.event_counts`.

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
| `meta.event_counts` | object | `{event: count}` for every event type **present on this ticket**, unaffected by `events`. Drives the SPA's filter options, so a new event becomes filterable with no client change. |

Known `meta` keys, each owned by the story that writes it: `reference`
(`created`, TM-22), `from_name` / `to_name` / `reason` (`category_changed`,
TM-18; `status_changed`, TM-38; `assigned`, TM-31; `escalated`, TM-41),
`resolution` (`status_changed` into Resolved, TM-39), `reference` / `subject`
(`deleted`, TM-28). `reason` is **omitted rather than null** when no reason
was given, so its presence means a human wrote something.

Values are stored verbatim: no HTML escaping, no unicode escaping. **Escaping
is the renderer's obligation** -- the SPA renders every one of these through
Vue interpolation and never `v-html`.

Pagination is offset-based and the trail is append-only (TM-48), so a page
fetched later may repeat rows the caller already holds -- when new rows arrive
at the head, the window shifts backwards over data already seen. It can never
skip a row. Clients that append pages must de-duplicate by `id`; the SPA does.

**The trail is append-only, enforced.** `TicketActivity` uses a custom Eloquent
builder that refuses `update`, `upsert`, `increment`, `decrement`, `delete`,
`forceDelete` and `truncate`; only `insert` is permitted, which is the single
path `ActivityRecorder` uses. There is no endpoint that mutates or removes an
activity, and a feature test scans the whole route table to keep it that way.
`Ticket::forceDelete()` is refused for the same reason -- the `ticket_id`
foreign key is `ON DELETE CASCADE`, so a hard delete would erase the trail.

**The limit, stated rather than implied:** a raw
`DB::table('ticket_activities')->update(...)` bypasses Eloquent and no
model-layer guard stops it. No route reaches it and nothing in `backend/app/`
does it -- a test fails the build with a filename if that changes -- but an
operator with database access is not constrained by application code.

Note: `GET /api/v1/tickets/{ticket}` and `POST /api/v1/tickets` remain
undocumented here -- pre-existing debt from TM-26 and TM-22, not backfilled by
this story.

### `POST /api/v1/tickets/{ticket}/notes`

Requires `Authorization: Bearer <token>`. Any active staff user may note any
ticket, **including a Resolved or Closed one** -- `TicketPolicy::addNote`
deliberately carries no terminal guard, because a post-mortem note is the point.
Body: `{ "body": "..." }`, required, 3 to 5000 characters. Whitespace-only is
trimmed to null by global middleware and returns `422 required`.

Returns **`201`** with the created activity in the same shape as
`GET /api/v1/tickets/{ticket}/activities`: `event` is `note_added`, the body is
`meta.note`, and `field`, `old_value`, `new_value`, `from_label` and `to_label`
are all **null** -- a note is not a field change.

**The note is stored byte-identical.** No HTML escaping, no unicode escaping, no
sanitising. Escaping is the renderer's obligation, and the SPA renders every
note through Vue interpolation and never `v-html`.

**Adding a note changes nothing on the `tickets` table** -- not `status_id`, not
`updated_at`, not any timestamp. This is deliberate: `php artisan
tickets:flag-stale` defines staleness as `tickets.updated_at`, so a note does
not reset the staleness clock. The consequence -- an agent investigating through
notes alone will see the ticket flagged -- is a known trade-off raised with the
backlog owner in TM-47, not an oversight.

**Notes are internal and permanent.** There is no `PATCH` and no `DELETE`: an
activity row cannot be amended (TM-48). If an amendable note is ever wanted, the
shape is a second note that supersedes the first, never a mutation. Notes are
also never sent to a requester -- no mail path reads them, and a test fails the
build if this endpoint ever sends or queues a message. **Excluding notes from a
requester mailable remains E8-S3's and E8-S6's criterion**; nothing here proves
it, because no mailable exists yet.

### `GET /api/v1/admin/workload`

Admin-only, enforced by the `admin` middleware rather than a policy — there is no
per-record decision to make. No parameters. One grouped aggregate query plus
three lookups; nothing is cached.

**"Open" means `statuses.is_terminal = false`**, the same definition as
`/tickets/stats`'s `mine_open`. Soft-deleted tickets are excluded.

| Field | Notes |
|---|---|
| `average_open` | Mean open tickets across the **active-agent cohort**, rounded to 1 dp **for display only**. `null` when there are no active agents. |
| `band` | `max(1, ceil(average_open * 0.25))`. `null` when `average_open` is. |
| `priorities` | Ordered by `level`. The column headers; per-agent counts reference these by `priority_id`. |
| `open_status_ids` | The non-terminal status ids, so a client can build a row link that matches the counts without a second request. |
| `agents[].user` | Full user object including `email` — safe because the route is admin-only. |
| `agents[].open_total` | Their open tickets across all priorities. |
| `agents[].in_average` | Whether they are in the cohort: active **and** `role = agent`. |
| `agents[].load` | `high` / `normal` / `low`, or **`null`** when `in_average` is false. Computed from the **unrounded** mean — **do not recompute it client-side from `average_open`.** |
| `agents[].needs_reassignment` | `true` when they hold open tickets but cannot be newly assigned any — inactive, or not an agent. |
| `agents[].by_priority` | Zero-filled, in `priorities` order. |

**Who appears:** every active agent (**including those with zero tickets** — they
are the ones you rebalance onto, and they pull the average down), plus anyone
holding at least one open ticket. That second clause is what surfaces an
**inactive agent** who still holds work, and also a **former agent now an admin**
— both hold tickets they can never be assigned again, because assignment requires
an active agent (TM-31).

### `POST /api/v1/tickets/{ticket}/status`

Requires a bearer token; `TicketPolicy::changeStatus` admits every staff member,
so the role rules live in `status_transitions`, not in the route. Body is
`{"status_id": <int>, "resolution"?: "<string>", "reason"?: "<string>"}`.

`resolution` is `required|min:10|max:5000` when `status_id` targets **Resolved**
and `prohibited` for every other target; `reason` is the same shape for
**Reopened**. Posting either field for a different target, or omitting it for
the target that needs it, returns `422` on that field before the workflow guard
runs.

Returns `200` with the ticket in `GET /api/v1/tickets/{ticket}`'s shape **minus**
`can`, `allowed_transitions`, `resolution` and `reopen_count`, all four of which
are recomputed per caller, per current state, or as an aggregate, and are stale
the moment the status changes — clients re-read the detail rather than
splicing this response into it.

An unknown `status_id` returns `422` before the workflow is consulted. An illegal
move, an admin-only move made by an agent, and a move to the current status each
return `422` under `errors.status_id` with the message the Status workflow
section lists. A non-existent or soft-deleted ticket returns `404` for both
roles.

Each accepted change writes one `ticket_activities` row: `event` is
`status_changed` for most moves, or `reopened` when the target is Reopened,
`field = 'status_id'`, `old_value` and `new_value` the two status ids, and
`meta.from_name` / `meta.to_name` the two status names. `user_id` is the caller.

`first_responded_at` is stamped once, on the first move off New. `resolved_at`
and `closed_at` are stamped on their matching targets and **both cleared** on
any move to a non-terminal status, including a reopen — so a ticket reopened
after being Closed shows neither timestamp until it is resolved or closed
again.

`GET /api/v1/tickets/{ticket}` carries `data.resolution`: the ticket's
**current** resolution as `{ note, at, by }`, or `null` when the ticket is not
currently resolved. `at` is taken from `resolved_at`, never from the
activity row's own timestamp, so the two can never disagree. `by` is `{ id,
name }`, or `null` when the system acted. It is read from the
`status_changed` activity row that carried the note — the **latest** one, so
a ticket resolved twice reports the second note — not from a column on
`tickets`; a reopened ticket therefore reports `null` without the note ever
being deleted from the trail.

`data.reopen_count` is the number of `reopened` activity rows on the ticket. It
is always present on the detail route, is `0` for a ticket that has never been
reopened, and survives the ticket moving on from Reopened.

Rows are ordered by `name` then `id`, matching `GET /api/v1/admin/users`.

### `DELETE /api/v1/admin/users/{user}`

**Admin-only bearer token.** Optional body `{"reassign_to": <user id>}` — a
`DELETE` with a body, the same shape `DELETE /api/v1/categories/{category}`
uses. `204` on success, having revoked every token the deleted account held
(`personal_access_tokens` has no foreign key, so nothing cascades).

`PATCH` is the ordinary way to remove someone: deactivation keeps every
reference intact. A delete is refused whenever it would lose something:

- The target still owns tickets and no `reassign_to` was sent — **`422`** with
  `errors.reassign_to`, plus `ticket_count` and `reassign_to_options` (a
  `UserResource` array of active accounts) in the same body, so the client can
  offer the choice without a second request. `tickets.created_by` is
  `ON DELETE RESTRICT`, so this refusal is what stands between an admin and a
  driver-level `500`.
- `reassign_to` names a deactivated, unknown, or self id — **`422`** under
  `errors.reassign_to`.
- The target is the last active administrator — **`422`** under `errors.user`,
  checked under a row lock inside the deleting transaction.
- The target is the acting admin — **`403`**; you cannot delete the account
  making the request.
- The caller is an agent — **`403`**. An unknown id is **`404`**.

Both the target's authored (`created_by`) and assigned (`assigned_to`) tickets
move to `reassign_to`, soft-deleted tickets included, and each move is written
to that ticket's activity trail: an `assigned` row for an assignment and an
`updated` row with `field: "created_by"` for authorship, both carrying
`meta.reason: "user_deleted"` and the deleted person's name in
`meta.from_name`. Historical `ticket_activities.user_id` and
`tickets.escalated_by` references are **not** rewritten — they are
`ON DELETE SET NULL`, so those rows keep their event, values and timestamp and
lose the actor, exactly as a system-generated activity row has always looked.

### `PATCH /api/v1/admin/users/{user}/password`

**Admin-only bearer token**, limited to **6 requests per minute** per acting
admin — the same limiter as `PATCH /api/v1/auth/password`, not the 60/min write
limiter. Body requires `current_password` — **the acting admin's own password**,
not the target's — and `password`, which must be at least 8 characters. There is
no `password_confirmation`: a typo locks out someone else and is fixed by
repeating the reset.

`204` with an empty body on success. **Every token the target holds is revoked**
and the acting admin's own token is untouched, so the target must sign in again
everywhere with the new password. A wrong or missing `current_password` is a
**`422`** under `errors.current_password` and changes nothing; a short password
is a `422` under `errors.password`. Resetting **your own** password here is a
**`403`** — use `PATCH /api/v1/auth/password`, which proves you know the current
one. An agent gets `403`, an unknown id `404`, excess attempts `429`.

The reset is recorded as a `warning` log line carrying the two user ids and
nothing else. The account holder is **not** emailed that their password changed;
no story owns that notification yet.

## Notifications

`App\Events\TicketAssigned` is dispatched by `POST /api/v1/tickets/{ticket}/assign`
after its transaction commits, only when a real assignment change was made, and
never on unassign or a repeated no-op assignment.
`App\Listeners\SendTicketAssignedNotification` turns that event into a queued
`App\Notifications\TicketAssignedNotification` addressed to the new assignee
alone. The email carries the reference, subject, priority, category, requester
and a link to `{FRONTEND_URL}/tickets/{id}`, plus the handover reason when the
assignment carried one. Nothing is sent during the request: `notify()` pushes a
`SendQueuedNotifications` job and a worker delivers it. Self-assignment,
unassignment, a repeated no-op assignment, an inactive assignee and a deleted
ticket all send nothing.

`POST /api/v1/tickets` dispatches `App\Events\TicketCreated` after its
transaction commits. `App\Listeners\SendTicketCreatedConfirmation` turns it into
a queued `App\Notifications\TicketCreatedNotification` addressed to the
requester alone. The email leads with the reference and echoes the subject and
description exactly as submitted; it carries no priority, status, category,
assignee, internal note or link, and it makes no promise about a response time.
A requester whose address is blank is skipped with a logged warning and nothing
is queued. Nothing is sent during the request.

`POST /api/v1/tickets/{ticket}/status` dispatches `App\Events\TicketStatusChanged`
after its transaction commits. `App\Listeners\SendTicketStatusChangedNotification`
queues a `TicketStatusChangedNotification` to the requester **only** when the
target status slug appears in `config('notifications.requester.visible_statuses')`
(`NOTIFY_REQUESTER_STATUSES`, default `in-progress,pending,resolved,closed,reopened`).
The email names the old and new status, echoes the subject, and quotes the
resolution note verbatim when the transition carried one; it never quotes a
reopen reason and carries no assignee, agent name, internal note or link. Each
message is held for `NOTIFY_REQUESTER_DELAY_SECONDS` (default 300) and is
dropped at send time if the ticket has changed status again since, so a run of
rapid changes produces one email describing the final state.

`POST /api/v1/tickets/{ticket}/escalate` dispatches `App\Events\TicketEscalated`
after its transaction commits. `App\Listeners\SendTicketEscalatedNotification`
queues a `TicketEscalatedNotification` to **every active admin** — one job per
recipient — including the escalating user when they are an admin, unless
`NOTIFY_ESCALATING_ADMIN=false`. The subject begins with the filterable token
`[ESCALATED`, gaining an `×N` suffix above level one, and the message carries
`X-Ticket-Escalation-Level` and `X-Ticket-Reference` headers. The body names the
reason verbatim, the level, the requester, who escalated it, where the ticket
landed and its current priority, and links to the ticket in the SPA. Escalations
are never delayed or suppressed: two escalations produce two emails.

Every notification renders through `resources/views/mail/layout.blade.php` and
its plain-text twin `layout-text.blade.php`, which supply the header, the footer
and a 600px card that goes fluid below 600px. Ticket fields are rendered by
`mail/partials/summary.blade.php` from a `label => value` map each notification
builds, so a requester's email and an admin's email share the markup without
sharing a field list. Neither layout contains a link: staff emails build their
own from `config('app.frontend_url')`, and requester emails carry none because a
requester has no login. The from address comes from `config('mail.from')`, the
product name from `config('app.name')`, and no mail template contains a literal
URL.

Every notification is queued with the policy in `config/notifications.php`:
`tries` attempts (default 3) with a `backoff` of 60 then 300 seconds and a
30-second `timeout`, which must stay below the database queue's `retry_after`
of 90. A notification that exhausts its attempts lands in `failed_jobs` with its
payload and exception, and writes one `Log::error` naming the ticket, its
reference and the notification class — no address, and no email about the
failure. Every event dispatches after its transaction commits, so a rolled-back
action queues nothing; `queue.connections.database.after_commit` is
deliberately `false`, because the dispatch sites are already outside their
transactions and enabling it would defer jobs into a callback that
`RefreshDatabase` never fires.
