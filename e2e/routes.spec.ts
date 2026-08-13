import { test, expect } from '@playwright/test'

/**
 * The endpoint surface, against public/routes.json.
 *
 * Its model ids deliberately match public/schema.json's, because the thing
 * worth testing here is not the list — it is that an endpoint and the graph
 * address the same models.
 */

test('opens the endpoint list on demand and groups it by controller', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('tab', { name: 'routes' }).click()

  await expect(page.getByText(/\d+ of \d+ endpoints/)).toBeVisible()
  await expect(page.getByRole('button', { name: /DocumentController/ })).toBeVisible()
  await expect(page.getByRole('button', { name: /WorkspaceController/ })).toBeVisible()
})

test('narrows the list by search and by facet, and clears again', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('tab', { name: 'routes' }).click()

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

test('describes an endpoint down to the column each field comes from', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('tab', { name: 'routes' }).click()

  await page.locator('li button', { hasText: 'api/documents' }).filter({ hasText: 'POST' }).click()

  await expect(page.getByText('documents.store')).toBeVisible()
  await expect(page.getByText('App\\Http\\Requests\\StoreDocumentRequest')).toBeVisible()

  // A nested rule path arrives in the same `[]` grammar the response uses.
  await expect(page.getByText('tags[].name')).toBeVisible()
  // And a rule naming a table resolves to the model behind it.
  await expect(page.getByRole('button', { name: 'DocumentType.id' })).toBeVisible()
})

test('admits when a shape was inferred rather than read from the framework', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('tab', { name: 'routes' }).click()

  await page.locator('li button', { hasText: 'workspaces' }).filter({ hasText: 'POST' }).click()

  // A confidently wrong endpoint description is worse than one that says so.
  await expect(page.getByText(/inferred —/)).toBeVisible()
  // A redirect has no payload; that is an answer, not a failure to look.
  await expect(page.getByText(/Redirects — no JSON body/)).toBeVisible()
})

test('follows an endpoint to the model it touches, and back again', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('tab', { name: 'routes' }).click()
  await page.locator('li button', { hasText: 'api/documents/{document}' }).filter({ hasText: 'GET' }).click()

  await page.locator('section', { hasText: 'TOUCHES' }).getByRole('button', { name: 'Variant' }).click()

  // The jump switches surface as well as centring: landing on the canvas with
  // the model off screen is the same as not having followed anything.
  await expect(page.getByRole('tab', { name: 'models' })).toHaveAttribute('aria-selected', 'true')
  await expect(page.locator('.model-node.is-highlighted')).toHaveCount(1)
  await expect(page.locator('.model-node.is-highlighted')).toContainText('Variant')

  // And the reverse link, off an expanded card. The jump above zoomed to one
  // node, so the rest of the board is off screen until the viewport is refit.
  await page.locator('.vue-flow__controls-fitview').click()

  const card = page.locator('.model-node', { hasText: 'documents' }).first()
  await card.click()
  await card.getByRole('button', { name: /Show endpoints touching Document/ }).click()

  await expect(page.getByRole('tab', { name: 'routes' })).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByRole('button', { name: /Stop filtering to Document/ })).toBeVisible()
  await expect(page.locator('li button')).toHaveCount(4)
})

test('remembers which surface was open across a reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('tab', { name: 'routes' }).click()
  await expect(page.getByText(/\d+ of \d+ endpoints/)).toBeVisible()

  await page.reload()

  // Per browser, deliberately not in a committed file — the same call the
  // active saved view makes.
  await expect(page.getByRole('tab', { name: 'routes' })).toHaveAttribute('aria-selected', 'true')

  await page.getByRole('tab', { name: 'models' }).click()
})
