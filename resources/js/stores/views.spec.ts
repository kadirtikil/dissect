import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useViewsStore } from '@/stores/views'
import type { GraphView } from '@/types/schema'

const json = (body: unknown, status = 200) =>
  Promise.resolve(new Response(JSON.stringify(body), { status }))

describe('views store saving', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows what the host kept, and says what did not fit', async () => {
    // The host keeps one view: the second is past its cap.
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, init: RequestInit) => {
        const { views } = JSON.parse(String(init.body)) as { views: GraphView[] }
        return json({ ok: true, saved: 1, views: views.slice(0, 1), revision: 'r2' })
      }),
    )

    const store = useViewsStore()

    store.selection = ['App\\Models\\Post']
    store.seedDraft()
    await store.create('Posts')

    store.selection = ['App\\Models\\Comment']
    store.seedDraft()
    await store.create('Comments')

    // Used to stay on screen, looking saved, until a reload took it away.
    expect(store.views.map((v) => v.name)).toEqual(['Posts'])
    expect(store.saveError).toBe('"Comments" was not saved: views.json holds at most 1 views.')
    expect(store.activeId).toBeNull()
  })
})
