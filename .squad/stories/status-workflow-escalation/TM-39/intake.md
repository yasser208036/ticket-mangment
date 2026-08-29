> **Fetched from jira:** [TM-39](https://notes-mangment.atlassian.net/browse/TM-39)  
> *Fetched 2026-08-25T09:46:24.645Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Resolving requires a resolution note  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-4

### Description

As a agent, I want to be required to explain the resolution when I resolve a ticket so that the record says how the problem was actually solved.

Acceptance Criteria

	Transitioning into Resolved requires a non-empty resolution note of a minimum length

	resolved_at is stamped on the ticket and cleared if the ticket is later reopened

	The resolution note is stored on the activity row and rendered prominently in the timeline

	Transitioning into Closed stamps closed_at

	first_responded_at is stamped the first time a ticket leaves the New status

Planning

Target sprint: 4

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/status-workflow-escalation/TM-39/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `status-workflow-escalation`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-39` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-4`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Resolving requires a resolution note
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want to be required to explain the resolution when I resolve a ticket so that the record says how the problem was actually solved.

Acceptance Criteria

	Transitioning into Resolved requires a non-empty resolution note of a minimum length

	resolved_at is stamped on the ticket and cleared if the ticket is later reopened

	The resolution note is stored on the activity row and rendered prominently in the timeline

	Transitioning into Closed stamps closed_at

	first_responded_at is stamped the first time a ticket leaves the New status

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
