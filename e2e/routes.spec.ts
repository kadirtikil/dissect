import { test, expect } from '@playwright/test'

/**
 * The endpoint surface, against public/routes.json.
 *
 * Endpoints stand on their own here: the fixture carries no model ids, and the
 * thing worth testing is that the list describes each endpoint's own contract
 * and never narrows itself by anything chosen on another surface.
 */

test('opens the endpoint list on demand and groups it by controller', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await expect(page.getByText(/\d+ of \d+ endpoints/)).toBeVisible()
  await expect(page.getByRole('button', { name: /DocumentController/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /WorkspaceController/ })).toBeVisible()
})

test('narrows the list by search and by facet, and clears again', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  const rows = page.locator('li button')
  // The list is fetched when the surface opens, so counting before it lands
  // would compare everything against zero.
  await expect(rows.first()).toBeVisible()
  const total = await rows.count()

  await page.getByLabel('Filter endpoints').fill('workspaces')
  await expect(rows).toHaveCount(3)

  await page.getByLabel('Filter endpoints').fill('')
  // A bare verb filters by verb rather than by text.
  await page.getByRole('button', { name: /^DELETE/ }).first().click()
  await expect(rows).toHaveCount(1)

  await page.getByLabel('Clear all filters').click()
  await expect(rows).toHaveCount(total)
})

test('describes an endpoint down to the shape of its payloads', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await page.locator('li button', { hasText: 'api/documents' }).filter({ hasText: 'POST' }).click()

  await expect(page.getByText('documents.store')).toBeVisible()
  await expect(page.getByText('App\\Http\\Requests\\StoreDocumentRequest')).toBeVisible()

  // A nested rule path arrives in the same `[]` grammar the response uses.
  await expect(page.getByText('tags[].name')).toBeVisible()
  // A rule naming a table is shown as written, not resolved to a model.
  await expect(page.getByText('exists:document_types,id')).toBeVisible()
})

test('admits when a shape was inferred rather than read from the framework', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  await page.locator('li button', { hasText: 'workspaces' }).filter({ hasText: 'POST' }).click()

  // A confidently wrong endpoint description is worse than one that says so.
  await expect(page.getByText(/inferred —/)).toBeVisible()
  // A redirect has no payload; that is an answer, not a failure to look.
  await expect(page.getByText(/Redirects — no JSON body/)).toBeVisible()
})

test('shows every endpoint, and offers no way back into the graph', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('button', { name: 'Routes', exact: true }).click()

  // The whole table. Nothing outside this surface narrows it: which models
  // somebody grouped into a view says nothing about which endpoints they want
  // to read, and a route going missing because of a choice made elsewhere is
  // the one failure an endpoint list must not have.
  //
  // That a saved view no longer scopes this list is covered in the filter unit
  // tests rather than here — building a view writes public/views.json, which
  // the graph specs also write, and they run in a separate worker.
  await expect(page.getByText('9 of 9 endpoints')).toBeVisible()
  await expect(page.getByRole('button', { name: /^in “/ })).toHaveCount(0)

  // No row advertises models, and no endpoint links back to one.
  await expect(page.locator('li button', { hasText: '⬡' })).toHaveCount(0)

  await page.locator('li button', { hasText: 'api/documents/{document}' }).filter({ hasText: 'GET' }).click()
  await expect(page.locator('section', { hasText: 'TOUCHES' })).toHaveCount(0)
  // The bound {document} is described as a placeholder, not as a model.
  await expect(page.getByText('bound to')).toHaveCount(0)
})

test('remembers which surface was open across a reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Routes', exact: true }).click()
  await expect(page.getByText(/\d+ of \d+ endpoints/)).toBeVisible()

  await page.reload()

  // Per browser, deliberately not in a committed file — the same call the
  // active saved view makes.
  await expect(page.getByRole('button', { name: 'Routes', exact: true })).toHaveAttribute('aria-current', 'page')

  await page.getByRole('button', { name: 'Models', exact: true }).click()
})
