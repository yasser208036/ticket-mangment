> **Fetched from jira:** [TM-11](https://notes-mangment.atlassian.net/browse/TM-11)  
> *Fetched 2026-08-25T09:33:04.074Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** SPA session persistence and route guards  
**Type:** Story  
**Status:** To Do  
**Labels:** frontend, sprint-1

### Description

As a user, I want the SPA to remember my session, guard private routes and bounce me to login when my token expires so that I am never stranded on a broken screen.

Acceptance Criteria

	A Pinia auth store holds the token and user and rehydrates on page reload

	Router guard redirects unauthenticated users to /login and preserves the intended destination

	Admin-only routes are blocked for agents with a clear not-authorised screen

	A 401 from any request clears the store and redirects to login exactly once, without a redirect loop

	Logging out clears the persisted token from storage

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/authentication-agent/TM-11/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `authentication-agent`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-11` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `frontend, sprint-1`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
SPA session persistence and route guards
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a user, I want the SPA to remember my session, guard private routes and bounce me to login when my token expires so that I am never stranded on a broken screen.

Acceptance Criteria

	A Pinia auth store holds the token and user and rehydrates on page reload

	Router guard redirects unauthenticated users to /login and preserves the intended destination

	Admin-only routes are blocked for agents with a clear not-authorised screen

	A 401 from any request clears the store and redirects to login exactly once, without a redirect loop

	Logging out clears the persisted token from storage

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
