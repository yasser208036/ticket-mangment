> **Fetched from jira:** [TM-46](https://notes-mangment.atlassian.net/browse/TM-46)  
> *Fetched 2026-08-25T09:50:14.070Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Ticket timeline  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, frontend, sprint-4

### Description

As a agent, I want a chronological timeline on the ticket page showing who changed what and when so that I can understand a ticket's whole story before I act on it.

Acceptance Criteria

	GET /api/v1/tickets/
{ticket}
/activities returns activities newest first, paginated

	Each entry renders as readable prose such as Ahmed changed status from Open to In Progress

	Old and new values are shown for field changes, and the actor and relative time appear on every entry

	Each event type has its own icon and colour so the timeline can be scanned quickly

	System entries are visually distinct from user entries

Planning

Target sprint: 4

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-history-audit-trail/TM-46/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-history-audit-trail`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-46` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, frontend, sprint-4`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Ticket timeline
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a agent, I want a chronological timeline on the ticket page showing who changed what and when so that I can understand a ticket's whole story before I act on it.

Acceptance Criteria

	GET /api/v1/tickets/
{ticket}
/activities returns activities newest first, paginated

	Each entry renders as readable prose such as Ahmed changed status from Open to In Progress

	Old and new values are shown for field changes, and the actor and relative time appear on every entry

	Each event type has its own icon and colour so the timeline can be scanned quickly

	System entries are visually distinct from user entries

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
