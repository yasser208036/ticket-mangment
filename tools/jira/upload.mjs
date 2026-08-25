#!/usr/bin/env node
/**
 * Pushes tools/jira/backlog.json to Jira Cloud as Epics + Stories.
 *
 *   node tools/jira/upload.mjs --dry-run       preview, writes nothing
 *   node tools/jira/upload.mjs                 create the backlog
 *   node tools/jira/upload.mjs --sync-points   backfill story points onto existing issues
 *
 * Credentials come from tools/jira/.jira.env (git-ignored) or the environment:
 *   JIRA_BASE_URL, JIRA_EMAIL, JIRA_API_TOKEN, JIRA_PROJECT_KEY
 *
 * Safe to re-run: anything whose summary already exists in the project is
 * skipped, so a half-finished run resumes instead of duplicating issues.
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const BACKLOG_FILE = join(HERE, 'backlog.json')
const ENV_FILE = join(HERE, '.jira.env')
const STATE_FILE = join(HERE, 'created-issues.json')

const DRY_RUN = process.argv.includes('--dry-run')
const SYNC_POINTS = process.argv.includes('--sync-points')
const THROTTLE_MS = 120

// ---------------------------------------------------------------- config

function loadEnv() {
  const config = {}
  if (existsSync(ENV_FILE)) {
    for (const line of readFileSync(ENV_FILE, 'utf8').split('\n')) {
      const trimmed = line.trim()
      if (!trimmed || trimmed.startsWith('#')) continue
      const eq = trimmed.indexOf('=')
      if (eq === -1) continue
      config[trimmed.slice(0, eq).trim()] = trimmed.slice(eq + 1).trim()
    }
  }
  // Real environment variables win over the file.
  for (const key of ['JIRA_BASE_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN', 'JIRA_PROJECT_KEY']) {
    if (process.env[key]) config[key] = process.env[key]
  }

  const missing = ['JIRA_BASE_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'].filter((k) => !config[k])
  if (missing.length && !DRY_RUN) {
    fail(
      `Missing ${missing.join(', ')}.\n\n` +
        `Create ${ENV_FILE} with:\n` +
        `  JIRA_BASE_URL=https://your-site.atlassian.net\n` +
        `  JIRA_EMAIL=you@example.com\n` +
        `  JIRA_API_TOKEN=<id.atlassian.com/manage-profile/security/api-tokens>\n` +
        `  JIRA_PROJECT_KEY=TM\n`
    )
  }

  config.JIRA_BASE_URL = (config.JIRA_BASE_URL || '').replace(/\/+$/, '')
  return config
}

// ---------------------------------------------------------------- http

let CONFIG = {}

async function jira(method, path, body, { allowFailure = false } = {}) {
  const url = `${CONFIG.JIRA_BASE_URL}${path}`
  const auth = Buffer.from(`${CONFIG.JIRA_EMAIL}:${CONFIG.JIRA_API_TOKEN}`).toString('base64')

  for (let attempt = 1; attempt <= 5; attempt++) {
    let response
    try {
      response = await fetch(url, {
        method,
        headers: {
          Authorization: `Basic ${auth}`,
          Accept: 'application/json',
          ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
      })
    } catch (cause) {
      if (attempt === 5) throw new Error(`${method} ${path} — network error: ${cause.message}`)
      await sleep(500 * attempt)
      continue
    }

    // Jira Cloud throttles bulk creation; honour its own backoff hint.
    if (response.status === 429 || response.status >= 503) {
      const retryAfter = Number(response.headers.get('retry-after')) || attempt * 2
      if (attempt === 5) throw new Error(`${method} ${path} — still throttled after 5 attempts`)
      warn(`${response.status} from Jira, waiting ${retryAfter}s (attempt ${attempt}/5)`)
      await sleep(retryAfter * 1000)
      continue
    }

    const text = await response.text()
    const payload = text ? safeJson(text) : null

    if (!response.ok) {
      if (allowFailure) return { ok: false, status: response.status, payload }
      throw new Error(`${method} ${path} → ${response.status}\n${describeJiraError(payload, text)}`)
    }
    return { ok: true, status: response.status, payload }
  }
}

function describeJiraError(payload, raw) {
  if (!payload) return raw.slice(0, 800)
  const parts = []
  if (Array.isArray(payload.errorMessages)) parts.push(...payload.errorMessages)
  if (payload.errors) {
    for (const [field, message] of Object.entries(payload.errors)) parts.push(`${field}: ${message}`)
  }
  return parts.length ? parts.join('\n') : JSON.stringify(payload).slice(0, 800)
}

// ---------------------------------------------------------------- ADF

/** Jira REST v3 wants descriptions in Atlassian Document Format, not plain text. */
function adf(story) {
  const content = [
    paragraph(`As a ${story.role}, I want ${story.want} so that ${story.soThat}.`),
    heading('Acceptance Criteria'),
    {
      type: 'bulletList',
      content: story.acceptanceCriteria.map((item) => ({
        type: 'listItem',
        content: [paragraph(item)],
      })),
    },
  ]
  if (story.sprint) {
    content.push(heading('Planning'), paragraph(`Target sprint: ${story.sprint}`))
  }
  return { type: 'doc', version: 1, content }
}

function adfEpic(epic) {
  return {
    type: 'doc',
    version: 1,
    content: [
      paragraph(epic.goal),
      heading('Stories in this epic'),
      {
        type: 'bulletList',
        content: epic.stories.map((story) => ({
          type: 'listItem',
          content: [paragraph(`${story.id} — ${story.summary}`)],
        })),
      },
    ],
  }
}

const paragraph = (text) => ({ type: 'paragraph', content: [{ type: 'text', text }] })
const heading = (text) => ({
  type: 'heading',
  attrs: { level: 3 },
  content: [{ type: 'text', text }],
})

// ------------------------------------------------------- project discovery

/**
 * Team-managed and company-managed projects disagree about how a story is
 * linked to its epic, and about what the story-point field is called. Ask the
 * instance instead of guessing.
 */
async function discoverProject(projectKey) {
  const { payload: me } = await jira('GET', '/rest/api/3/myself')
  info(`Authenticated as ${me.displayName} <${me.emailAddress ?? 'email hidden'}>`)

  const { payload: project } = await jira('GET', `/rest/api/3/project/${projectKey}`)
  const teamManaged = project.style === 'next-gen' || project.simplified === true
  info(`Project ${project.key} — "${project.name}" (${teamManaged ? 'team-managed' : 'company-managed'})`)

  const issueTypes = await fetchIssueTypes(projectKey)
  const epicType = pickIssueType(issueTypes, ['Epic'], 1)
  const storyType = pickIssueType(issueTypes, ['Story', 'Task'], 0)
  if (!epicType) fail(`No Epic issue type available in ${projectKey}. Enable Epics on the project first.`)
  if (!storyType) fail(`No Story or Task issue type available in ${projectKey}.`)
  info(`Issue types → epic: "${epicType.name}", story: "${storyType.name}"`)

  const { storyPointsField, epicLinkField } = await discoverFields(projectKey, storyType.id)
  info(
    `Story points field: ${storyPointsField ?? 'not available on this site — points will be skipped'}`
  )
  if (!storyPointsField) {
    warn(
      'Enable estimation to store points: Project settings → Features → Estimation (story points),\n' +
        '    then re-run with --sync-points to backfill them onto the existing issues.'
    )
  }

  return { project, teamManaged, epicType, storyType, storyPointsField, epicLinkField }
}

async function fetchIssueTypes(projectKey) {
  // Preferred (current) endpoint.
  const modern = await jira(
    'GET',
    `/rest/api/3/issue/createmeta/${projectKey}/issuetypes`,
    null,
    { allowFailure: true }
  )
  if (modern.ok && Array.isArray(modern.payload?.issueTypes)) return modern.payload.issueTypes
  if (modern.ok && Array.isArray(modern.payload?.values)) return modern.payload.values

  // Fallback for older sites still serving the deprecated shape.
  const legacy = await jira(
    'GET',
    `/rest/api/3/issue/createmeta?projectKeys=${projectKey}&expand=projects.issuetypes.fields`,
    null,
    { allowFailure: true }
  )
  const types = legacy.payload?.projects?.[0]?.issuetypes
  if (Array.isArray(types)) return types

  fail(`Could not read issue types for ${projectKey}. Check the project key and your permissions.`)
}

function pickIssueType(issueTypes, names, hierarchyLevel) {
  const usable = issueTypes.filter((type) => !type.subtask)
  for (const name of names) {
    const match = usable.find((type) => type.name?.toLowerCase() === name.toLowerCase())
    if (match) return match
  }
  return usable.find((type) => type.hierarchyLevel === hierarchyLevel) ?? null
}

async function discoverFields(projectKey, storyTypeId) {
  // Two sources, because they answer different questions: createmeta lists the
  // fields actually on the create screen, /field lists everything the site has.
  // A field can exist globally but be absent from the screen, so merge both.
  const candidates = []

  const scoped = await jira(
    'GET',
    `/rest/api/3/issue/createmeta/${projectKey}/issuetypes/${storyTypeId}`,
    null,
    { allowFailure: true }
  )
  if (scoped.ok) candidates.push(...(scoped.payload?.fields ?? scoped.payload?.values ?? []))

  const all = await jira('GET', '/rest/api/3/field', null, { allowFailure: true })
  if (all.ok && Array.isArray(all.payload)) candidates.push(...all.payload)

  const byName = (needle) => {
    const match = candidates.find((field) => (field.name ?? '').toLowerCase() === needle)
    return match ? (match.fieldId ?? match.key ?? match.id ?? null) : null
  }

  return {
    storyPointsField: byName('story point estimate') ?? byName('story points'),
    epicLinkField: byName('epic link'),
  }
}

// ---------------------------------------------------------------- sync points

/**
 * Estimation is off by default on team-managed projects, so the first upload has
 * nowhere to put story points. Once it is enabled, this backfills them without
 * touching anything else on the issues.
 */
async function syncPoints(backlog) {
  const ctx = await discoverProject(CONFIG.JIRA_PROJECT_KEY)
  if (!ctx.storyPointsField) {
    fail(
      'No story-point field on this site, so there is nothing to sync.\n' +
        `  Enable it at ${CONFIG.JIRA_BASE_URL}/jira/software/projects/${CONFIG.JIRA_PROJECT_KEY}/settings/features\n` +
        '  (Features → Estimation → Story points), then run this again.'
    )
  }

  const existing = await fetchExistingSummaries(CONFIG.JIRA_PROJECT_KEY)
  const state = existsSync(STATE_FILE) ? JSON.parse(readFileSync(STATE_FILE, 'utf8')) : {}
  const tally = { updated: 0, missing: 0 }

  for (const epic of backlog.epics) {
    for (const story of epic.stories) {
      const key = state[story.id] ?? existing.get(story.summary)
      if (!key) {
        warn(`${story.id} not found in Jira — run the upload first`)
        tally.missing++
        continue
      }
      if (!story.points) continue

      const result = await jira(
        'PUT',
        `/rest/api/3/issue/${key}`,
        { fields: { [ctx.storyPointsField]: story.points } },
        { allowFailure: true }
      )
      if (!result.ok) {
        warn(`${key} ${story.id} — ${describeJiraError(result.payload, '')}`)
        continue
      }
      console.log(`  ~ ${story.id} ${key} → ${story.points}p`)
      tally.updated++
      await sleep(THROTTLE_MS)
    }
  }

  console.log(`\nDone — ${tally.updated} issues estimated, ${tally.missing} not found.\n`)
}

// ------------------------------------------------------- existing issues

/** Index the project's current issues by summary so a re-run creates nothing twice. */
async function fetchExistingSummaries(projectKey) {
  const index = new Map()
  const jql = `project = "${projectKey}" ORDER BY created ASC`

  let nextPageToken
  for (let page = 0; page < 40; page++) {
    const modern = await jira(
      'POST',
      '/rest/api/3/search/jql',
      { jql, fields: ['summary'], maxResults: 100, ...(nextPageToken ? { nextPageToken } : {}) },
      { allowFailure: true }
    )

    if (!modern.ok) {
      const legacy = await jira(
        'GET',
        `/rest/api/3/search?jql=${encodeURIComponent(jql)}&fields=summary&maxResults=100&startAt=${page * 100}`,
        null,
        { allowFailure: true }
      )
      if (!legacy.ok) {
        warn('Could not list existing issues; relying on created-issues.json only.')
        return index
      }
      for (const issue of legacy.payload.issues ?? []) index.set(issue.fields.summary, issue.key)
      if ((legacy.payload.issues ?? []).length < 100) return index
      continue
    }

    for (const issue of modern.payload.issues ?? []) index.set(issue.fields.summary, issue.key)
    nextPageToken = modern.payload.nextPageToken
    if (!nextPageToken) return index
  }
  return index
}

// ---------------------------------------------------------------- create

async function createEpic(epic, ctx) {
  const fields = {
    project: { key: CONFIG.JIRA_PROJECT_KEY },
    issuetype: { id: ctx.epicType.id },
    summary: epic.summary,
    description: adfEpic(epic),
  }
  if (epic.labels?.length) fields.labels = epic.labels
  const { payload } = await jira('POST', '/rest/api/3/issue', { fields })
  return payload.key
}

async function createStory(story, epicKey, ctx) {
  const fields = {
    project: { key: CONFIG.JIRA_PROJECT_KEY },
    issuetype: { id: ctx.storyType.id },
    summary: story.summary,
    description: adf(story),
    labels: buildLabels(story),
  }
  if (ctx.storyPointsField && story.points) fields[ctx.storyPointsField] = story.points

  // `parent` is the modern way for both project styles; company-managed sites
  // that predate it need the Epic Link custom field instead.
  const withParent = { ...fields, parent: { key: epicKey } }
  const first = await jira('POST', '/rest/api/3/issue', { fields: withParent }, { allowFailure: true })
  if (first.ok) return first.payload.key

  const complainedAboutParent = JSON.stringify(first.payload ?? {}).toLowerCase().includes('parent')
  if (complainedAboutParent && ctx.epicLinkField) {
    warn(`parent rejected for ${story.id}; retrying via ${ctx.epicLinkField}`)
    const { payload } = await jira('POST', '/rest/api/3/issue', {
      fields: { ...fields, [ctx.epicLinkField]: epicKey },
    })
    return payload.key
  }

  throw new Error(
    `Could not create ${story.id} "${story.summary}":\n${describeJiraError(first.payload, '')}`
  )
}

function buildLabels(story) {
  const labels = [...(story.labels ?? [])]
  if (story.sprint) labels.push(`sprint-${story.sprint}`)
  // Jira rejects labels containing whitespace.
  return labels.map((label) => label.replace(/\s+/g, '-'))
}

// ---------------------------------------------------------------- run

async function main() {
  CONFIG = loadEnv()
  const backlog = JSON.parse(readFileSync(BACKLOG_FILE, 'utf8'))
  CONFIG.JIRA_PROJECT_KEY = CONFIG.JIRA_PROJECT_KEY || backlog.projectKey

  const totalStories = backlog.epics.reduce((sum, epic) => sum + epic.stories.length, 0)
  const totalPoints = backlog.epics.reduce(
    (sum, epic) => sum + epic.stories.reduce((s, story) => s + (story.points ?? 0), 0),
    0
  )

  console.log(`\n${backlog.meta.product} — Jira backlog`)
  console.log(`${backlog.epics.length} epics · ${totalStories} stories · ${totalPoints} points\n`)

  if (DRY_RUN) return dryRun(backlog)
  if (SYNC_POINTS) return syncPoints(backlog)

  const ctx = await discoverProject(CONFIG.JIRA_PROJECT_KEY)
  const existing = await fetchExistingSummaries(CONFIG.JIRA_PROJECT_KEY)
  const state = existsSync(STATE_FILE) ? JSON.parse(readFileSync(STATE_FILE, 'utf8')) : {}
  info(`${existing.size} issues already in ${CONFIG.JIRA_PROJECT_KEY}\n`)

  const tally = { created: 0, skipped: 0 }
  const persist = () => writeFileSync(STATE_FILE, `${JSON.stringify(state, null, 2)}\n`)

  try {
    for (const epic of backlog.epics) {
      let epicKey = state[epic.id] ?? existing.get(epic.summary)
      if (epicKey) {
        console.log(`= ${epic.id} ${epicKey} ${epic.summary} (exists)`)
        tally.skipped++
      } else {
        epicKey = await createEpic(epic, ctx)
        state[epic.id] = epicKey
        persist()
        console.log(`+ ${epic.id} ${epicKey} ${epic.summary}`)
        tally.created++
        await sleep(THROTTLE_MS)
      }

      for (const story of epic.stories) {
        const known = state[story.id] ?? existing.get(story.summary)
        if (known) {
          console.log(`  = ${story.id} ${known} (exists)`)
          tally.skipped++
          continue
        }
        const key = await createStory(story, epicKey, ctx)
        state[story.id] = key
        persist()
        console.log(`  + ${story.id} ${key} ${story.summary} [${story.points}p]`)
        tally.created++
        await sleep(THROTTLE_MS)
      }
    }
  } finally {
    persist()
  }

  console.log(`\nDone — ${tally.created} created, ${tally.skipped} skipped.`)
  console.log(`State written to ${STATE_FILE}`)
  console.log(`Board: ${CONFIG.JIRA_BASE_URL}/jira/software/projects/${CONFIG.JIRA_PROJECT_KEY}/boards/34\n`)
}

function dryRun(backlog) {
  for (const epic of backlog.epics) {
    const points = epic.stories.reduce((sum, story) => sum + (story.points ?? 0), 0)
    console.log(`EPIC ${epic.id}  ${epic.summary}  (${epic.stories.length} stories, ${points}p)`)
    for (const story of epic.stories) {
      console.log(
        `   ${story.id.padEnd(7)} ${String(story.points).padStart(2)}p  s${story.sprint}  ` +
          `${story.summary}  [${buildLabels(story).join(', ')}]`
      )
      console.log(
        `           As a ${story.role}, I want ${story.want} so that ${story.soThat}.`
      )
      for (const criterion of story.acceptanceCriteria) console.log(`           - ${criterion}`)
    }
    console.log('')
  }
  console.log('Dry run — nothing was sent to Jira.\n')
}

// ---------------------------------------------------------------- helpers

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const info = (message) => console.log(`  ${message}`)
const warn = (message) => console.warn(`  ! ${message}`)
const safeJson = (text) => {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}
function fail(message) {
  console.error(`\nError: ${message}\n`)
  process.exit(1)
}

main().catch((error) => fail(error.message))
