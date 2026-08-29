> **Fetched from jira:** [TM-18](https://notes-mangment.atlassian.net/browse/TM-18)  
> *Fetched 2026-08-25T09:37:18.484Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Protect referential integrity on category delete  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-2

### Description

As a admin, I want deleting a category that still has tickets to be blocked or to require reassignment so that no ticket is ever orphaned and history stays readable.

Acceptance Criteria

	Deleting a category with tickets returns 422 explaining how many tickets block it

	The response offers reassignment to another category, which moves the tickets and then deletes

	Reassignment is wrapped in a transaction and logs an activity row on every affected ticket

	A category with no tickets deletes cleanly as a soft delete

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/categories-priorities-statuses/TM-18/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `categories-priorities-statuses`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-18` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Protect referential integrity on category delete
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want deleting a category that still has tickets to be blocked or to require reassignment so that no ticket is ever orphaned and history stays readable.

Acceptance Criteria

	Deleting a category with tickets returns 422 explaining how many tickets block it

	The response offers reassignment to another category, which moves the tickets and then deletes

	Reassignment is wrapped in a transaction and logs an activity row on every affected ticket

	A category with no tickets deletes cleanly as a soft delete

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
