import { test, expect, type Page } from '@playwright/test'
import { keepViewsInMemory } from './memoryViews'

/**
 * A graph node, by model. Node ids are full class names, and a backslash
 * inside a CSS attribute value has to be escaped.
 */
function node(page: Page, model: string) {
  const id = `App\\Models\\${model}`.replaceAll('\\', '\\\\')
  return page.locator(`.vue-flow__node[data-id="${id}"]`)
}

/**
 * The graph is no longer what the app opens on, so a test that works with it
 * has to ask for it.
 *
 * Counting nodes would pass either way — the canvas stays mounted behind the
 * overview, so its nodes are in the DOM throughout — but nothing on it can be
 * clicked or measured while it is hidden, which is the trap this avoids.
 */
async function openGraph(page: Page) {
  await page.goto('/')
  await page.getByRole('button', { name: 'Models', exact: true }).click()
}

// See here how to get started:
// https://playwright.dev/docs/intro
test('renders the schema graph on the app root url', async ({ page }) => {
  await openGraph(page)
  await expect(page.getByText('dissect')).toBeVisible()

  // 20 scanned models + 2 referenced-but-unscanned placeholders.
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)
  // Counts are not hard-coded: the schema is regenerated from the Laravel app,
  // so asserting exact totals makes this test fail on every legitimate export.
  await expect(page.getByText(/\d+ models · \d+ relations/)).toBeVisible()
})

test('lays models out in columns of five', async ({ page }) => {
  await openGraph(page)
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
  await openGraph(page)
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

test('a card works from the keyboard', async ({ page }) => {
  await openGraph(page)
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  const card = page.locator('.model-node', { hasText: 'documents' }).first()
  const wrapper = page.locator('.vue-flow__node', { has: card })

  // One tab stop per model: the card, not Vue Flow's wrapper around it too.
  await expect(wrapper).not.toHaveAttribute('tabindex')

  await card.focus()
  await page.keyboard.press('Enter')
  await expect(card).toHaveAttribute('aria-expanded', 'true')
  // Opening it is not selecting it.
  await expect(wrapper).not.toHaveClass(/selected/)

  // Enter on the link inside follows the link, and leaves the card open.
  await card.getByRole('button', { name: /Show jobs carrying Document/ }).focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('button', { name: 'Jobs', exact: true })).toHaveAttribute(
    'aria-current',
    'page',
  )
})

test('saves a selection as a view, switches to it and deletes it', async ({ page }) => {
  await keepViewsInMemory(page)
  await page.setViewportSize({ width: 1400, height: 900 })
  await openGraph(page)
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // Box-select the first grid column's top two models. Coordinates come from
  // the nodes themselves: the grid is stable, but hard-coding pixels would
  // make this fail on any change to node height.
  const first = (await node(page, 'AccessGrant').boundingBox())!
  const second = (await node(page, 'Activity').boundingBox())!

  // Clamped to the pane: the gutter left of the column is only canvas as far as
  // the sidebar, and a drag starting outside it never reaches Vue Flow at all.
  const pane = (await page.locator('.vue-flow__pane').boundingBox())!
  const gutterX = Math.max(first.x - 90, pane.x + 8)

  await page.keyboard.down('Shift')
  // Starts in the empty gutter left of the column — and deliberately drags
  // across edges, which used to swallow the gesture.
  await page.mouse.move(gutterX, second.y + second.height + 10)
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

  // Deleting is two-step. The menu stays open across a switch, so there is
  // nothing to reopen here.
  await page.getByRole('button', { name: 'Delete Grants' }).click()
  await page.getByRole('button', { name: 'Confirm deleting Grants' }).click()
  await expect(page.getByRole('menuitem', { name: /Grants/ })).toHaveCount(0)
})

test('builds a view from the model list and extends it afterwards', async ({ page }) => {
  await keepViewsInMemory(page)
  await openGraph(page)
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

/** Where Vue Flow has placed a node, in canvas coordinates rather than pixels. */
async function flowPosition(page: Page, model: string): Promise<{ x: number; y: number }> {
  const style = (await node(page, model).getAttribute('style')) ?? ''
  const match = style.match(/translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/)
  if (!match) throw new Error(`no transform on ${model}: ${style}`)
  return { x: Number(match[1]), y: Number(match[2]) }
}

test('a dragged node stays where it was put when the view changes', async ({ page }) => {
  // Answered here rather than by the dev server, so the run leaves
  // public/layout.json and public/views.json as it found them.
  await page.route('**/__layout', (route) => route.fulfill({ json: { ok: true, saved: 1 } }))
  await page.route('**/__views', (route) => route.fulfill({ json: { ok: true, saved: 1 } }))

  await page.setViewportSize({ width: 1400, height: 900 })
  await openGraph(page)
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  const grid = await flowPosition(page, 'User')
  const box = (await node(page, 'User').boundingBox())!

  const saved = page.waitForRequest('**/__layout')
  await page.mouse.move(box.x + box.width / 2, box.y + 12)
  await page.mouse.down()
  await page.mouse.move(box.x + box.width / 2 + 240, box.y + 12 + 160, { steps: 12 })
  await page.mouse.up()
  await saved

  const dragged = await flowPosition(page, 'User')
  expect(dragged).not.toEqual(grid)

  // Narrow to a view, then back out. Every switch hands Vue Flow a new node
  // list, and the list used to carry the position from before the drag.
  // The drag left User selected, and a new view starts from the selection.
  await page.getByRole('button', { name: /^View:/ }).click()
  await page.getByLabel('Filter models').fill('user')
  await expect(page.getByRole('menuitemcheckbox', { name: /^User / })).toBeChecked()
  await page.getByLabel('Filter models').fill('team')
  await page.getByRole('menuitemcheckbox', { name: /^Team / }).click()
  await page.getByLabel('Name for the new view').fill('People')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(2)

  expect(await flowPosition(page, 'User')).toEqual(dragged)

  // The menu stays open after saving, so "All models" is right there.
  await page.getByRole('menuitem', { name: 'All models' }).click()
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  expect(await flowPosition(page, 'User')).toEqual(dragged)
})

/**
 * How far an edge's two ends are, on screen, from the handles they should
 * leave and arrive at. Both are measured in page pixels, so the canvas's pan
 * and zoom do not matter.
 */
async function edgeOffsets(
  page: Page,
  edge: { source: string; name: string; target: string },
  handles: { source: string; target: string },
) {
  return page.evaluate(
    ({ edge, handles }) => {
      const id = `App\\Models\\${edge.source}--${edge.name}--App\\Models\\${edge.target}`
      const path = [...document.querySelectorAll<SVGGElement>('.vue-flow__edge')]
        .find((g) => g.dataset.id === id)
        ?.querySelector<SVGPathElement>('.vue-flow__edge-path')
      if (!path) return null

      const onScreen = (at: number) => {
        const point = path.getPointAtLength(at)
        const matrix = path.getScreenCTM()!
        return new DOMPoint(point.x, point.y).matrixTransform(matrix)
      }

      const handle = (model: string, handleId: string) => {
        const node = [...document.querySelectorAll<HTMLElement>('.vue-flow__node')].find(
          (n) => n.dataset.id === `App\\Models\\${model}`,
        )
        const box = node
          ?.querySelector(`.vue-flow__handle[data-handleid="${handleId}"]`)
          ?.getBoundingClientRect()
        return box ? { x: box.x + box.width / 2, y: box.y + box.height / 2 } : null
      }

      const from = handle(edge.source, handles.source)
      const to = handle(edge.target, handles.target)
      if (!from || !to) return null

      const start = onScreen(0)
      const end = onScreen(path.getTotalLength())
      return {
        source: Math.hypot(start.x - from.x, start.y - from.y),
        target: Math.hypot(end.x - to.x, end.y - to.y),
      }
    },
    { edge, handles },
  )
}

test('a relation runs from its foreign key to the key it points at', async ({ page }) => {
  await openGraph(page)
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // Document belongs to Folder: from documents.folder_id to folders.id. Polled:
  // the canvas may still be fitting itself to the page when the nodes appear.
  await expect
    .poll(async () => {
      const offsets = await edgeOffsets(
        page,
        { source: 'Document', name: 'folder', target: 'Folder' },
        { source: 'col-out:folder_id', target: 'col-in:id' },
      )
      return offsets ? Math.max(offsets.source, offsets.target) : null
    })
    .toBeLessThan(3)
})

test('a key folded into "+N more" is stood in for until the card opens', async ({ page }) => {
  await openGraph(page)
  await expect(page.locator('.vue-flow__node')).toHaveCount(22)

  // documents.workspace_id is past what a collapsed card lists.
  const edge = { source: 'Document', name: 'workspace', target: 'Workspace' }

  await expect
    .poll(
      async () =>
        (await edgeOffsets(page, edge, { source: 'more-out', target: 'col-in:id' }))?.source,
    )
    .toBeLessThan(3)

  await node(page, 'Document').locator('.model-node').click()
  await expect(node(page, 'Document').locator('.model-node')).toHaveAttribute(
    'aria-expanded',
    'true',
  )

  // Once listed, the column carries its own handle and the edge moves to it.
  await expect
    .poll(
      async () =>
        (await edgeOffsets(page, edge, { source: 'col-out:workspace_id', target: 'col-in:id' }))
          ?.source,
    )
    .toBeLessThan(3)
})
