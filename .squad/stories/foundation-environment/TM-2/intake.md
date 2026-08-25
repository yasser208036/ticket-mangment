> **Fetched from jira:** [TM-2](https://notes-mangment.atlassian.net/browse/TM-2)  
> *Fetched 2026-08-25T08:50:05.141Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Scaffold the monorepo skeleton  
**Type:** Story  
**Status:** To Do  
**Labels:** devops, docs, sprint-1

### Description

As a developer, I want a monorepo with backend/, frontend/, docs/ and tools/ plus a root README and .gitignore so that the whole team works from one predictable layout.

Acceptance Criteria

	Repository initialised with git and a root .gitignore covering vendor/, node_modules/, .env, .jira.env and build output

	Root README documents the layout, prerequisites and how to start the stack

	docs/ contains a placeholder for the ERD, API contract and deployment runbook

	tools/jira/.jira.env is git-ignored and never committed

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/foundation-environment/TM-2/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `foundation-environment`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-2` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `devops, docs, sprint-1`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Scaffold the monorepo skeleton
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want a monorepo with backend/, frontend/, docs/ and tools/ plus a root README and .gitignore so that the whole team works from one predictable layout.

Acceptance Criteria

	Repository initialised with git and a root .gitignore covering vendor/, node_modules/, .env, .jira.env and build output

	Root README documents the layout, prerequisites and how to start the stack

	docs/ contains a placeholder for the ERD, API contract and deployment runbook

	tools/jira/.jira.env is git-ignored and never committed

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
