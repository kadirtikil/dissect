import { describe, expect, it } from 'vitest'
import { keptViews, lostInSave } from '@/lib/views'
import type { GraphView } from '@/types/schema'

const view = (id: string, models = 1): GraphView => ({
  id,
  name: id.toUpperCase(),
  models: Array.from({ length: models }, (_, i) => `App\\Models\\M${i}`),
})

describe('keptViews', () => {
  it('reads what the host kept, and nothing from a host that does not say', () => {
    expect(keptViews({ ok: true, views: [view('a')] })).toEqual([view('a')])
    expect(keptViews({ ok: true })).toBeNull()
    expect(keptViews(null)).toBeNull()
  })
})

describe('lostInSave', () => {
  it('is nothing when everything was kept', () => {
    expect(lostInSave([view('a'), view('b', 3)], [view('a'), view('b', 3)])).toBeNull()
  })

  it('names a view past the cap, and the cap', () => {
    expect(lostInSave([view('a'), view('b'), view('c')], [view('a'), view('b')])).toBe(
      '"C" was not saved: views.json holds at most 2 views.',
    )
  })

  it('says how much of a view past the model cap was kept', () => {
    expect(lostInSave([view('a', 501)], [view('a', 500)])).toBe(
      '"A" was saved with 500 of its 501 models, the most a view holds.',
    )
  })
})
