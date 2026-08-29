> **Fetched from jira:** [TM-33](https://notes-mangment.atlassian.net/browse/TM-33)  
> *Fetched 2026-08-25T09:43:37.141Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** My tickets view  
**Type:** Story  
**Status:** To Do  
**Labels:** frontend, sprint-3

### Description

As a agent, I want a view scoped to the tickets assigned to me so that I can focus on my own work without filtering every time.

Acceptance Criteria

	A My Tickets route pre-applies the assigned-to-me filter and excludes terminal statuses by default

	The view reuses the same table and filter components as the main list rather than duplicating them

	A toggle reveals my resolved and closed tickets

	The sidebar shows a live count of my open tickets

Planning

Target sprint: 3

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/assignment-workload/TM-33/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `assignment-workload`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-33` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `frontend, sprint-3`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
My tickets view
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want a view scoped to the tickets assigned to me so that I can focus on my own work without filtering every time.

Acceptance Criteria

	A My Tickets route pre-applies the assigned-to-me filter and excludes terminal statuses by default

	The view reuses the same table and filter components as the main list rather than duplicating them

	A toggle reveals my resolved and closed tickets

	The sidebar shows a live count of my open tickets

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
