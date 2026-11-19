import { describe, expect, it } from 'vitest'
import {
  MORE_HANDLE,
  NODE_HANDLE,
  columnHandle,
  handleFor,
  keyColumns,
} from '@/lib/relationHandles'
import { MAX_VISIBLE_COLUMNS } from '@/lib/layout'
import type { SchemaEdge } from '@/types/schema'

const columns = ['id', 'author_id', 'title']

/** More columns than a collapsed card lists, with the key past the cut. */
const wide = [...Array.from({ length: MAX_VISIBLE_COLUMNS }, (_, i) => `c${i}`), 'owner_id']

describe('handleFor', () => {
  it('attaches to the key column’s own row', () => {
    expect(handleFor('author_id', columns, false, 'out')).toBe(columnHandle('author_id', 'out'))
    expect(handleFor('id', columns, false, 'in')).toBe(columnHandle('id', 'in'))
  })

  it('attaches to the whole card when no column is named', () => {
    // A host on an older package, or the far end of a MorphTo.
    expect(handleFor(null, columns, false, 'in')).toBe(NODE_HANDLE.in)
    expect(handleFor(undefined, columns, false, 'out')).toBe(NODE_HANDLE.out)
  })

  it('attaches to the whole card when the model has no such column', () => {
    // A model outside the scan has no columns at all.
    expect(handleFor('id', [], false, 'in')).toBe(NODE_HANDLE.in)
    expect(handleFor('missing', columns, false, 'out')).toBe(NODE_HANDLE.out)
  })

  it('lands on "+N more" while a collapsed card hides the column', () => {
    expect(handleFor('owner_id', wide, false, 'out')).toBe(MORE_HANDLE.out)
    expect(handleFor('owner_id', wide, false, 'in')).toBe(MORE_HANDLE.in)
  })

  it('moves to the column’s row once the card is open', () => {
    expect(handleFor('owner_id', wide, true, 'out')).toBe(columnHandle('owner_id', 'out'))
  })
})

describe('keyColumns', () => {
  const edge = (source: string, target: string, keys?: SchemaEdge['keys']): SchemaEdge => ({
    source,
    target,
    name: 'rel',
    type: 'BelongsTo',
    keys,
  })

  it('collects the columns each model offers, by the side an edge uses', () => {
    const keys = keyColumns([
      edge('Post', 'Author', { source: 'author_id', target: 'id', via: null }),
      edge('Author', 'Post', { source: 'id', target: 'author_id', via: null }),
    ])

    expect(keys.get('Post')).toEqual({ out: new Set(['author_id']), in: new Set(['author_id']) })
    expect(keys.get('Author')).toEqual({ out: new Set(['id']), in: new Set(['id']) })
  })

  it('skips ends with no column, and edges with no keys', () => {
    const keys = keyColumns([
      edge('Comment', 'Comment', { source: 'commentable_id', target: null, via: null }),
      edge('Post', 'Tag'),
    ])

    expect(keys.get('Comment')).toEqual({ out: new Set(['commentable_id']), in: new Set() })
    expect(keys.has('Post')).toBe(false)
    expect(keys.has('Tag')).toBe(false)
  })
})
