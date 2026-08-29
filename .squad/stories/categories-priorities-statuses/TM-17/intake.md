> **Fetched from jira:** [TM-17](https://notes-mangment.atlassian.net/browse/TM-17)  
> *Fetched 2026-08-25T09:36:09.448Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Admin CRUD for categories  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-2

### Description

As a admin, I want to create, edit, reorder and deactivate ticket categories so that classification matches how our support team actually works.

Acceptance Criteria

	Full CRUD available at /api/v1/categories, restricted to admins for writes and open to agents for reads

	Slug is generated from the name and is unique; name is required and unique

	Colour is validated as a hex value and used consistently by the UI badges

	Categories screen supports inline activate and deactivate plus drag-free sort_order editing

	Deactivated categories disappear from the new-ticket dropdown but remain on existing tickets

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/categories-priorities-statuses/TM-17/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `categories-priorities-statuses`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-17` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Admin CRUD for categories
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want to create, edit, reorder and deactivate ticket categories so that classification matches how our support team actually works.

Acceptance Criteria

	Full CRUD available at /api/v1/categories, restricted to admins for writes and open to agents for reads

	Slug is generated from the name and is unique; name is required and unique

	Colour is validated as a hex value and used consistently by the UI badges

	Categories screen supports inline activate and deactivate plus drag-free sort_order editing

	Deactivated categories disappear from the new-ticket dropdown but remain on existing tickets

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
