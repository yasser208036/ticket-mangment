> **Fetched from jira:** [TM-22](https://notes-mangment.atlassian.net/browse/TM-22)  
> *Fetched 2026-08-25T09:39:13.046Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Create a ticket  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-2

### Description

As a agent, I want to create a ticket capturing the requester, subject, description, category and priority so that an incoming request is recorded and can be worked.

Acceptance Criteria

	POST /api/v1/tickets validates every field through a FormRequest and returns the created ticket resource

	An existing requester is matched by email, otherwise a new requester contact is created in the same transaction

	Status defaults to the is_default status and priority to the is_default priority when not supplied

	created_by is taken from the authenticated user and can never be set from the request body

	A created activity row is written for every new ticket

	The create form validates client side, disables submit while in flight and cannot double-submit

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-creation-tracking/TM-22/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-creation-tracking`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-22` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Create a ticket
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want to create a ticket capturing the requester, subject, description, category and priority so that an incoming request is recorded and can be worked.

Acceptance Criteria

	POST /api/v1/tickets validates every field through a FormRequest and returns the created ticket resource

	An existing requester is matched by email, otherwise a new requester contact is created in the same transaction

	Status defaults to the is_default status and priority to the is_default priority when not supplied

	created_by is taken from the authenticated user and can never be set from the request body

	A created activity row is written for every new ticket

	The create form validates client side, disables submit while in flight and cannot double-submit

Planning

Target sprint: 2
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
