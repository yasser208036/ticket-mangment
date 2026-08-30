# Ticket Lifecycle

The workflow a ticket moves through: its statuses, the legal moves between
them, the timestamps each move stamps, and how escalation and activity
logging fit in. See `docs/erd.md` for the schema these rules sit on top of and
`docs/api-contract.md` for the exact request/response shapes of the endpoints
mentioned below — this file states the rules, not the wire format.

## Statuses

Seven seeded statuses (`StatusSeeder::STATUSES`), each with a `bucket` and an
`is_terminal` flag:

| Slug | Name | Bucket | Terminal |
|---|---|---|---|
| `new` | New | open | no |
| `open` | Open | open | no |
| `in-progress` | In Progress | open | no |
| `pending` | Pending | pending | no |
| `resolved` | Resolved | done | **yes** |
| `closed` | Closed | done | **yes** |
| `reopened` | Reopened | open | no |

`new` is the default status a ticket is created in. `bucket` is a coarse
grouping used for filters and dashboards; `is_terminal` is what
`TicketTimestamps` (below) actually branches on.

## Legal transitions

`status_transitions` is the single source of truth — one row per legal move,
seeded from `StatusTransitionSeeder::EDGES` and editable at runtime without a
deploy. Fourteen edges today:

| From | To | Required role |
|---|---|---|
| `new` | `open` | any staff |
| `new` | `pending` | any staff |
| `open` | `in-progress` | any staff |
| `open` | `pending` | any staff |
| `in-progress` | `resolved` | any staff |
| `in-progress` | `pending` | any staff |
| `pending` | `open` | any staff |
| `pending` | `in-progress` | any staff |
| `resolved` | `closed` | **admin only** |
| `resolved` | `reopened` | any staff |
| `closed` | `reopened` | any staff |
| `reopened` | `in-progress` | any staff |
| `reopened` | `resolved` | any staff |
| `reopened` | `pending` | any staff |

**Every pair not in this table is illegal.** That is 28 of the 42 possible
ordered pairs among 7 statuses (7 × 6, excluding a status targeting itself) —
generated as the complement of the table above, never hand-listed, so it never
drifts out of sync with it.

`resolved → closed` is the one edge that requires the admin role; every other
edge is open to any active staff member. An illegal move — including this one,
attempted by an agent — returns **`422` under `errors.status_id`, never
`403`**. The policy layer (`TicketPolicy::changeStatus`) is a pure pass-through
with no per-transition logic; `TicketWorkflow::reject()` is what refuses the
move, and it always throws a validation error. A move to the ticket's own
current status is also `422`, for the same reason — there is no edge from a
status to itself.

## Timestamps

`TicketTimestamps` applies three rules on every legal move:

- **`first_responded_at`** is set once, on the first move off `new`, and never
  overwritten by a later move — it answers "how long before anyone touched
  this," not "when was it last touched."
- **Moving into a non-terminal status clears both `resolved_at` and
  `closed_at`.** This is what makes `closed → reopened` (and every other move
  back into an active status) drop a stale resolution/closure date — a
  reopened ticket reporting an old `resolved_at` would be the single most
  visibly wrong row this schema could produce.
- **Moving into `resolved` sets `resolved_at`; moving into `closed` sets
  `closed_at`.** Both are set to the moment of that specific move, not
  inherited from an earlier one.

A resolution note is required by `POST /tickets/{ticket}/status` when the
target is `resolved`, and prohibited otherwise; a reopen reason is required
when the target is `reopened`, and prohibited otherwise. Neither is a column
on `tickets` — both live in the `status_changed`/`reopened` activity row's
`meta`, and `GET /tickets/{ticket}`'s `resolution` field is the **latest**
resolution note read back out of that trail.

## Escalation

`POST /tickets/{ticket}/escalate` is a separate act from a status move — it
never changes `status_id`. One call:

1. Increments `escalation_level` by exactly one.
2. Raises the priority by exactly one level, capped at the highest seeded
   priority (raising an already-Urgent ticket is a no-op on priority).
3. Reassigns the ticket to the active admin with the fewest open (non-terminal)
   tickets — unless it is already held by an active admin, in which case the
   assignment is left alone. Ties break on the lowest user id, so the choice
   is deterministic. The escalating user is never excluded from being the
   target, even if they are the admin doing the escalating.
4. Is refused with `422` on a terminal ticket, and with `422` if no active
   admin exists to escalate to.

See `docs/api-contract.md`'s `POST /tickets/{ticket}/escalate` section for the
exact request/response shape and error field names.

## Assignment requests

An agent can no longer self-claim an unassigned ticket. Instead:

1. `POST /tickets/{ticket}/assignment-requests` records a `pending` request
   naming the agent, and writes an `assignment_requested` activity row. The
   ticket must be unassigned, and an agent may hold only one pending request
   per ticket — **two different agents may each have one pending on the same
   ticket at once**.
2. An admin reviews the queue (`GET /admin/assignment-requests`) and either:
   - **Approves** it — the ticket is assigned to that agent through the same
     writer `POST /tickets/{ticket}/assign` uses, so the trail's `assigned`
     row is indistinguishable in shape from a direct assign
     (`meta.reason: "assignment_request"`). Every other pending request on
     that ticket is declined automatically, each writing its own
     `assignment_request_declined` row.
   - **Declines** it — the ticket is untouched, and one
     `assignment_request_declined` row is written. No notification is sent
     either way; an admin action, not an event, is what tells the agent.

The only ways a ticket gains an assignee are, in total: `POST
/tickets/{ticket}/assign` (admin-only), choosing an agent at creation time
(`POST /tickets`, role `user` only), escalation's own reassignment, and an
approved assignment request.

See `docs/api-contract.md`'s assignment-request endpoints for exact
request/response shapes and error field names.

## Activity logging

Every mutation path writes to `ticket_activities` through `ActivityRecorder`,
the only writer the append-only builder permits. One sentence per event:

| Event | Written by |
|---|---|
| `created` | Filing a ticket (`POST /tickets`) |
| `updated` | Editing subject/description/category/priority (`PATCH /tickets/{ticket}`), one row per changed field |
| `deleted` | Soft-deleting a ticket (`DELETE /tickets/{ticket}`) |
| `assigned` | Assigning to an agent (`POST /tickets/{ticket}/assign`), or escalation's own reassignment |
| `unassigned` | Assigning `null` (`POST /tickets/{ticket}/assign`) |
| `claimed` | **Historical only** — self-claim was removed in favour of assignment requests; no live code path writes this event any more, but existing rows and the enum case remain |
| `assignment_requested` | An agent asking for an unassigned ticket (`POST /tickets/{ticket}/assignment-requests`) |
| `assignment_request_declined` | An admin declining a request, or a request auto-declined because another was approved for the same ticket |
| `status_changed` | Any status move that is not into `reopened` (`POST /tickets/{ticket}/status`) |
| `reopened` | A status move whose target is `reopened` — its own event, not `status_changed`, so the timeline reads plainly |
| `category_changed` | A category deleted while holding tickets, once per reassigned ticket, soft-deleted tickets included |
| `escalated` | `POST /tickets/{ticket}/escalate` |
| `note_added` | `POST /tickets/{ticket}/notes` |
| `stale` | The scheduled `tickets:flag-stale` command |

See `docs/api-contract.md`'s `GET /tickets/{ticket}/activities` section for the
row shape and filtering options.
