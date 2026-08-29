> **Fetched from jira:** [TM-43](https://notes-mangment.atlassian.net/browse/TM-43)  
> *Fetched 2026-08-25T09:48:35.833Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Flag stale tickets on a schedule  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-4

### Description

As a admin, I want a scheduled command that flags tickets with no activity for N days so that nothing is quietly forgotten in the queue.

Acceptance Criteria

	An artisan command finds non-terminal tickets whose last activity is older than a configurable threshold

	Each stale ticket receives a system activity row attributed to no user

	The threshold and whether the command notifies are both configuration driven

	The command is registered in the scheduler, is idempotent, and will not re-flag a ticket already flagged

	Running the command with --dry-run reports what it would flag and changes nothing

Planning

Target sprint: 4

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/status-workflow-escalation/TM-43/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `status-workflow-escalation`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-43` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-4`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Flag stale tickets on a schedule
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want a scheduled command that flags tickets with no activity for N days so that nothing is quietly forgotten in the queue.

Acceptance Criteria

	An artisan command finds non-terminal tickets whose last activity is older than a configurable threshold

	Each stale ticket receives a system activity row attributed to no user

	The threshold and whether the command notifies are both configuration driven

	The command is registered in the scheduler, is idempotent, and will not re-flag a ticket already flagged

	Running the command with --dry-run reports what it would flag and changes nothing

Planning

Target sprint: 4
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
