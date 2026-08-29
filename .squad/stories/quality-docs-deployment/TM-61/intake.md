> **Fetched from jira:** [TM-61](https://notes-mangment.atlassian.net/browse/TM-61)  
> *Fetched 2026-08-25T09:55:23.152Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Frontend component tests  
**Type:** Story  
**Status:** To Do  
**Labels:** frontend, sprint-5, testing

### Description

As a developer, I want Vitest tests for the ticket table, the filters and the ticket form so that the components users touch most do not regress silently.

Acceptance Criteria

	TicketTable tests cover rendering, the empty state and the loading state

	TicketFilters tests assert that changing a filter emits the expected query and that clear-all resets everything

	TicketForm tests cover validation errors, a successful submit and the double-submit guard

	The auth store is tested for login, logout and rehydration

	npm run test:unit passes in CI

Planning

Target sprint: 5

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/quality-docs-deployment/TM-61/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `quality-docs-deployment`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-61` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `frontend, sprint-5, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Frontend component tests
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want Vitest tests for the ticket table, the filters and the ticket form so that the components users touch most do not regress silently.

Acceptance Criteria

	TicketTable tests cover rendering, the empty state and the loading state

	TicketFilters tests assert that changing a filter emits the expected query and that clear-all resets everything

	TicketForm tests cover validation errors, a successful submit and the double-submit guard

	The auth store is tested for login, logout and rehydration

	npm run test:unit passes in CI

Planning

Target sprint: 5
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
