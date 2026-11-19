import { MAX_VISIBLE_COLUMNS } from '@/lib/layout'
import type { SchemaEdge } from '@/types/schema'

/**
 * Where on a model card each end of a relation attaches.
 *
 * An edge leaves from the column that does the linking (`author_id` on a post)
 * and arrives at the column it points to (`id` on an author), so the graph says
 * which attribute ties two models together, not only that something does.
 *
 * `out` is the end an edge leaves from, `in` the end it arrives at. Every id
 * handed to Vue Flow has to belong to a handle the card actually rendered —
 * one that does not exist makes Vue Flow drop the edge without a word — so the
 * card and the store both decide through the functions here.
 */
export type HandleSide = 'in' | 'out'

/** The card as a whole: for a relation with no named column. */
export const NODE_HANDLE: Record<HandleSide, string> = { in: 'node-in', out: 'node-out' }

/** The "+N more" row, standing in for a key column a collapsed card hides. */
export const MORE_HANDLE: Record<HandleSide, string> = { in: 'more-in', out: 'more-out' }

export function columnHandle(column: string, side: HandleSide): string {
  return `col-${side}:${column}`
}

/**
 * The handle one end of an edge attaches to.
 *
 * Falls back to the whole card when the column is unknown, or when the model
 * has no such column (a host on an older package, a model outside the scan).
 * A column the collapsed card is not listing attaches to its "+N more" row, so
 * the edge still lands near the attribute it is about.
 */
export function handleFor(
  column: string | null | undefined,
  columns: readonly string[],
  expanded: boolean,
  side: HandleSide,
): string {
  if (!column) return NODE_HANDLE[side]

  const index = columns.indexOf(column)
  if (index === -1) return NODE_HANDLE[side]

  return expanded || index < MAX_VISIBLE_COLUMNS ? columnHandle(column, side) : MORE_HANDLE[side]
}

/** The columns of each model that an edge leaves from (`out`) or arrives at (`in`). */
export type KeyColumns = Map<string, Record<HandleSide, Set<string>>>

export function keyColumns(edges: readonly SchemaEdge[]): KeyColumns {
  const keys: KeyColumns = new Map()

  const add = (model: string, side: HandleSide, column: string | null | undefined) => {
    if (!column) return
    let entry = keys.get(model)
    if (!entry) keys.set(model, (entry = { in: new Set(), out: new Set() }))
    entry[side].add(column)
  }

  for (const edge of edges) {
    add(edge.source, 'out', edge.keys?.source)
    add(edge.target, 'in', edge.keys?.target)
  }

  return keys
}
