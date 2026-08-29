> **Fetched from jira:** [TM-59](https://notes-mangment.atlassian.net/browse/TM-59)  
> *Fetched 2026-08-25T09:54:43.569Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Factories and a demo seeder  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-5, testing

### Description

As a developer, I want model factories and a demo seeder producing a realistic dataset so that the app can be demonstrated and tested against believable data immediately.

Acceptance Criteria

	Factories exist for users, requesters, categories, tickets and activities

	A demo seeder creates admins, several agents, a spread of categories and roughly fifty tickets across every status and priority

	Seeded tickets have plausible activity histories, including some assigned, some escalated and some resolved

	Created dates are spread over recent months so date filters and age columns are meaningful

	The demo seeder is separate from the production seeder and never runs in production

Planning

Target sprint: 5

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/quality-docs-deployment/TM-59/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `quality-docs-deployment`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-59` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-5, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Factories and a demo seeder
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want model factories and a demo seeder producing a realistic dataset so that the app can be demonstrated and tested against believable data immediately.

Acceptance Criteria

	Factories exist for users, requesters, categories, tickets and activities

	A demo seeder creates admins, several agents, a spread of categories and roughly fifty tickets across every status and priority

	Seeded tickets have plausible activity histories, including some assigned, some escalated and some resolved

	Created dates are spread over recent months so date filters and age columns are meaningful

	The demo seeder is separate from the production seeder and never runs in production

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
