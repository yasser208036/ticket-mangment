> **Fetched from jira:** [TM-45](https://notes-mangment.atlassian.net/browse/TM-45)  
> *Fetched 2026-08-25T09:49:37.685Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Activity table and a single recorder  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, database, sprint-4

### Description

As a developer, I want the ticket_activities table plus an ActivityRecorder that is the only code path writing to it so that the audit trail is complete by construction rather than by good intentions.

Acceptance Criteria

	ticket_activities stores ticket_id, nullable user_id, event, field, old_value, new_value, a JSON meta column and created_at

	A backed enum defines every event type, so no raw event strings appear in the codebase

	ActivityRecorder is the only class that inserts into ticket_activities, and TicketService is its only caller

	A composite index on (ticket_id, created_at) keeps timeline reads fast

	A null user_id means the actor was the system, and the UI renders it as such

	Recording an activity happens in the same transaction as the change it describes, so the two can never disagree

Planning

Target sprint: 4

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-history-audit-trail/TM-45/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-history-audit-trail`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-45` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, database, sprint-4`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Activity table and a single recorder
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want the ticket_activities table plus an ActivityRecorder that is the only code path writing to it so that the audit trail is complete by construction rather than by good intentions.

Acceptance Criteria

	ticket_activities stores ticket_id, nullable user_id, event, field, old_value, new_value, a JSON meta column and created_at

	A backed enum defines every event type, so no raw event strings appear in the codebase

	ActivityRecorder is the only class that inserts into ticket_activities, and TicketService is its only caller

	A composite index on (ticket_id, created_at) keeps timeline reads fast

	A null user_id means the actor was the system, and the UI renders it as such

	Recording an activity happens in the same transaction as the change it describes, so the two can never disagree

Planning

Target sprint: 4
```

---

## Acceptance criteria

*(Checklist, bullets, Gherkin, etc. Prefilled for Azure DevOps when the work item has acceptance criteria.)*

```

```

---

## Attachments

Place files in `attachments/` next to this `intake.md`, then list them here so the planner knows what to open.

| File (relative to this folder) | What it is |
| ------------------------------ | ---------- |
| *(e.g. `attachments/flow.png`)* | *(e.g. UX flow)* |

*(Add rows per file. If none, write "None.")*

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
