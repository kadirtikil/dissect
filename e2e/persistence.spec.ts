import process from 'node:process'
import { readFileSync } from 'node:fs'
import { test, expect } from '@playwright/test'

/**
 * The dev server's write endpoints, called directly.
 *
 * They exist only under `vite dev` (`apply: 'serve'`), so there is nothing to
 * test against the preview build CI uses.
 */
// eslint-disable-next-line playwright/no-skipped-test -- conditional: dev-server only
test.skip(!!process.env.CI, 'the persistence endpoints exist only on the dev server')

const LAYOUT = 'public/layout.json'

test('a page on another origin cannot write the layout', async ({ request, baseURL }) => {
  const before = readFileSync(LAYOUT, 'utf8')

  // What a malicious page's fetch() looks like from here: a simple request,
  // no preflight, and an Origin the browser fills in and the page cannot fake.
  const res = await request.post('/__layout', {
    headers: { origin: 'https://evil.example', 'content-type': 'text/plain' },
    data: JSON.stringify({ version: 2, positions: { Pwned: { x: 1, y: 1 } } }),
  })

  expect(res.status()).toBe(403)
  expect(readFileSync(LAYOUT, 'utf8')).toBe(before)

  // The page's own origin is still let through. It writes back what was
  // there, so the fixture is left as the suite found it.
  const own = await request.post('/__layout', {
    headers: { origin: new URL(baseURL!).origin },
    data: JSON.parse(before),
  })
  expect(own.status()).toBe(200)
  expect(readFileSync(LAYOUT, 'utf8')).toBe(before)
})

test('an oversized body is refused without being read to the end', async ({ request }) => {
  const before = readFileSync(LAYOUT, 'utf8')

  const positions: Record<string, { x: number; y: number }> = {}
  for (let i = 0; i < 40_000; i++) positions[`Model${i}`] = { x: i, y: i }

  const res = await request.post('/__layout', { data: { version: 2, positions } })

  expect(res.status()).toBe(413)
  expect(readFileSync(LAYOUT, 'utf8')).toBe(before)
})
