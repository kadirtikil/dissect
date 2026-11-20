import { readFileSync } from 'node:fs'
import { test, expect, type Page } from '@playwright/test'

/**
 * The domain chips on the route, job and provider lists.
 *
 * The public/ fixtures are a plain app with no domains, which is what the first
 * test checks. The rest serve the same payloads with a domain on each row, as
 * an app split into Documents and Workspaces would have them.
 */

type Row = { class?: string | null; action?: { class: string | null }; context?: string | null }

/** Documents and Workspaces by class name; everything else in no domain. */
function withContexts(file: string, key: string): Record<string, unknown> {
  const payload = JSON.parse(readFileSync(`public/${file}`, 'utf8')) as Record<string, Row[]>

  payload[key] = payload[key]!.map((row) => {
    const name = row.action?.class ?? row.class ?? ''
    const context = name.startsWith('App\\')
      ? /Document/.test(name)
        ? 'Documents'
        : /Workspace/.test(name)
          ? 'Workspaces'
          : null
      : null

    return { ...row, context }
  })

  return payload
}

async function split(page: Page) {
  await page.route('**/routes.json', (route) =>
    route.fulfill({ json: withContexts('routes.json', 'routes') }),
  )
  await page.route('**/jobs.json', (route) =>
    route.fulfill({ json: withContexts('jobs.json', 'jobs') }),
  )
  await page.route('**/providers.json', (route) =>
    route.fulfill({ json: withContexts('providers.json', 'providers') }),
  )
}

async function open(page: Page, surface: string) {
  await page.goto('/')
  await page.getByRole('button', { name: surface, exact: true }).click()

  // Fetched when the surface opens, so wait for the list before counting it.
  await expect(page.locator('li button').first()).toBeVisible()
}

const facet = (page: Page) => page.getByTestId('domain-facet')

test('a plain app shows no domain chips anywhere', async ({ page }) => {
  for (const surface of ['Routes', 'Jobs', 'Provider-Trees']) {
    await open(page, surface)
    await expect(facet(page)).toHaveCount(0)
  }
})

test('narrows the route list to a domain, or to the routes in none', async ({ page }) => {
  await split(page)
  await open(page, 'Routes')

  const rows = page.locator('li button')
  const total = await rows.count()

  await expect(facet(page).getByRole('button', { name: /Documents\s*4/ })).toBeVisible()
  await expect(facet(page).getByRole('button', { name: /Workspaces\s*3/ })).toBeVisible()
  await expect(facet(page).getByRole('button', { name: /unassigned\s*7/ })).toBeVisible()

  await facet(page)
    .getByRole('button', { name: /Documents/ })
    .click()
  await expect(rows).toHaveCount(4)
  await expect(facet(page).getByRole('button', { name: /Documents/ })).toHaveAttribute(
    'aria-pressed',
    'true',
  )

  // Chips in one facet widen it; the other facets count within it.
  await facet(page)
    .getByRole('button', { name: /unassigned/ })
    .click()
  await expect(rows).toHaveCount(11)

  await page.getByLabel('Clear all filters').click()
  await expect(rows).toHaveCount(total)
})

test('narrows the job list and keeps the other counts honest', async ({ page }) => {
  await split(page)
  await open(page, 'Jobs')

  await facet(page)
    .getByRole('button', { name: /Workspaces/ })
    .click()

  await expect(page.locator('li button')).toHaveCount(1)
  await expect(page.locator('li button')).toContainText('SyncWorkspaceSeats')
  // Its own selection is left out of its counts, so the others still say
  // how much is behind them.
  await expect(facet(page).getByRole('button', { name: /Documents\s*2/ })).toBeVisible()
})

test('narrows the provider list by the provider’s own domain', async ({ page }) => {
  await split(page)
  await open(page, 'Provider-Trees')

  await facet(page)
    .getByRole('button', { name: /Documents/ })
    .click()

  await expect(page.locator('li button')).toHaveCount(1)
  await expect(page.locator('li button')).toContainText('DocumentServiceProvider')
  await expect(page.locator('header').getByText('1 of 4 providers')).toBeVisible()
})

test('colours domains in the order the page was given them', async ({ page }) => {
  // The package hands the page every domain name, so a list colours them the
  // same way as the domain list, even when it has rows in only some.
  await page.addInitScript(() => {
    ;(window as unknown as { __DISSECT__: unknown }).__DISSECT__ = {
      domains: ['Accounts', 'Workspaces', 'Documents'],
    }
  })
  await split(page)
  await open(page, 'Routes')

  const dot = (name: string) =>
    facet(page)
      .getByRole('button', { name: new RegExp(name) })
      .locator('[aria-hidden]')

  await expect(dot('Workspaces')).toHaveAttribute('style', /var\(--chart-2\)/)
  await expect(dot('Documents')).toHaveAttribute('style', /var\(--chart-3\)/)
})
