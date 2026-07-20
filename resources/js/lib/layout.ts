import type { Node } from '@vue-flow/core'
import type { ModelNodeData } from '@/types/schema'

export const NODE_WIDTH = 200
/** Height of a node with no column list — header plus table name. */
export const NODE_HEIGHT = 60

/** Nodes per column before wrapping to the next one. */
export const COLUMN_SIZE = 5

/** Columns listed before collapsing into a "+N more" row. */
export const MAX_VISIBLE_COLUMNS = 8

// Must track the type styles in ModelNode.vue: text-[10px] with gap-px.
const COLUMN_LINE_HEIGHT = 14
// Border-top plus the mt-1/pt-1 around the column list.
const COLUMN_LIST_CHROME = 10

const COLUMN_GAP = 140
const ROW_GAP = 40
const MARGIN = 40

/**
 * Single source of truth for flow direction. ModelNode derives its handle
 * positions from this — if they disagree, edges loop back around the nodes
 * instead of running between columns.
 */
export type LayoutDirection = 'LR' | 'TB'
export const LAYOUT_DIRECTION: LayoutDirection = 'LR'

/**
 * Nodes grow with their column list, so rows cannot sit on a fixed pitch or
 * tall nodes overlap the one below. The height is estimated from the same
 * constants the component renders with.
 */
export function estimateNodeHeight(node: Node<ModelNodeData>): number {
  const count = node.data?.columns?.length ?? 0
  if (count === 0) return NODE_HEIGHT

  const rows = Math.min(count, MAX_VISIBLE_COLUMNS) + (count > MAX_VISIBLE_COLUMNS ? 1 : 0)
  return NODE_HEIGHT + COLUMN_LIST_CHROME + rows * COLUMN_LINE_HEIGHT
}

/**
 * The schema carries no coordinates, so positions are assigned on a grid:
 * fill a column top-to-bottom up to COLUMN_SIZE, then start the next column.
 *
 * Deliberately independent of the edges — position is a pure function of the
 * node's index, so the arrangement is stable and predictable rather than
 * shifting whenever a relation is added or removed.
 */
export function layoutGraph(
  nodes: Node<ModelNodeData>[],
  columnSize: number = COLUMN_SIZE,
): Node<ModelNodeData>[] {
  const perColumn = Math.max(1, Math.floor(columnSize))

  // Every column restarts at the top margin; y accumulates the real heights of
  // the nodes stacked above, so a tall node pushes its neighbours down rather
  // than overlapping them.
  let y = MARGIN

  return nodes.map((node, i) => {
    const column = Math.floor(i / perColumn)
    const row = i % perColumn

    if (row === 0) y = MARGIN

    const position = { x: MARGIN + column * (NODE_WIDTH + COLUMN_GAP), y }
    y += estimateNodeHeight(node) + ROW_GAP

    return { ...node, position }
  })
}
