import { readFileSync } from 'node:fs'
import { test, expect, type Page } from '@playwright/test'
import type { Schema } from '../resources/js/types/schema'

/**
 * Domains on the graph: each card says its domain, a relation into another
 * domain is drawn apart, and every domain is a view of its own.
 *
 * public/schema.json is a plain app with no domains, which is what the first
 * test checks. The rest serve it with a domain on each model, as an app split
 * into Documents and Workspaces would have them.
 */

const DOCUMENTS = ['Document', 'DocumentType', 'Folder', 'Tag', 'Version', 'VersionReview']
const WORKSPACES = ['Workspace', 'WorkspaceMembership', 'Invitation', 'Team']

function contextOf(id: string): string | null {
  const name = id.replace('App\\Models\\', '')
  if (DOCUMENTS.includes(name)) return 'Documents'
  if (WORKSPACES.includes(name)) return 'Workspaces'
  return null
}

function splitSchema(): Schema {
  const schema = JSON.parse(readFileSync('public/schema.json', 'utf8')) as Schema

  return { ...schema, nodes: schema.nodes.map((n) => ({ ...n, context: contextOf(n.id) })) }
}

/** Relations between two different domains, worked out from the payload. */
function crossings(schema: Schema): number {
  const contexts = new Map(schema.nodes.map((n) => [n.id, n.context ?? null]))

  return schema.edges.filter((e) => {
    const from = contexts.get(e.source)
    const to = contexts.get(e.target)
    return from && to && from !== to
  }).length
}

async function openGraph(page: Page, schema?: Schema) {
  if (schema) {
    await page.route('**/schema.json', (route) => route.fulfill({ json: schema }))
  }

  await page.goto('/')
  await page.getByRole('button', { name: 'Models', exact: true }).click()
  await expect(page.locator('.vue-flow__node').first()).toBeVisible()
}

test('a plain app shows no domains on the graph', async ({ page }) => {
  await openGraph(page)

  await expect(page.getByTestId('model-domain')).toHaveCount(0)
  await expect(page.getByTestId('legend-crosses')).toHaveCount(0)
  await expect(page.locator('.vue-flow__edge.crosses-domain')).toHaveCount(0)

  await page.getByRole('button', { name: /^View:/ }).click()
  await expect(page.locator('[data-domain-view]')).toHaveCount(0)
})

test('each card says its domain, and crossing relations say where they go', async ({ page }) => {
  const schema = splitSchema()
  await openGraph(page, schema)

  await expect(page.getByTestId('model-domain')).toHaveCount(DOCUMENTS.length + WORKSPACES.length)
  await expect(page.getByTestId('model-domain').filter({ hasText: 'Workspaces' })).toHaveCount(
    WORKSPACES.length,
  )

  const crossing = page.locator('.vue-flow__edge.crosses-domain')
  const expected = crossings(schema)

  // The fixture has to have some for this test to mean anything.
  expect(expected).toBeGreaterThan(0)
  await expect(crossing).toHaveCount(expected)
  await expect(crossing.first().locator('.vue-flow__edge-text')).toContainText(
    /→ (Documents|Workspaces)/,
  )
  await expect(page.getByTestId('legend-crosses')).toBeVisible()
})

test('every domain is a read-only view that can be copied', async ({ page }) => {
  let saved: unknown = null

  // Answer the save here, so the test never writes public/views.json.
  await page.route('**/__views', async (route) => {
    saved = route.request().postDataJSON()
    await route.fulfill({ json: { revision: null } })
  })

  await openGraph(page, splitSchema())
  await page.getByRole('button', { name: /^View:/ }).click()

  await page.locator('[data-domain-view="Workspaces"]').click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(WORKSPACES.length)
  await expect(page.getByRole('button', { name: 'View: Workspaces' })).toBeVisible()

  // Its members are its folders', so the list cannot change them.
  const rows = page.getByRole('menuitemcheckbox')
  await expect(rows.first()).toBeDisabled()

  await page.getByRole('button', { name: 'Copy to a saved view' }).click()

  // Now an ordinary saved view, with a delete button and an editable list.
  await expect(page.getByRole('button', { name: 'Delete Workspaces' })).toBeVisible()
  await expect(rows.first()).toBeEnabled()
  await expect(page.locator('.vue-flow__node')).toHaveCount(WORKSPACES.length)

  expect(saved).toMatchObject({
    views: [{ id: 'workspaces', name: 'Workspaces' }],
  })
  const [view] = (saved as { views: { models: string[] }[] }).views
  expect(view!.models.sort()).toEqual(WORKSPACES.map((m) => `App\\Models\\${m}`).sort())
})

test('an open domain view survives a reload', async ({ page }) => {
  // Views load before the graph a domain's view is derived from, so the
  // remembered one must not be thrown away for not existing yet.
  await openGraph(page, splitSchema())
  await page.getByRole('button', { name: /^View:/ }).click()
  await page.locator('[data-domain-view="Documents"]').click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(DOCUMENTS.length)

  await page.reload()
  await page.getByRole('button', { name: 'Models', exact: true }).click()

  await expect(page.getByRole('button', { name: 'View: Documents' })).toBeVisible()
  await expect(page.locator('.vue-flow__node')).toHaveCount(DOCUMENTS.length)
})
