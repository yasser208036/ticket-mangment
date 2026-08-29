> **Fetched from jira:** [TM-16](https://notes-mangment.atlassian.net/browse/TM-16)  
> *Fetched 2026-08-25T09:35:15.384Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Master data migrations and seeders  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, database, sprint-2

### Description

As a developer, I want categories, priorities and statuses tables seeded with sensible defaults so that tickets can be classified the moment the app is installed.

Acceptance Criteria

	categories table has name, slug, description, color, is_active, sort_order, timestamps and soft deletes

	priorities table has name, slug, level, color and is_default, seeded with Low, Medium, High and Urgent

	statuses table has name, slug, bucket, color, is_default, is_terminal and sort_order, seeded with New, Open, In Progress, Pending, Resolved, Closed and Reopened

	Exactly one priority and one status are marked is_default

	Seeders are idempotent and keyed on slug so re-running does not duplicate rows

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/categories-priorities-statuses/TM-16/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `categories-priorities-statuses`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-16` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, database, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Master data migrations and seeders
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want categories, priorities and statuses tables seeded with sensible defaults so that tickets can be classified the moment the app is installed.

Acceptance Criteria

	categories table has name, slug, description, color, is_active, sort_order, timestamps and soft deletes

	priorities table has name, slug, level, color and is_default, seeded with Low, Medium, High and Urgent

	statuses table has name, slug, bucket, color, is_default, is_terminal and sort_order, seeded with New, Open, In Progress, Pending, Resolved, Closed and Reopened

	Exactly one priority and one status are marked is_default

	Seeders are idempotent and keyed on slug so re-running does not duplicate rows

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
