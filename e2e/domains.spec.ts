import { test, expect } from '@playwright/test'
import type { DomainsFile } from '../resources/js/types/domains'

/**
 * The domain list.
 *
 * public/domains.json is the plain workbench app's, which has no domains, so
 * the first test is the one most apps will see. The rest serve a split app's
 * payload in its place.
 */

const SPLIT: DomainsFile = {
  enabled: true,
  domains: [
    {
      name: 'Billing',
      sources: ['domain-folder', 'attribute'],
      roots: ['app/Domain/Billing'],
      classes: ['App\\Domain\\Billing\\Models\\Invoice', 'App\\Domain\\Billing\\Jobs\\SendInvoice'],
    },
    {
      name: 'Identity',
      sources: ['config'],
      roots: ['app/Http/Controllers/Auth'],
      classes: ['App\\Http\\Controllers\\Auth\\LoginController'],
    },
  ],
  unassigned: ['App\\Support\\Clock'],
  findings: [
    {
      kind: 'override-disagrees',
      class: 'App\\Domain\\Sales\\TaxRate',
      file: 'app/Domain/Sales/TaxRate.php',
      context: 'Billing',
      folder: 'Sales',
      message: "#[Domain('Billing')] on a class in Sales's folder.",
    },
  ],
  problems: [],
}

async function open(page: import('@playwright/test').Page, payload?: DomainsFile) {
  if (payload) {
    await page.route('**/domains.json', (route) => route.fulfill({ json: payload }))
  }

  await page.goto('/')
  await page.getByRole('button', { name: 'Domains', exact: true }).click()
}

test('an app with no domains says so and lists what would count', async ({ page }) => {
  await open(page)

  const empty = page.getByTestId('domains-empty')

  await expect(empty.getByText('No domains found')).toBeVisible()
  await expect(empty.getByText('app/Domain/{Name}')).toBeVisible()
  await expect(page.locator('[data-domain]')).toHaveCount(0)
})

test('lists each domain with how it was found and its classes', async ({ page }) => {
  await open(page, SPLIT)

  await expect(page.getByText('2 domains · 1 unassigned · 1 to check')).toBeVisible()

  const billing = page.locator('[data-domain="Billing"]')

  await expect(billing.getByText('Domain/ folder')).toBeVisible()
  await expect(billing.getByText('#[Domain] attribute')).toBeVisible()
  await expect(billing.getByText('app/Domain/Billing')).toBeVisible()
  await expect(billing.getByText('2 classes')).toBeVisible()

  // Class lists stay folded until asked for: a real domain has hundreds.
  await expect(billing.getByText('SendInvoice')).toHaveCount(0)
  await billing.getByRole('button', { name: 'Show classes' }).click()
  await expect(billing.getByText('SendInvoice')).toBeVisible()
  await expect(billing.getByText('App\\Domain\\Billing\\Jobs')).toBeVisible()
})

test('shows stale overrides and unassigned classes', async ({ page }) => {
  await open(page, SPLIT)

  await expect(
    page.getByTestId('domain-findings').getByText("on a class in Sales's folder"),
  ).toBeVisible()

  const unassigned = page.getByTestId('domain-unassigned')

  await expect(unassigned.getByText('App\\Support\\Clock')).toHaveCount(0)
  await unassigned.getByRole('button', { name: /Unassigned/ }).click()
  await expect(unassigned.getByText('App\\Support\\Clock')).toBeVisible()
})

test('reports a broken domains.json above everything else', async ({ page }) => {
  await open(page, { ...SPLIT, problems: ['domains.json is not valid JSON: Syntax error.'] })

  await expect(page.getByTestId('domain-problems').getByText('not valid JSON')).toBeVisible()
  // The folders still count while the file is broken.
  await expect(page.locator('[data-domain="Billing"]')).toBeVisible()
})
