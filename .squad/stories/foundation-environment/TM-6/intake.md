> **Fetched from jira:** [TM-6](https://notes-mangment.atlassian.net/browse/TM-6)  
> *Fetched 2026-08-25T09:31:09.157Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Code quality tooling and CI pipeline  
**Type:** Story  
**Status:** To Do  
**Labels:** devops, sprint-1, testing

### Description

As a developer, I want Pint, ESLint, Prettier and a CI workflow that runs linters and tests on every push so that style drift and broken code are caught before review, not during it.

Acceptance Criteria

	php artisan pint --test passes on the backend

	npm run lint and npm run format:check pass on the frontend

	GitHub Actions workflow runs backend tests, frontend tests and both linters

	CI spins up a MySQL 8 service so backend feature tests run against a real database

	The workflow fails the build on any linter or test failure

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/foundation-environment/TM-6/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `foundation-environment`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-6` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `devops, sprint-1, testing`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Code quality tooling and CI pipeline
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want Pint, ESLint, Prettier and a CI workflow that runs linters and tests on every push so that style drift and broken code are caught before review, not during it.

Acceptance Criteria

	php artisan pint --test passes on the backend

	npm run lint and npm run format:check pass on the frontend

	GitHub Actions workflow runs backend tests, frontend tests and both linters

	CI spins up a MySQL 8 service so backend feature tests run against a real database

	The workflow fails the build on any linter or test failure

Planning

Target sprint: 1
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
