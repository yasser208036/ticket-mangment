> **Fetched from jira:** [TM-8](https://notes-mangment.atlassian.net/browse/TM-8)  
> *Fetched 2026-08-25T08:53:28.620Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Users table with role enum and a seeded admin  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, database, sprint-1

### Description

As a developer, I want the users migration with a role enum plus a seeder creating the first admin so that a fresh install can be logged into immediately.

Acceptance Criteria

	users table has name, email (unique), password, role enum('admin','agent'), is_active and timestamps

	A PHP 8.3 backed enum UserRole is the single definition of the role values

	Seeder creates one admin whose credentials come from environment variables, not hardcoded

	The seeder is idempotent and safe to run twice

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/authentication-agent/TM-8/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `authentication-agent`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-8` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, database, sprint-1`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Users table with role enum and a seeded admin
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want the users migration with a role enum plus a seeder creating the first admin so that a fresh install can be logged into immediately.

Acceptance Criteria

	users table has name, email (unique), password, role enum('admin','agent'), is_active and timestamps

	A PHP 8.3 backed enum UserRole is the single definition of the role values

	Seeder creates one admin whose credentials come from environment variables, not hardcoded

	The seeder is idempotent and safe to run twice

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
