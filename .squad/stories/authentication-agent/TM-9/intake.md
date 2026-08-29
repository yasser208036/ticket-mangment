> **Fetched from jira:** [TM-9](https://notes-mangment.atlassian.net/browse/TM-9)  
> *Fetched 2026-08-25T09:32:12.914Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Log in and receive an API token  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-1

### Description

As a user, I want to log in with my email and password and receive a Sanctum token so that I can access the protected parts of the system.

Acceptance Criteria

	POST /api/v1/auth/login validates credentials and returns a token plus the user object

	Invalid credentials return 422 with a generic message that does not reveal whether the email exists

	A deactivated user (is_active = false) cannot log in even with a correct password

	The login route is rate limited to a small number of attempts per minute per IP

	Passwords are hashed with bcrypt and never appear in any API response

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/authentication-agent/TM-9/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `authentication-agent`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-9` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-1`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Log in and receive an API token
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a user, I want to log in with my email and password and receive a Sanctum token so that I can access the protected parts of the system.

Acceptance Criteria

	POST /api/v1/auth/login validates credentials and returns a token plus the user object

	Invalid credentials return 422 with a generic message that does not reveal whether the email exists

	A deactivated user (is_active = false) cannot log in even with a correct password

	The login route is rate limited to a small number of attempts per minute per IP

	Passwords are hashed with bcrypt and never appear in any API response

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
