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
