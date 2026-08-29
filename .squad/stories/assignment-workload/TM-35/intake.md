> **Fetched from jira:** [TM-35](https://notes-mangment.atlassian.net/browse/TM-35)  
> *Fetched 2026-08-25T09:44:07.141Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Agent workload overview  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-3

### Description

As a admin, I want to see how many open tickets each agent is carrying, broken down by priority so that I can rebalance before someone is overwhelmed.

Acceptance Criteria

	An endpoint returns open ticket counts per agent split by priority, using a grouped aggregate query

	The screen highlights agents well above and well below the team average

	Each agent row links to the ticket list filtered to that assignee

	Inactive agents holding open tickets are surfaced so their work can be reassigned

Planning

Target sprint: 3

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/assignment-workload/TM-35/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `assignment-workload`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-35` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-3`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Agent workload overview
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a admin, I want to see how many open tickets each agent is carrying, broken down by priority so that I can rebalance before someone is overwhelmed.

Acceptance Criteria

	An endpoint returns open ticket counts per agent split by priority, using a grouped aggregate query

	The screen highlights agents well above and well below the team average

	Each agent row links to the ticket list filtered to that assignee

	Inactive agents holding open tickets are surfaced so their work can be reassigned

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
