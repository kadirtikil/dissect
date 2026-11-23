import type { Page } from '@playwright/test'

/**
 * Answers views.json and the dev server's save endpoint from memory, for one
 * page, across its reloads.
 *
 * Without it a test that builds a view writes the tracked public/views.json,
 * leaves it changed when it fails before its own clean-up, and races the same
 * test running in another browser project against the same file.
 */
export async function keepViewsInMemory(page: Page): Promise<void> {
  let views: unknown[] = []

  await page.route('**/views.json', (route) => route.fulfill({ json: { version: 2, views } }))

  await page.route('**/__views', async (route) => {
    const body = route.request().postDataJSON() as { views?: unknown[] }
    views = Array.isArray(body?.views) ? body.views : []
    await route.fulfill({ json: { ok: true, saved: views.length } })
  })
}
