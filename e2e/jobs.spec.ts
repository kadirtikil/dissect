import { test, expect } from '@playwright/test'

/**
 * The queue surface, against public/jobs.json.
 *
 * Its model ids match public/schema.json's and its route ids match
 * public/routes.json's, because the thing worth testing here is not the list —
 * it is that a job, an endpoint and the graph all address the same things.
 */

test('opens the job list on demand and groups it by queue', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('button', { name: 'Jobs', exact: true }).click()

  await expect(page.getByText(/\d+ of \d+ jobs/)).toBeVisible()

  // The queue is the unit a worker is configured in, so it is the unit the list
  // is bucketed by. Scoped to the collapsible headings, since a queue name is
  // also a facet chip.
  const headings = page.locator('button[aria-expanded]')

  await expect(headings.filter({ hasText: 'indexing' })).toBeVisible()
  await expect(headings.filter({ hasText: 'mail' })).toBeVisible()
})

test('narrows the list by search and by facet, and clears again', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Jobs', exact: true }).click()

  const rows = page.locator('li button')
  // The list is fetched when the surface opens, so counting before it lands
  // would compare everything against zero.
  await expect(rows.first()).toBeVisible()
  const total = await rows.count()

  await page.getByLabel('Filter jobs').fill('thumbnail')
  await expect(rows).toHaveCount(1)

  await page.getByLabel('Filter jobs').fill('')
  await page.getByRole('button', { name: /^Listener/ }).first().click()
  await expect(rows).toHaveCount(1)

  await page.getByLabel('Clear all filters').click()
  await expect(rows).toHaveCount(total)
})

test('surfaces the queueables nothing was found to dispatch', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Jobs', exact: true }).click()

  // The finding no other tool reports, said in the page header before anybody
  // touches a filter.
  await expect(page.locator('header').getByText(/\d+ undispatched/)).toBeVisible()

  await page.getByRole('button', { name: /^undispatched/ }).click()
  await page.locator('li button', { hasText: 'PurgeDeletedDocuments' }).click()

  // "None found" rather than "never dispatched": a dispatch behind a variable
  // names a class only the running application knows.
  await expect(page.getByText(/No dispatch site found/)).toBeVisible()
})

test('says where a queue name came from, and refuses to pick between two', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Jobs', exact: true }).click()

  await page.locator('li button', { hasText: 'ExtractDocumentText' }).click()
  // A job using Queueable cannot declare a $queue property, so most queue names
  // were read from somewhere other than the class's own field.
  await expect(page.getByText('set in the constructor')).toBeVisible()

  await page.locator('li button', { hasText: 'SyncWorkspaceSeats' }).click()
  await expect(page.getByText('dispatched onto more than one queue')).toBeVisible()
})

test('follows a job to the endpoint that dispatches it', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Jobs', exact: true }).click()

  await page.locator('li button', { hasText: 'ExtractDocumentText' }).click()
  await page.getByRole('button', { name: 'POST:api/documents' }).click()

  // The other direction of the link the routes surface already offers: what an
  // endpoint does after it has answered is half of what the endpoint does.
  await expect(page.getByRole('button', { name: 'Routes', exact: true })).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(page.getByText('documents.store')).toBeVisible()
})

test('follows a job to the model it carries, and back again', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  await page.getByRole('button', { name: 'Jobs', exact: true }).click()
  await page.locator('li button', { hasText: 'RenderThumbnail' }).click()

  await page
    .locator('section', { hasText: 'CARRIES' })
    // Exact, because the payload row above it is named "carries Variant" and
    // hasText matches case-insensitively.
    .getByRole('button', { name: 'Variant', exact: true })
    .click()

  await expect(page.getByRole('button', { name: 'Models', exact: true })).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(page.locator('.model-node.is-highlighted')).toContainText('Variant')

  // And the reverse link, off an expanded card. The jump above zoomed to one
  // node, so the rest of the board is off screen until the viewport is refit.
  await page.locator('.vue-flow__controls-fitview').click()

  const card = page.locator('.model-node', { hasText: 'documents' }).first()
  await card.click()
  await card.getByRole('button', { name: /Show jobs carrying Document/ }).click()

  await expect(page.getByRole('button', { name: 'Jobs', exact: true })).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(page.getByRole('button', { name: /Stop filtering to Document/ })).toBeVisible()
  await expect(page.locator('li button')).toHaveCount(1)
})
