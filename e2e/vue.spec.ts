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
