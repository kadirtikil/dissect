import { readFileSync } from 'node:fs'
import { test, expect } from '@playwright/test'

/**
 * The live queue surface, against public/queue.json.
 *
 * The one surface whose payload is runtime state rather than a description of
 * code, so what is worth testing is the honesty of it: the three tenses being
 * told apart, the gap where succeeded jobs would be, and a driver that cannot
 * be read saying so instead of showing an empty queue.
 */

test('reads the queue in three tenses', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Now' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Next' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Past' })).toBeVisible()

  // Depth first: counting is cheap where listing is not.
  await expect(page.getByText('7 on the queue · 3 delayed')).toBeVisible()
})

test('separates what is waiting from what a worker is holding', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()

  const waiting = page.locator('section', { hasText: 'WAITING' }).first()
  const reserved = page.locator('section', { hasText: 'RESERVED' }).first()

  await expect(waiting.getByRole('button', { name: /ExtractDocumentText/ }).first()).toBeVisible()
  // Held, and read by when it was taken rather than when it was queued.
  await expect(reserved.getByRole('columnheader', { name: 'taken' })).toBeVisible()
  await expect(reserved.getByText(/taken by a worker/)).toBeVisible()
})

test('says plainly that a job which succeeded is recorded nowhere', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()

  // The honest limit of the whole surface. An empty history means nothing
  // failed, not that nothing ran.
  await expect(page.getByText(/keeps no record of a job that succeeded/)).toBeVisible()
  await expect(page.getByText(/pdftotext exited with status 127/)).toBeVisible()
})

test('follows a queued job to the class behind it', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()

  await page.getByRole('button', { name: /RenderThumbnail/ }).first().click()

  // A live row and a row in the job list are the same class, addressed by the
  // same id.
  await expect(page.getByRole('button', { name: 'Jobs', exact: true })).toHaveAttribute(
    'aria-current',
    'page',
  )
  await expect(page.getByText('App\\Jobs\\RenderThumbnail')).toBeVisible()
})

test('stops polling when the page is left before its first reading arrives', async ({ page }) => {
  await page.clock.install()

  // Hold the first reading until the page has already been left.
  let release!: () => void
  const held = new Promise<void>((resolve) => (release = resolve))
  let reads = 0

  await page.route('**/queue.json*', async (route) => {
    reads++
    if (reads === 1) await held
    await route.continue()
  })

  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()
  await expect.poll(() => reads).toBe(1)

  await page.getByRole('button', { name: 'Jobs', exact: true }).click()
  release()

  // Several poll intervals later, nothing else has been asked for.
  await page.clock.runFor(30_000)
  expect(reads).toBe(1)
})

test('never shows a reading for the connection it just switched away from', async ({ page }) => {
  const fixture = JSON.parse(readFileSync('public/queue.json', 'utf8'))
  let release!: () => void
  const held = new Promise<void>((resolve) => (release = resolve))
  let reads = 0
  let staleAnswered = false

  await page.route('**/queue.json*', async (route) => {
    const read = ++reads
    const connection = new URL(route.request().url()).searchParams.get('connection') ?? 'database'

    // The poll for `database` that is in flight when the switch happens comes
    // back last.
    if (read === 2) await held

    try {
      await route.fulfill({ json: { ...fixture, connection, driver: connection } })
    } catch {
      // The page may already have cancelled it, which is the point.
    } finally {
      if (read === 2) staleAnswered = true
    }
  })

  await page.clock.install()
  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'database', exact: true })).toBeVisible()

  await page.clock.runFor(5_000)
  await expect.poll(() => reads).toBe(2)

  await page.getByLabel('Queue connection').selectOption('redis')
  await expect(page.getByRole('heading', { name: 'redis', exact: true })).toBeVisible()

  release()
  await expect.poll(() => staleAnswered).toBe(true)
  await expect(page.getByRole('heading', { name: 'redis', exact: true })).toBeVisible()
})

test('ages the reading on screen and says when a poll fails', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-08-31T12:00:00Z') })
  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()
  await expect(page.getByText('read just now')).toBeVisible()

  // The server goes away. The last good reading stays up — and must stop
  // looking fresh.
  await page.route('**/queue.json*', (route) => route.fulfill({ status: 500, body: 'down' }))
  await page.clock.runFor(5_000)
  await page.clock.runFor(60_000)

  // Read once, not retried: a retrying assertion can be satisfied by a later
  // re-render, and the bug was that nothing re-rendered on its own.
  // eslint-disable-next-line playwright/prefer-web-first-assertions
  expect(await page.getByText(/^read /).textContent()).toBe('read 1m ago')
  await expect(page.getByText('last read failed')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'database', exact: true })).toBeVisible()
})

test('reads a Redis reservation by when it expires', async ({ page }) => {
  const fixture = JSON.parse(readFileSync('public/queue.json', 'utf8'))

  // Redis records when a reservation runs out, not when it was taken.
  const reserved = {
    ...fixture.now.reserved,
    rows: fixture.now.reserved.rows.map(({ reserved_at, ...row }: Record<string, unknown>) => ({
      ...row,
      reserved_until: reserved_at,
    })),
  }

  await page.route('**/queue.json*', (route) =>
    route.fulfill({
      json: { ...fixture, driver: 'redis', now: { ...fixture.now, reserved } },
    }),
  )

  await page.goto('/')
  await page.getByRole('button', { name: 'Queue', exact: true }).click()

  const section = page.locator('section', { hasText: 'RESERVED' }).first()
  await expect(section.getByRole('columnheader', { name: 'expires' })).toBeVisible()
  await expect(section.getByRole('row').nth(1)).not.toContainText('—')
})
