> **Source:** manual entry (tracker skipped via `--no-tracker`).
> Active tracker for this workspace: `jira` — this story is not linked.
> Run `squad tracker link <story-path> <tracker-id>` later if you want to attach one.

# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/authentication-agent/enhance-users/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `authentication-agent`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `` _(used in filenames and plan tables; fill manually if empty)_
- **Work item type:** ``
- **Status:** ``
- **Assignee:** ``
- **Labels:** ``

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

_(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)_

```
enhance-users
```

---

## Description

_(Paste the full work item description. Prefilled when fetched from a tracker.)_

```
This system is designed around three main roles: Admin, Agent, and User, with clearly defined responsibilities and access control.


1. Admin

The Admin is responsible for creating and managing all system users (Agents and Users).
Has full access to all tickets across the system.
Can assign or reassign tickets to any Agent at any time.
Can update ticket details and modify ticket assignments.
Has the ability to override any existing assignment.
Cannot create tickets on behalf of Users or Agents (ticket creation is restricted to Users only).

2. User

Users are created by the Admin and use the system to create tickets.
Can create tickets and optionally assign them to an Agent during creation.
If no Agent is assigned, the Admin will handle assignment later.
Can only view their own tickets.
Cannot view or access tickets created by other Users.
Cannot manage or modify Agents or system settings.

3. Agent

Agents are created by the Admin and are responsible for handling assigned tickets.
Can view:
Tickets assigned directly to them.
Unassigned tickets (to allow requesting assignment from Admin).
Can request the Admin to assign specific unassigned tickets to them.
Cannot create tickets.
Cannot access other Users’ tickets unless assigned.
Cannot modify ticket ownership or system configuration.

General Rules

Ticket visibility is strictly controlled based on role:
Users → only their own tickets.
Agents → assigned + unassigned tickets only.
Admin → all tickets.
Only Users are allowed to create tickets.
Assignment can be done by Users (optional at creation) or Admin (any time).
Admin has full control over ticket lifecycle and assignment management.
```

---

## Acceptance criteria

_(Checklist, bullets, Gherkin, etc. Prefilled for Azure DevOps when the work item has acceptance criteria.)_

```
This system is designed around three main roles: Admin, Agent, and User, with clearly defined responsibilities and access control.


1. Admin

The Admin is responsible for creating and managing all system users (Agents and Users).
Has full access to all tickets across the system.
Can assign or reassign tickets to any Agent at any time.
Can update ticket details and modify ticket assignments.
Has the ability to override any existing assignment.
Cannot create tickets on behalf of Users or Agents (ticket creation is restricted to Users only).

2. User

Users are created by the Admin and use the system to create tickets.
Can create tickets and optionally assign them to an Agent during creation.
If no Agent is assigned, the Admin will handle assignment later.
Can only view their own tickets.
Cannot view or access tickets created by other Users.
Cannot manage or modify Agents or system settings.

3. Agent

Agents are created by the Admin and are responsible for handling assigned tickets.
Can view:
Tickets assigned directly to them.
Unassigned tickets (to allow requesting assignment from Admin).
Can request the Admin to assign specific unassigned tickets to them.
Cannot create tickets.
Cannot access other Users’ tickets unless assigned.
Cannot modify ticket ownership or system configuration.

General Rules

Ticket visibility is strictly controlled based on role:
Users → only their own tickets.
Agents → assigned + unassigned tickets only.
Admin → all tickets.
Only Users are allowed to create tickets.
Assignment can be done by Users (optional at creation) or Admin (any time).
Admin has full control over ticket lifecycle and assignment management.

```

---

## Attachments

Place files in `attachments/` next to this `intake.md`, then list them here so the planner knows what to open.

| File (relative to this folder)  | What it is       |
| ------------------------------- | ---------------- |
| _(e.g. `attachments/flow.png`)_ | _(e.g. UX flow)_ |

_(Add rows per file. If none, write "None.")_

---

## Dependencies

- **Blocked by / related ids:** (tracker ids only; optional short note)
- **Depends on code areas or other stories:**

## Extra notes (optional)

- Anything not captured above (e.g. chat context) — keep short.

## Technical hints (optional)

- APIs, screens, services already discussed. Repos/roots: `.`. Primary language: `typescript`.

## Out of scope

- What this story explicitly does **not** cover:
