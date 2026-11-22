import { test, expect, type Page, type Route } from '@playwright/test'
import type { ChangesFile } from '../resources/js/types/changes'

/**
 * The Changes page.
 *
 * public/changes.json is a fresh app's: no baseline yet, which is what every
 * first visit sees. The rest serve a report in its place, and stand in for
 * the server's start and stop endpoints, which the dev server does not have.
 */

const BASELINES: ChangesFile['baselines'] = {
  watch: {
    exists: true,
    path: 'storage/dissect/watch.json',
    taken_at: new Date(Date.now() - 12 * 60_000).toISOString(),
  },
  snapshot: {
    exists: true,
    path: '.dissect/snapshot.json',
    taken_at: '2026-11-20T09:00:00+00:00',
  },
}

const REPORT: ChangesFile = {
  against: 'watch',
  baselines: BASELINES,
  problem: null,
  summary: { breaking: 1, risky: 1, additive: 1 },
  changes: [
    {
      part: 'models',
      id: 'App\\Models\\Invoice',
      name: 'Invoice',
      change: 'changed',
      severity: 'risky',
      text: '~ model Invoice',
      facts: [
        {
          path: ['columns', 'total', 'nullable'],
          change: 'changed',
          before: false,
          after: true,
          severity: 'risky',
          text: '~ column total: now nullable',
        },
      ],
    },
    {
      part: 'routes',
      id: 'DELETE:api/invoices/{invoice}',
      name: 'DELETE api/invoices/{invoice}',
      change: 'removed',
      severity: 'breaking',
      text: '- route DELETE api/invoices/{invoice}',
      facts: [],
    },
    {
      part: 'routes',
      id: 'POST:api/invoices/{invoice}/refund',
      name: 'POST api/invoices/{invoice}/refund',
      change: 'added',
      severity: 'additive',
      text: '+ route POST api/invoices/{invoice}/refund',
      facts: [],
    },
  ],
}

async function open(page: Page, serve?: (url: URL) => ChangesFile) {
  if (serve) {
    await page.route('**/changes.json*', (route: Route) =>
      route.fulfill({ json: serve(new URL(route.request().url())) }),
    )
  }

  await page.goto('/')
  await page.getByRole('button', { name: 'Changes', exact: true }).click()
}

test('a first visit explains both ways to get a baseline', async ({ page }) => {
  await open(page)

  const empty = page.getByTestId('changes-empty')

  await expect(empty.getByText('Nothing to compare with yet')).toBeVisible()
  await expect(empty.getByText('php artisan dissect:snapshot')).toBeVisible()
  await expect(page.getByTestId('start-watching')).toBeEnabled()
  await expect(page.getByRole('button', { name: /Since the snapshot/ })).toBeDisabled()
  await expect(page.getByTestId('changes-headline')).toHaveText('What changed')
})

test('changes are grouped by part, worst first, with their facts', async ({ page }) => {
  await open(page, () => REPORT)

  await expect(page.getByTestId('changes-headline')).toHaveText(
    '3 changes since watching started · 1 breaking',
  )
  await expect(page.locator('[data-part]')).toHaveCount(2)
  await expect(page.locator('[data-part="models"] h2')).toContainText('Models')

  const invoice = page.locator('[data-change="App\\\\Models\\\\Invoice"]')
  await expect(invoice).toContainText('column total: now nullable')
  await expect(invoice).toContainText('changed')

  // Something removed is on no page any more, so it cannot be opened.
  const gone = page.locator('[data-change="DELETE:api/invoices/{invoice}"]')
  await expect(gone).toContainText('gone')
  await expect(gone.getByRole('button', { name: /^Open/ })).toHaveCount(0)
  await expect(
    page.getByRole('button', { name: 'Open POST api/invoices/{invoice}/refund' }),
  ).toBeVisible()

  // Watching is on, so the switch shows it and its age.
  await expect(page.getByRole('button', { name: /Since watching started/ })).toHaveAttribute(
    'aria-pressed',
    'true',
  )
  await expect(page.getByRole('button', { name: /Since watching started/ })).toContainText(
    '12m ago',
  )
})

test('a severity can be hidden and shown again', async ({ page }) => {
  await open(page, () => REPORT)

  await page.locator('[data-severity="breaking"]').click()

  await expect(page.locator('[data-change]')).toHaveCount(2)
  await expect(page.locator('[data-severity="breaking"]')).toHaveAttribute('aria-pressed', 'false')

  await page.locator('[data-severity="risky"]').click()
  await page.locator('[data-severity="additive"]').click()

  await expect(page.getByText('Every change is hidden by the filter above.')).toBeVisible()

  await page.locator('[data-severity="breaking"]').click()
  await expect(page.locator('[data-change]')).toHaveCount(1)
})

test('switching to the snapshot asks for it', async ({ page }) => {
  await open(page, (url) =>
    url.searchParams.get('against') === 'snapshot'
      ? {
          ...REPORT,
          against: 'snapshot',
          summary: { breaking: 0, risky: 0, additive: 0 },
          changes: [],
        }
      : REPORT,
  )

  await page.getByRole('button', { name: /Since the snapshot/ }).click()

  await expect(page.getByTestId('changes-none')).toHaveText('Nothing changed since the snapshot.')
  await expect(page.getByRole('button', { name: /Since the snapshot/ })).toHaveAttribute(
    'aria-pressed',
    'true',
  )
})

test('starting and stopping watching', async ({ page }) => {
  let watching = false
  const methods: string[] = []

  await page.route('**/changes/watch', (route) => {
    methods.push(route.request().method())
    watching = route.request().method() === 'POST'

    return route.fulfill({ json: { ok: true } })
  })

  await open(page, () =>
    watching
      ? { ...REPORT, summary: { breaking: 0, risky: 0, additive: 0 }, changes: [] }
      : {
          ...REPORT,
          against: null,
          baselines: {
            ...BASELINES,
            watch: { ...BASELINES.watch, exists: false },
            snapshot: { ...BASELINES.snapshot, exists: false },
          },
          summary: { breaking: 0, risky: 0, additive: 0 },
          changes: [],
        },
  )

  await page.getByTestId('start-watching').click()

  await expect(page.getByTestId('changes-none')).toHaveText(
    'Nothing changed since watching started.',
  )
  await expect(page.getByTestId('stop-watching')).toBeVisible()

  await page.getByTestId('stop-watching').click()

  await expect(page.getByTestId('changes-empty')).toBeVisible()
  expect(methods).toEqual(['POST', 'DELETE'])
})

test("the server's reason is shown when watching cannot start", async ({ page }) => {
  await page.route('**/changes/watch', (route) =>
    route.fulfill({
      status: 500,
      json: { ok: false, message: 'Cannot write storage/dissect/watch.json.' },
    }),
  )

  await open(page)
  await page.getByTestId('start-watching').click()

  await expect(page.getByRole('alert')).toHaveText('Cannot write storage/dissect/watch.json.')
  await expect(page.getByTestId('changes-empty')).toBeVisible()
})

test('a baseline that cannot be read is said in words', async ({ page }) => {
  await open(page, () => ({
    ...REPORT,
    against: 'snapshot',
    problem:
      '.dissect/snapshot.json is in snapshot format 2, and this version of dissect reads format 1.',
    summary: { breaking: 0, risky: 0, additive: 0 },
    changes: [],
  }))

  await expect(page.getByTestId('changes-problem')).toContainText('snapshot format 2')
  await expect(page.locator('[data-change]')).toHaveCount(0)
})

test('opening a changed route lands on it', async ({ page }) => {
  await open(page, () => REPORT)

  await page.getByRole('button', { name: 'Open POST api/invoices/{invoice}/refund' }).click()

  await expect(page).toHaveURL(/#\/routes$/)
})
