import { test, expect } from '@playwright/test'

/**
 * The provider trees, against public/providers.json.
 *
 * Same fictional application as the other fixtures — the contextual binding is
 * scoped to RenderThumbnail from public/jobs.json — with one provider per thing
 * this surface has to say out loud: a constructor chain that stops at the
 * framework, a contextual override beside its default, a deferred provider that
 * registers another, a tree that could not be read in full, and a provider that
 * is nothing but side effects.
 */

async function openProviders(page: import('@playwright/test').Page) {
  await page.goto('/')
  await page.getByRole('button', { name: 'Provider-Trees', exact: true }).click()

  // Fetched when the surface opens, so wait for the list before counting it.
  await expect(page.locator('li button').first()).toBeVisible()
}

test('opens the provider list on demand and asks for a selection', async ({ page }) => {
  await openProviders(page)

  await expect(page.locator('li button')).toHaveCount(4)
  await expect(page.locator('header').getByText('4 of 4 providers')).toBeVisible()
  await expect(page.getByText('Select a provider to see what it binds')).toBeVisible()
})

test('draws the selected provider as a tree, labels included', async ({ page }) => {
  await openProviders(page)

  await page.locator('li button', { hasText: 'DocumentServiceProvider' }).click()

  const tree = page.locator('.provider-tree')

  await expect(tree.locator('.vue-flow__node')).toHaveCount(8)
  await expect(page.locator('header').getByText('8 nodes · 8 edges')).toBeVisible()

  // The label is the only thing telling the override from the default beside
  // it, so it is drawn without hovering.
  await expect(tree.getByText('contextual · when RenderThumbnail').first()).toBeVisible()

  // The walk stops at framework code and says why the branch ends there.
  const framework = tree.locator('.vue-flow__node', { hasText: 'FilesystemManager' })
  await expect(framework).toContainText('framework')
  await expect(framework).toContainText('inferred')

  // A complete tree carries no warning.
  await expect(page.getByRole('status')).toHaveCount(0)
})

test('narrows the list by a class anywhere in a tree, and clears again', async ({ page }) => {
  await openProviders(page)

  const rows = page.locator('li button')

  // Nothing is called Meilisearch but the class SearchServiceProvider binds —
  // "which provider wires this up?" is the question the search is for.
  await page.getByLabel('Filter providers').fill('meilisearch')
  await expect(rows).toHaveCount(1)
  await expect(rows.first()).toContainText('SearchServiceProvider')

  await page.getByLabel('Filter providers').fill('nothing-is-called-this')
  await expect(page.getByText('No provider matches the current filter.')).toBeVisible()

  await page.getByLabel('Clear all filters').click()
  await expect(rows).toHaveCount(4)
})

test('says when a tree is partial or was cut short', async ({ page }) => {
  await openProviders(page)

  await page.locator('li button', { hasText: 'WorkspaceServiceProvider' }).click()

  const banner = page.getByRole('status')
  await expect(banner).toContainText('Not fully analysed')
  await expect(banner).toContainText('1 dependency could not be read statically')
  await expect(banner).toContainText('Cut short by the depth or node cap')

  await expect(
    page.locator('.provider-tree .vue-flow__node', { hasText: '$this->app->bind' }),
  ).toContainText('unknown')
})

test('shows side effects as badges and opens a doc block on click', async ({ page }) => {
  await openProviders(page)

  await page.locator('li button', { hasText: 'AppServiceProvider' }).click()

  const root = page.locator('.provider-tree .vue-flow__node').first()
  await expect(root).toContainText('gates ×3')
  await expect(root).toContainText('events')

  await page.locator('li button', { hasText: 'SearchServiceProvider' }).click()

  const card = page
    .locator('.provider-tree .vue-flow__node', { hasText: 'SearchServiceProvider' })
    .getByRole('button')

  await expect(card).toContainText('deferred')
  await card.click()
  await expect(card).toHaveAttribute('aria-expanded', 'true')
  await expect(card).toContainText('loaded only when something asks for the index')
  await expect(card).toContainText('provides: App\\Contracts\\SearchIndex')
})
