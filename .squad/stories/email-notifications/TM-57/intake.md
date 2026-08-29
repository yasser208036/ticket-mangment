> **Fetched from jira:** [TM-57](https://notes-mangment.atlassian.net/browse/TM-57)  
> *Fetched 2026-08-25T09:53:46.990Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Notifications are queued, retried and never break a request  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-5, testing

### Description

As a developer, I want every notification dispatched from a queued job with retries and backoff so that a mail failure degrades quietly instead of failing the user's action.

Acceptance Criteria

	Every mailable is queued; no notification is sent inline during a request

	Jobs declare tries and backoff, and exhausted jobs land in failed_jobs with context

	A mail transport outage does not roll back or fail the ticket action that triggered it

	Notifications dispatch after the database transaction commits, so no email references uncommitted data

	Feature tests assert both the queued notification and its recipients for each event

Planning

Target sprint: 5

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/email-notifications/TM-57/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `email-notifications`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-57` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-5, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Notifications are queued, retried and never break a request
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want every notification dispatched from a queued job with retries and backoff so that a mail failure degrades quietly instead of failing the user's action.

Acceptance Criteria

	Every mailable is queued; no notification is sent inline during a request

	Jobs declare tries and backoff, and exhausted jobs land in failed_jobs with context

	A mail transport outage does not roll back or fail the ticket action that triggered it

	Notifications dispatch after the database transaction commits, so no email references uncommitted data

	Feature tests assert both the queued notification and its recipients for each event

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
