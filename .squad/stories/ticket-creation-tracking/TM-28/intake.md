> **Fetched from jira:** [TM-28](https://notes-mangment.atlassian.net/browse/TM-28)  
> *Fetched 2026-08-25T09:40:45.425Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Soft-delete a ticket  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-3

### Description

As a admin, I want to remove a ticket from the queue while keeping it recoverable so that spam and duplicates can be cleared without destroying data.

Acceptance Criteria

	DELETE /api/v1/tickets/
{ticket}
 is admin-only and performs a soft delete

	Soft-deleted tickets are excluded from all lists, filters, searches and statistics

	An activity row records who deleted the ticket and when

	The UI asks for confirmation naming the ticket reference before deleting

Planning

Target sprint: 3

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-creation-tracking/TM-28/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-creation-tracking`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-28` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-3`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Soft-delete a ticket
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want to remove a ticket from the queue while keeping it recoverable so that spam and duplicates can be cleared without destroying data.

Acceptance Criteria

	DELETE /api/v1/tickets/
{ticket}
 is admin-only and performs a soft delete

	Soft-deleted tickets are excluded from all lists, filters, searches and statistics

	An activity row records who deleted the ticket and when

	The UI asks for confirmation naming the ticket reference before deleting

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
