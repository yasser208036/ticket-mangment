> **Fetched from jira:** [TM-24](https://notes-mangment.atlassian.net/browse/TM-24)  
> *Fetched 2026-08-25T09:39:41.731Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Filter and sort the ticket queue  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-2

### Description

As a agent, I want to filter by status, priority, category, assignee and escalation state and to sort the results so that I can narrow thousands of tickets down to the ones I must act on.

Acceptance Criteria

	All filters compose on a single request and can be combined freely

	Sorting is supported on created_at, updated_at and priority level, ascending and descending

	Only whitelisted sort columns are accepted, so the parameter cannot be used for injection

	Active filters are reflected in the URL query string so a filtered view can be bookmarked and shared

	A visible clear-all control resets every filter at once

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-creation-tracking/TM-24/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-creation-tracking`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-24` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Filter and sort the ticket queue
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want to filter by status, priority, category, assignee and escalation state and to sort the results so that I can narrow thousands of tickets down to the ones I must act on.

Acceptance Criteria

	All filters compose on a single request and can be combined freely

	Sorting is supported on created_at, updated_at and priority level, ascending and descending

	Only whitelisted sort columns are accepted, so the parameter cannot be used for injection

	Active filters are reflected in the URL query string so a filtered view can be bookmarked and shared

	A visible clear-all control resets every filter at once

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
