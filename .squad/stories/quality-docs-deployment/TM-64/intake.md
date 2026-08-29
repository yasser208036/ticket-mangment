> **Fetched from jira:** [TM-64](https://notes-mangment.atlassian.net/browse/TM-64)  
> *Fetched 2026-08-25T09:55:52.257Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Security hardening pass  
**Type:** Story  
**Status:** To Do  
**Labels:** backend, sprint-5, testing

### Description

As a developer, I want a deliberate security review of the finished system so that obvious vulnerabilities are closed before anyone relies on this.

Acceptance Criteria

	Login and all write endpoints are rate limited

	CORS is restricted to an explicit origin allowlist, never a wildcard in production

	Every model defines fillable fields, so no mass-assignment hole exists on any request

	All output is escaped and every user-supplied string in the timeline and ticket views is checked against XSS

	No query concatenates user input; all filters and sorts use bindings or a whitelist

	No secret, token or credential is present anywhere in the repository history

Planning

Target sprint: 5

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/quality-docs-deployment/TM-64/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `quality-docs-deployment`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-64` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `backend, sprint-5, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Security hardening pass
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want a deliberate security review of the finished system so that obvious vulnerabilities are closed before anyone relies on this.

Acceptance Criteria

	Login and all write endpoints are rate limited

	CORS is restricted to an explicit origin allowlist, never a wildcard in production

	Every model defines fillable fields, so no mass-assignment hole exists on any request

	All output is escaped and every user-supplied string in the timeline and ticket views is checked against XSS

	No query concatenates user input; all filters and sorts use bindings or a whitelist

	No secret, token or credential is present anywhere in the repository history

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
