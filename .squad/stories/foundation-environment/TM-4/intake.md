> **Fetched from jira:** [TM-4](https://notes-mangment.atlassian.net/browse/TM-4)  
> *Fetched 2026-08-25T09:30:01.571Z. Edit the sections below as needed; the planner reads this file verbatim.*


## Source — work item (from tracker)

**Title:** Scaffold the Vue 3 SPA with an API client  
**Type:** Story  
**Status:** To Do  
**Labels:** frontend, sprint-1

### Description

As a developer, I want a Vue 3 + TypeScript + Vite SPA with Vue Router, Pinia and a configured axios client so that the frontend can call the API with auth headers from day one.

Acceptance Criteria

	frontend/ scaffolded with Vue 3, TypeScript, Vite, Vue Router and Pinia

	src/api/client.ts exposes an axios instance reading the base URL from an env variable

	A request interceptor attaches the bearer token and a response interceptor redirects to login on 401

	The SPA successfully calls /api/v1/health and renders the result

	Vite dev server proxies API calls so there are no CORS issues in development

Planning

Target sprint: 1

### Attachments

None.

---
# Story intake

Fill this template for each story you want planned. Keep it copy-paste-friendly: the planner reads **this file and the files in `attachments/`**, nothing else.

- Folder: `.squad/stories/foundation-environment/TM-4/intake.md`
- Binaries (screenshots, PDFs, exports): put them in `attachments/` next to this file and list them below.
- Do **not** rely on external links (tracker URLs, wiki, chat) — the planner cannot open them. Paste the content you want considered.

This is **not** an implementation prompt. It is the input to the plan-generation meta-prompt bundled with squad-kit (`generate-plan.md` in the installed package).

---

## Feature

- **Feature name (display):**
- **Feature slug (folder under `plans/`):** `foundation-environment`

## Tracker (metadata only)

- **Tracker type:** `jira`
- **Work item id:** `TM-4` *(used in filenames and plan tables; fill manually if empty)*
- **Work item type:** `Story`
- **Status:** `To Do`
- **Assignee:** ``
- **Labels:** `frontend, sprint-1`

External tracker links are **not** followed by the planner. Keep the id for naming and traceability only.

---

## Title

*(Paste the work item title verbatim. Prefilled when `squad new-story` fetched from a tracker.)*

```
Scaffold the Vue 3 SPA with an API client
```

---

## Description

*(Paste the full work item description. Prefilled when fetched from a tracker.)*

```
As a developer, I want a Vue 3 + TypeScript + Vite SPA with Vue Router, Pinia and a configured axios client so that the frontend can call the API with auth headers from day one.

Acceptance Criteria

	frontend/ scaffolded with Vue 3, TypeScript, Vite, Vue Router and Pinia

	src/api/client.ts exposes an axios instance reading the base URL from an env variable

	A request interceptor attaches the bearer token and a response interceptor redirects to login on 401

	The SPA successfully calls /api/v1/health and renders the result

	Vite dev server proxies API calls so there are no CORS issues in development

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
