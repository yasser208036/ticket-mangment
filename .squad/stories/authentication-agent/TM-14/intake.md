> **Fetched from jira:** [TM-14](https://notes-mangment.atlassian.net/browse/TM-14)  
> *Fetched 2026-08-25T09:33:53.803Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Change my own password  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-1

### Description

As a user, I want to change my password by confirming the current one so that I can rotate my credentials without asking an admin.

Acceptance Criteria

	PATCH /api/v1/auth/password requires the current password and a confirmed new password

	New password is validated against a minimum strength rule

	All other tokens for that user are revoked after a successful change

	A wrong current password returns 422 without changing anything

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/authentication-agent/TM-14/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `authentication-agent`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-14` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-1`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Change my own password
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a user, I want to change my password by confirming the current one so that I can rotate my credentials without asking an admin.

Acceptance Criteria

	PATCH /api/v1/auth/password requires the current password and a confirmed new password

	New password is validated against a minimum strength rule

	All other tokens for that user are revoked after a successful change

	A wrong current password returns 422 without changing anything

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
