> **Fetched from jira:** [TM-21](https://notes-mangment.atlassian.net/browse/TM-21)  
> *Fetched 2026-08-25T09:38:35.695Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Tickets and requesters schema with a readable reference  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, database, sprint-2

### Description

As a developer, I want the tickets and requesters migrations with proper indexes and a unique human-readable reference so that tickets can be cited by number in email and conversation, and queries stay fast.

Acceptance Criteria

	requesters table stores name, email (unique), phone and company as contact records with no login capability

	tickets table carries reference, subject, description, requester_id, category_id, priority_id, status_id, assigned_to (nullable), created_by, the escalation columns and the resolved/closed timestamps

	reference is unique and formatted TKT-YYYY-NNNNNN, generated inside a transaction so concurrent creates cannot collide

	Indexes exist on status_id, category_id, priority_id, created_at and the composite (assigned_to, status_id)

	A MySQL 8 FULLTEXT index covers subject and description

	All foreign keys are constrained and tickets use soft deletes

Planning

Target sprint: 2

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/ticket-creation-tracking/TM-21/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `ticket-creation-tracking`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-21` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, database, sprint-2`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Tickets and requesters schema with a readable reference
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want the tickets and requesters migrations with proper indexes and a unique human-readable reference so that tickets can be cited by number in email and conversation, and queries stay fast.

Acceptance Criteria

	requesters table stores name, email (unique), phone and company as contact records with no login capability

	tickets table carries reference, subject, description, requester_id, category_id, priority_id, status_id, assigned_to (nullable), created_by, the escalation columns and the resolved/closed timestamps

	reference is unique and formatted TKT-YYYY-NNNNNN, generated inside a transaction so concurrent creates cannot collide

	Indexes exist on status_id, category_id, priority_id, created_at and the composite (assigned_to, status_id)

	A MySQL 8 FULLTEXT index covers subject and description

	All foreign keys are constrained and tickets use soft deletes

Planning

Target sprint: 2
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
