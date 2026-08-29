> **Fetched from jira:** [TM-13](https://notes-mangment.atlassian.net/browse/TM-13)  
> *Fetched 2026-08-25T09:33:37.816Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Role-based authorization via policies  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-1, testing

### Description

As a admin, I want authorization enforced by Laravel policies on every endpoint so that an agent cannot reach admin functionality by calling the API directly.

Acceptance Criteria

	UserPolicy, CategoryPolicy and TicketPolicy define who may view, create, update and delete

	Every controller action authorizes before acting; no endpoint relies on the UI hiding a button

	An agent calling an admin-only endpoint receives 403, verified by a feature test per endpoint

	Policy logic reads the UserRole enum rather than comparing raw strings

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/authentication-agent/TM-13/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `authentication-agent`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-13` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-1, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Role-based authorization via policies
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want authorization enforced by Laravel policies on every endpoint so that an agent cannot reach admin functionality by calling the API directly.

Acceptance Criteria

	UserPolicy, CategoryPolicy and TicketPolicy define who may view, create, update and delete

	Every controller action authorizes before acting; no endpoint relies on the UI hiding a button

	An agent calling an admin-only endpoint receives 403, verified by a feature test per endpoint

	Policy logic reads the UserRole enum rather than comparing raw strings

Planning

Target sprint: 1
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
