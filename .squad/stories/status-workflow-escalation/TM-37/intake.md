> **Fetched from jira:** [TM-37](https://notes-mangment.atlassian.net/browse/TM-37)  
> *Fetched 2026-08-25T09:45:10.824Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Transition table and workflow guard  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, database, sprint-3, testing

### Description

As a developer, I want a status_transitions table and a TicketWorkflow service that validates every status change against it so that the lifecycle is enforced by the server and stays configurable without code changes.

Acceptance Criteria

	status_transitions stores from_status_id, to_status_id and an optional required_role

	Seeded edges implement New to Open to In Progress to Resolved to Closed, with Pending reachable from the active states and Reopened reachable from the terminal ones

	TicketWorkflow exposes allowedTransitions(ticket, user) and assertCanTransition(...)

	Any status change not present in the table is rejected with 422 and a message naming the attempted move

	A transition whose required_role is admin is refused for agents

	Unit tests cover both a legal and an illegal move for every seeded status

Planning

Target sprint: 3

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/status-workflow-escalation/TM-37/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `status-workflow-escalation`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-37` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, database, sprint-3, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Transition table and workflow guard
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want a status_transitions table and a TicketWorkflow service that validates every status change against it so that the lifecycle is enforced by the server and stays configurable without code changes.

Acceptance Criteria

	status_transitions stores from_status_id, to_status_id and an optional required_role

	Seeded edges implement New to Open to In Progress to Resolved to Closed, with Pending reachable from the active states and Reopened reachable from the terminal ones

	TicketWorkflow exposes allowedTransitions(ticket, user) and assertCanTransition(...)

	Any status change not present in the table is rejected with 422 and a message naming the attempted move

	A transition whose required_role is admin is refused for agents

	Unit tests cover both a legal and an illegal move for every seeded status

Planning

Target sprint: 3
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
