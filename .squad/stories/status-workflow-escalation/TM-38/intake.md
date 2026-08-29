> **Fetched from jira:** [TM-38](https://notes-mangment.atlassian.net/browse/TM-38)  
> *Fetched 2026-08-25T09:45:47.796Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Change a ticket's status  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-3

### Description

As a agent, I want to move a ticket to its next status, seeing only the moves that are legal from here so that I cannot put a ticket into an invalid state by accident.

Acceptance Criteria

	POST /api/v1/tickets/
{ticket}
/status transitions the ticket through TicketWorkflow

	The ticket detail page offers only the transitions returned by allowedTransitions for the current user

	Each change writes a status_changed activity row with the old and new status names

	The UI reflects the new status immediately without a full page reload

	A rejected transition surfaces the server message rather than a generic error

Planning

Target sprint: 3

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/status-workflow-escalation/TM-38/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `status-workflow-escalation`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-38` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-3`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Change a ticket's status
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want to move a ticket to its next status, seeing only the moves that are legal from here so that I cannot put a ticket into an invalid state by accident.

Acceptance Criteria

	POST /api/v1/tickets/
{ticket}
/status transitions the ticket through TicketWorkflow

	The ticket detail page offers only the transitions returned by allowedTransitions for the current user

	Each change writes a status_changed activity row with the old and new status names

	The UI reflects the new status immediately without a full page reload

	A rejected transition surfaces the server message rather than a generic error

Planning

Target sprint: 3
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
