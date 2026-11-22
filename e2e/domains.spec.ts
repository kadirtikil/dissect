import { test, expect } from '@playwright/test'
import type { DomainsFile } from '../resources/js/types/domains'
import type { ClassesFile } from '../resources/js/types/classes'
import type { BoundariesFile } from '../resources/js/types/boundaries'

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

/** The class index for SPLIT, plus a Sales domain the domain list need not name. */
const CLASSES: ClassesFile = {
  classes: [
    {
      id: 'App\\Domain\\Billing\\Models\\Invoice',
      kind: 'class',
      abstract: false,
      file: 'app/Domain/Billing/Models/Invoice.php',
      line: 10,
      context: 'Billing',
      uses: [
        { to: 'App\\Domain\\Sales\\Models\\Customer', kind: 'relation', lines: [15] },
        { to: 'App\\Domain\\Billing\\Jobs\\SendInvoice', kind: 'dispatch', lines: [30] },
      ],
    },
    {
      id: 'App\\Domain\\Billing\\Jobs\\SendInvoice',
      kind: 'class',
      abstract: false,
      file: 'app/Domain/Billing/Jobs/SendInvoice.php',
      line: 8,
      context: 'Billing',
      uses: [{ to: 'App\\Domain\\Sales\\Models\\Customer', kind: 'type', lines: [12, 14] }],
    },
    {
      id: 'App\\Domain\\Sales\\Models\\Customer',
      kind: 'class',
      abstract: false,
      file: 'app/Domain/Sales/Models/Customer.php',
      line: 9,
      context: 'Sales',
      uses: [{ to: 'App\\Domain\\Billing\\Models\\Invoice', kind: 'relation', lines: [19] }],
    },
    {
      id: 'App\\Http\\Controllers\\Auth\\LoginController',
      kind: 'class',
      abstract: false,
      file: 'app/Http/Controllers/Auth/LoginController.php',
      line: 7,
      context: 'Identity',
      uses: [{ to: 'App\\Support\\Clock', kind: 'static', lines: [20] }],
    },
    {
      id: 'App\\Support\\Clock',
      kind: 'class',
      abstract: false,
      file: 'app/Support/Clock.php',
      line: 5,
      context: null,
      uses: [],
    },
  ],
  unreadable: [],
}

const WITH_SALES: DomainsFile = {
  ...SPLIT,
  domains: [
    ...SPLIT.domains,
    {
      name: 'Sales',
      sources: ['domain-folder'],
      roots: ['app/Domain/Sales'],
      classes: ['App\\Domain\\Sales\\Models\\Customer'],
    },
  ],
}

test('says which domains each one depends on, and lists the references behind it', async ({
  page,
}) => {
  await page.route('**/classes.json', (route) => route.fulfill({ json: CLASSES }))
  await open(page, WITH_SALES)

  await expect(page.getByText('5 classes read')).toBeVisible()

  const billing = page.locator('[data-domain="Billing"]')
  const dependsOn = billing.locator('[data-direction="depends-on"]')
  const usedBy = billing.locator('[data-direction="used-by"]')

  // One reference per class pair and kind; the job Invoice dispatches stays
  // inside Billing and does not count.
  await expect(dependsOn.locator('[data-link="Sales"]')).toHaveText(/Sales\s*2/)
  await expect(usedBy.locator('[data-link="Sales"]')).toHaveText(/Sales\s*1/)
  await expect(dependsOn.getByLabel('names this domain back')).toBeVisible()

  const references = billing.getByTestId('link-references')

  await expect(references).toHaveCount(0)
  await dependsOn.locator('[data-link="Sales"]').click()
  await expect(references.getByText('relation 1 · type 1')).toBeVisible()
  await expect(references.getByText('app/Domain/Billing/Models/Invoice.php:15')).toBeVisible()
  await expect(references.getByText('app/Domain/Billing/Jobs/SendInvoice.php:12, 14')).toBeVisible()

  // The same chip again folds it; the other direction opens its own.
  await dependsOn.locator('[data-link="Sales"]').click()
  await expect(references).toHaveCount(0)
  await usedBy.locator('[data-link="Sales"]').click()
  await expect(references.getByText('app/Domain/Sales/Models/Customer.php:19')).toBeVisible()

  // A reference to a class in no domain is not a dependency.
  await expect(page.locator('[data-domain="Identity"]').getByTestId('domain-links')).toHaveText(
    /No class here names another domain's/,
  )
})

test('an app with no domains never asks for the class index', async ({ page }) => {
  let asked = false
  await page.route(/\/(classes|boundaries)\.json/, (route) => {
    asked = true
    return route.fulfill({ json: CLASSES })
  })

  await open(page)
  await expect(page.getByTestId('domains-empty')).toBeVisible()
  expect(asked).toBe(false)
})

test('lists domains without dependencies when the class index will not load', async ({ page }) => {
  await page.route('**/classes.json', (route) => route.fulfill({ status: 500, body: 'boom' }))
  await open(page, WITH_SALES)

  await expect(page.getByTestId('classes-status')).toHaveText(/Could not load classes\.json/)
  await expect(page.locator('[data-domain="Billing"]')).toBeVisible()
  await expect(page.getByTestId('domain-links')).toHaveCount(0)
})

/** Sales may use nothing; Billing may use Sales and must keep requests out. */
const BOUNDARIES: BoundariesFile = {
  enabled: true,
  rules: [
    { domain: 'Billing', may_use: ['Sales'], must_not_use: ['Illuminate\\Http'] },
    { domain: 'Sales', may_use: [], must_not_use: [] },
  ],
  violations: [
    {
      rule: 'may_use',
      domain: 'Sales',
      from: 'App\\Domain\\Sales\\Models\\Customer',
      file: 'app/Domain/Sales/Models/Customer.php',
      to: 'App\\Domain\\Billing\\Models\\Invoice',
      to_domain: 'Billing',
      namespace: null,
      kind: 'relation',
      lines: [19],
      message: 'Sales may not use Billing: Customer → Invoice (relation, line 19).',
    },
  ],
  problems: [],
  unchecked: [],
}

async function openWithRules(page: import('@playwright/test').Page, boundaries: BoundariesFile) {
  await page.route('**/classes.json', (route) => route.fulfill({ json: CLASSES }))
  await page.route('**/boundaries.json', (route) => route.fulfill({ json: boundaries }))
  await open(page, WITH_SALES)
}

test('shows each rule, the links that break one, and every violation', async ({ page }) => {
  await openWithRules(page, BOUNDARIES)

  await expect(page.getByText('1 violation', { exact: true }).first()).toBeVisible()

  const sales = page.locator('[data-domain="Sales"]')
  const billing = page.locator('[data-domain="Billing"]')

  await expect(sales.getByTestId('domain-rule')).toHaveText(/may use no other domain/)
  await expect(billing.getByTestId('domain-rule')).toHaveText(
    /may use Sales; must not use Illuminate\\Http/,
  )
  await expect(sales.getByTestId('domain-violations')).toHaveText('1 violation')
  await expect(billing.getByTestId('domain-violations')).toHaveCount(0)

  // The link is forbidden from both ends: Sales → Billing breaks Sales's rule,
  // so it is red under Sales's "Depends on" and Billing's "Used by". Billing
  // → Sales is allowed by Billing's rule.
  const salesOut = sales.locator('[data-direction="depends-on"] [data-link="Billing"]')

  await expect(salesOut).toHaveAttribute('data-forbidden', '')
  await expect(billing.locator('[data-direction="used-by"] [data-link="Sales"]')).toHaveAttribute(
    'data-forbidden',
    '',
  )
  await expect(
    billing.locator('[data-direction="depends-on"] [data-link="Sales"]'),
  ).not.toHaveAttribute('data-forbidden')

  await salesOut.click()
  await expect(sales.getByTestId('link-rule')).toHaveText(
    "Every reference here breaks Sales's rule: may use no other domain",
  )

  const broken = page.getByTestId('domain-broken-rules').locator('[data-broken="Sales"]')

  await expect(broken.getByText('may not use Billing')).toBeVisible()
  await expect(broken.getByText('app/Domain/Sales/Models/Customer.php:19')).toBeVisible()
})

test('says the rules hold when nothing breaks them', async ({ page }) => {
  await openWithRules(page, { ...BOUNDARIES, violations: [] })

  await expect(page.getByText('2 rules kept')).toBeVisible()
  await expect(page.getByTestId('domain-broken-rules')).toHaveCount(0)
  await expect(page.locator('[data-forbidden]').first()).toBeVisible()
})

test('lists a rule naming a domain nothing defines with the other domains.json problems', async ({
  page,
}) => {
  await openWithRules(page, {
    ...BOUNDARIES,
    problems: ['rules.Sales.may_use: no domain is called Biling.'],
  })

  await expect(
    page.getByTestId('domain-problems').getByText('no domain is called Biling'),
  ).toBeVisible()
})

test('lists domains without rules when boundaries.json will not load', async ({ page }) => {
  await page.route('**/classes.json', (route) => route.fulfill({ json: CLASSES }))
  await page.route('**/boundaries.json', (route) => route.fulfill({ status: 500, body: 'boom' }))
  await open(page, WITH_SALES)

  await expect(page.getByTestId('boundaries-status')).toHaveText(/Could not load boundaries\.json/)
  await expect(page.getByTestId('domain-rule')).toHaveCount(0)
  await expect(page.locator('[data-domain="Billing"] [data-link]').first()).toBeVisible()
})
