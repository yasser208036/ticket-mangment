> **Fetched from jira:** [TM-19](https://notes-mangment.atlassian.net/browse/TM-19)  
> *Fetched 2026-08-25T09:37:06.706Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Cache master data in a Pinia store  
**Type:** Story  
**Status:** To Do  
**Labels:** frontend, sprint-2

### Description

As a agent, I want categories, priorities and statuses fetched once and shared across the app so that dropdowns and badges render instantly without repeated requests.

Acceptance Criteria

	A masterData Pinia store loads all three lists on first authenticated page load

	Every dropdown and badge in the app reads from this store, never from its own request

	The store exposes lookup helpers so a component can resolve an id to a name and colour

	Mutating master data in the admin screens refreshes the store without a page reload

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/categories-priorities-statuses/TM-19/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `categories-priorities-statuses`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-19` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `frontend, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Cache master data in a Pinia store
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want categories, priorities and statuses fetched once and shared across the app so that dropdowns and badges render instantly without repeated requests.

Acceptance Criteria

	A masterData Pinia store loads all three lists on first authenticated page load

	Every dropdown and badge in the app reads from this store, never from its own request

	The store exposes lookup helpers so a component can resolve an id to a name and colour

	Mutating master data in the admin screens refreshes the store without a page reload

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
