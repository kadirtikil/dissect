import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useLayoutStore } from '@/stores/layout'

/**
 * A fetch whose answers are released by hand, so a test can hold a save in
 * flight and do things while it waits — which is where the bugs were.
 */
function heldFetch() {
  const calls: {
    body: { positions: Record<string, unknown> }
    release: (status?: number) => void
  }[] = []

  const fetch = vi.fn<(url: string, init: RequestInit) => Promise<Response>>((_url, init) => {
    return new Promise<Response>((resolve) => {
      calls.push({
        body: JSON.parse(String(init.body)),
        release: (status = 200) => resolve(new Response('{"ok":true}', { status })),
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
})
