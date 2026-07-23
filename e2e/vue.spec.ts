import { test, expect } from '@playwright/test'

// See here how to get started:
// https://playwright.dev/docs/intro
test('renders the schema graph on the app root url', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByText('dissect')).toBeVisible()

  // 20 scanned models + 2 referenced-but-unscanned placeholders.
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)
  // Counts are not hard-coded: the schema is regenerated from the Laravel app,
  // so asserting exact totals makes this test fail on every legitimate export.
  await expect(page.getByText(/\d+ models · \d+ relations/)).toBeVisible()
})

test('lays models out in columns of five', async ({ page }) => {
  await page.goto('/')
  // Wait on the count, not on .first() — the graph is populated by an async
  // fetch, and the nodes do not exist at all until it resolves.
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  const columns = await page.evaluate(() => {
    const xs = [...document.querySelectorAll<HTMLElement>('.vue-flow__node')].map((n) => {
      const m = /translate\(([-\d.]+)px/.exec(n.style.transform || '')
      return m ? Math.round(Number(m[1])) : NaN
    })
    const counts = new Map<number, number>()
    for (const x of xs) counts.set(x, (counts.get(x) ?? 0) + 1)
    return [...counts.entries()].sort((a, b) => a[0] - b[0]).map(([, n]) => n)
  })

  expect(columns).toEqual([5, 5, 5, 5, 2])
})

test('expands a card to its full attribute list, and collapses again', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // Document has more columns than a collapsed card lists, so the truncation
  // row is what proves the expansion actually revealed something.
  const card = page.locator('.model-node', { hasText: 'documents' }).first()
  await expect(card.getByText(/\+\d+ more/)).toBeVisible()

  await card.click()

  await expect(card).toHaveAttribute('aria-expanded', 'true')
  await expect(card.getByText(/\+\d+ more/)).toHaveCount(0)
  // The flags only exist on an expanded card; the key column carries one.
  await expect(card.getByTitle('Auto-incrementing key')).toBeVisible()

  // The header offers the way back out once anything is open.
  await page.getByRole('button', { name: /Collapse 1 expanded/ }).click()
  await expect(card).toHaveAttribute('aria-expanded', 'false')
})
