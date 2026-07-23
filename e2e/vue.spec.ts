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

test('saves a selection as a view, switches to it and deletes it', async ({ page }) => {
  await page.setViewportSize({ width: 1400, height: 900 })
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // Box-select the first grid column's top two models. Coordinates come from
  // the nodes themselves: the grid is stable, but hard-coding pixels would
  // make this fail on any change to node height.
  const first = (await page.locator('[data-id="AccessGrant"]').boundingBox())!
  const second = (await page.locator('[data-id="Activity"]').boundingBox())!

  await page.keyboard.down('Shift')
  // Starts in the empty gutter left of the column — and deliberately drags
  // across edges, which used to swallow the gesture.
  await page.mouse.move(first.x - 90, second.y + second.height + 10)
  await page.mouse.down()
  await page.mouse.move(first.x + first.width + 10, first.y - 10, { steps: 12 })
  await page.mouse.up()
  await page.keyboard.up('Shift')

  await expect(page.locator('.vue-flow__node.selected')).toHaveCount(2)
  // The multi-select gesture must not also expand the cards it touches.
  await expect(page.locator('.model-node.is-expanded')).toHaveCount(0)

  await page.getByRole('button', { name: /^View:/ }).click()
  await page.getByLabel('Name for the new view').fill('Grants')
  await page.getByRole('button', { name: 'Save' }).click()

  // The graph is now only the view's members, and the header says so.
  await expect(page.locator('.vue-flow__node')).toHaveCount(2)
  await expect(page.getByText('2 of 22 models')).toBeVisible()

  // Views live in a file, and which one is open is remembered per browser.
  await page.reload()
  await expect(page.locator('.vue-flow__node')).toHaveCount(2)

  await page.getByRole('button', { name: /^View: Grants/ }).click()
  await page.getByRole('menuitem', { name: 'All models' }).click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // Deleting is two-step, and leaves views.json as the suite found it. The menu
  // stays open across a switch, so there is nothing to reopen here.
  await page.getByRole('button', { name: 'Delete Grants' }).click()
  await page.getByRole('button', { name: 'Confirm deleting Grants' }).click()
  await expect(page.getByRole('menuitem', { name: /Grants/ })).toHaveCount(0)
})

test('builds a view from the model list and extends it afterwards', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // No canvas selection anywhere in this test: the list is the whole interface.
  await page.getByRole('button', { name: /^View:/ }).click()
  await page.getByLabel('Filter models').fill('user')
  await page.getByRole('menuitemcheckbox', { name: /^User / }).click()
  await page.getByLabel('Filter models').fill('team')
  await page.getByRole('menuitemcheckbox', { name: /^Team / }).click()
  await page.getByLabel('Name for the new view').fill('People')
  await page.getByRole('button', { name: 'Save' }).click()

  await expect(page.locator('.vue-flow__node')).toHaveCount(2)

  // The point of the list: adding a model the active view is currently hiding,
  // which by definition cannot be clicked on the canvas.
  await page.getByLabel('Filter models').fill('workspace')
  await page.getByRole('menuitemcheckbox', { name: /^Workspace / }).click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(3)
  await expect(page.getByText('3 of 22 models')).toBeVisible()

  // Ticking saves as it goes, so the addition outlives a reload.
  await page.reload()
  await expect(page.locator('.vue-flow__node')).toHaveCount(3)

  // And back out again — "temporarily" is add, look, remove.
  await page.getByRole('button', { name: /^View: People/ }).click()
  await page.getByLabel('Filter models').fill('workspace')
  await page.getByRole('menuitemcheckbox', { name: /^Workspace / }).click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(2)

  // The last member cannot be unticked: an empty view is dropped on write, so
  // that click would delete the view as a side effect of editing it.
  await page.getByLabel('Filter models').fill('team')
  await page.getByRole('menuitemcheckbox', { name: /^Team / }).click()
  await page.getByLabel('Filter models').fill('user')
  await expect(page.getByRole('menuitemcheckbox', { name: /^User / })).toBeDisabled()

  await page.getByRole('button', { name: 'Delete People' }).click()
  await page.getByRole('button', { name: 'Confirm deleting People' }).click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)
})
