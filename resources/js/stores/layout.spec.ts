import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useLayoutStore } from '@/stores/layout'

/**
 * A fetch whose answers are released by hand, so a test can hold a save in
 * flight and do things while it waits — which is where the bugs were.
 */
function heldFetch() {
  const calls: {
    body: { positions: Record<string, unknown>; revision?: unknown }
    release: (status?: number, answer?: object) => void
  }[] = []

  const fetch = vi.fn<(url: string, init: RequestInit) => Promise<Response>>((_url, init) => {
    return new Promise<Response>((resolve) => {
      calls.push({
        body: JSON.parse(String(init.body)),
        release: (status = 200, answer: object = { ok: true }) =>
          resolve(new Response(JSON.stringify(answer), { status })),
      })
    })
  })

  return { fetch, calls }
}

/** Lets queued promise callbacks run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('layout store saving', () => {
  let held: ReturnType<typeof heldFetch>

  beforeEach(() => {
    setActivePinia(createPinia())
    held = heldFetch()
    vi.stubGlobal('fetch', held.fetch)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    delete window.__DISSECT__
  })

  it('keeps a drag made while a save is in flight', async () => {
    const layout = useLayoutStore()

    layout.setPosition('Post', { x: 1, y: 1 }, ['Post', 'Comment'])
    const first = layout.save()
    await settle()

    // The request is out. Somebody drags another node before it answers.
    layout.setPosition('Comment', { x: 2, y: 2 }, ['Post', 'Comment'])

    held.calls[0]!.release()
    await first

    // Used to be overwritten with what the first request sent.
    expect(layout.positions).toEqual({ Post: { x: 1, y: 1 }, Comment: { x: 2, y: 2 } })
  })

  it('sends one save at a time, each with everything dragged so far', async () => {
    const layout = useLayoutStore()

    layout.setPosition('Post', { x: 1, y: 1 })
    const first = layout.save()
    layout.setPosition('Comment', { x: 2, y: 2 })
    const second = layout.save()
    await settle()

    expect(held.calls).toHaveLength(1)

    held.calls[0]!.release()
    await first
    await settle()

    expect(held.calls).toHaveLength(2)
    expect(held.calls[1]!.body.positions).toEqual({ Post: { x: 1, y: 1 }, Comment: { x: 2, y: 2 } })

    held.calls[1]!.release()
    await second
  })

  it('still prunes models that no longer exist, from the file and from memory', async () => {
    const layout = useLayoutStore()
    layout.positions = { Gone: { x: 9, y: 9 } }

    layout.setPosition('Post', { x: 1, y: 1 }, ['Post'])
    const save = layout.save()
    await settle()

    expect(held.calls[0]!.body.positions).toEqual({ Post: { x: 1, y: 1 } })

    held.calls[0]!.release()
    await save

    expect(layout.positions).toEqual({ Post: { x: 1, y: 1 } })
  })

  it('keeps the edit and reports it when the save is refused', async () => {
    const layout = useLayoutStore()

    layout.setPosition('Post', { x: 1, y: 1 }, ['Post'])
    layout.positions = { ...layout.positions, Gone: { x: 9, y: 9 } }
    const save = layout.save()
    await settle()

    held.calls[0]!.release(409)
    await save

    expect(layout.saveError).toMatch(/409/)
    expect(layout.positions).toHaveProperty('Post')
  })

  it('sends back the revision it read, then the one each save returns', async () => {
    window.__DISSECT__ = { layout: { version: 1, positions: {}, revision: 'aaaa' } }
    const layout = useLayoutStore()
    await layout.load()

    layout.setPosition('Post', { x: 1, y: 1 })
    const first = layout.save()
    await settle()
    expect(held.calls[0]!.body.revision).toBe('aaaa')
    held.calls[0]!.release(200, { ok: true, saved: 1, revision: 'bbbb' })
    await first

    const second = layout.save()
    await settle()
    expect(held.calls[1]!.body.revision).toBe('bbbb')
    held.calls[1]!.release()
    await second
  })

  it('sends null when there was no file, and nothing when the host keeps no revisions', async () => {
    window.__DISSECT__ = { layout: { version: 1, positions: {}, revision: null } }
    const layout = useLayoutStore()
    await layout.load()

    const first = layout.save()
    await settle()
    expect(held.calls[0]!.body).toHaveProperty('revision', null)

    // The dev server answers without one: from then on, nothing to send.
    held.calls[0]!.release(200, { ok: true, saved: 0 })
    await first

    const second = layout.save()
    await settle()
    expect(held.calls[1]!.body).not.toHaveProperty('revision')
    held.calls[1]!.release()
    await second
  })

  it('reports what the server said when it refuses a stale save', async () => {
    const layout = useLayoutStore()

    const save = layout.save()
    await settle()
    held.calls[0]!.release(409, {
      ok: false,
      message: 'layout.json has changed since this page read it',
    })
    await save

    expect(layout.saveError).toBe('layout.json has changed since this page read it')
  })
})
