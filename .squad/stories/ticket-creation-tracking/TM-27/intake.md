> **Fetched from jira:** [TM-27](https://notes-mangment.atlassian.net/browse/TM-27)  
> *Fetched 2026-08-25T09:40:30.573Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Edit a ticket  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-3

### Description

As a agent, I want to correct a ticket's subject, description, category or priority so that mistakes at intake can be fixed without losing the record.

Acceptance Criteria

	PATCH /api/v1/tickets/
{ticket}
 accepts partial updates and validates only what is sent

	Every changed field writes its own activity row capturing the old and new values

	Status and assignee cannot be changed through this endpoint; they have dedicated actions

	reference, created_by and all timestamps are immutable through the API

	The edit form pre-populates and warns before discarding unsaved changes

Planning

Target sprint: 3

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-creation-tracking/TM-27/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-creation-tracking`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-27` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-3`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Edit a ticket
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want to correct a ticket's subject, description, category or priority so that mistakes at intake can be fixed without losing the record.

Acceptance Criteria

	PATCH /api/v1/tickets/
{ticket}
 accepts partial updates and validates only what is sent

	Every changed field writes its own activity row capturing the old and new values

	Status and assignee cannot be changed through this endpoint; they have dedicated actions

	reference, created_by and all timestamps are immutable through the API

	The edit form pre-populates and warns before discarding unsaved changes

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
