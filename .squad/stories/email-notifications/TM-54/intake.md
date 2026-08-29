> **Fetched from jira:** [TM-54](https://notes-mangment.atlassian.net/browse/TM-54)  
> *Fetched 2026-08-25T09:53:07.367Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Notify the requester on status change and resolution  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-5

### Description

As a requester, I want an email when my ticket progresses or is resolved so that I do not have to chase anyone for an update.

Acceptance Criteria

	A status change to a requester-visible status queues an email naming the old and new status

	Resolution email includes the resolution note

	Which statuses are requester-visible is configuration driven, not hardcoded at the call site

	Internal-only transitions send nothing

	Rapid consecutive changes do not produce a burst of near-identical emails

Planning

Target sprint: 5

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/email-notifications/TM-54/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `email-notifications`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-54` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-5`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Notify the requester on status change and resolution
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a requester, I want an email when my ticket progresses or is resolved so that I do not have to chase anyone for an update.

Acceptance Criteria

	A status change to a requester-visible status queues an email naming the old and new status

	Resolution email includes the resolution note

	Which statuses are requester-visible is configuration driven, not hardcoded at the call site

	Internal-only transitions send nothing

	Rapid consecutive changes do not produce a burst of near-identical emails

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
