> **Fetched from jira:** [TM-56](https://notes-mangment.atlassian.net/browse/TM-56)  
> *Fetched 2026-08-25T09:53:35.471Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Shared responsive mail layout  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-5

### Description

As a developer, I want one Blade mail layout that every notification extends so that emails look consistent and render correctly across mail clients.

Acceptance Criteria

	A single layout provides the header, footer and ticket-summary block reused by all mailables

	Emails render correctly on desktop and mobile widths and degrade gracefully without CSS

	Every email has a plain-text alternative part

	The from address, product name and base URL all come from configuration

	No email leaks internal notes, agent email addresses or stack traces

Planning

Target sprint: 5

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/email-notifications/TM-56/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `email-notifications`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-56` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-5`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Shared responsive mail layout
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want one Blade mail layout that every notification extends so that emails look consistent and render correctly across mail clients.

Acceptance Criteria

	A single layout provides the header, footer and ticket-summary block reused by all mailables

	Emails render correctly on desktop and mobile widths and degrade gracefully without CSS

	Every email has a plain-text alternative part

	The from address, product name and base URL all come from configuration

	No email leaks internal notes, agent email addresses or stack traces

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
