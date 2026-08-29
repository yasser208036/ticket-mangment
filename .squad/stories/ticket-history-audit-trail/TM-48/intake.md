> **Fetched from jira:** [TM-48](https://notes-mangment.atlassian.net/browse/TM-48)  
> *Fetched 2026-08-25T09:50:39.758Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** The audit trail is append-only  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-4, testing

### Description

As a admin, I want activity records to be impossible to edit or delete so that the history can be trusted as evidence.

Acceptance Criteria

	No update or delete endpoint exists for ticket_activities anywhere in the routes file

	The model blocks updates and deletes at the application level as a second line of defence

	Soft-deleting a ticket preserves all of its activity rows

	A feature test asserts that no route can mutate or remove an existing activity

Planning

Target sprint: 4

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-history-audit-trail/TM-48/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-history-audit-trail`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-48` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-4, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
The audit trail is append-only
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want activity records to be impossible to edit or delete so that the history can be trusted as evidence.

Acceptance Criteria

	No update or delete endpoint exists for ticket_activities anywhere in the routes file

	The model blocks updates and deletes at the application level as a second line of defence

	Soft-deleting a ticket preserves all of its activity rows

	A feature test asserts that no route can mutate or remove an existing activity

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
