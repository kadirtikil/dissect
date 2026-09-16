import type { ProviderEdge, ProviderNode } from '@/types/providers'

/**
 * Where each box in one provider's tree goes.
 *
 * Hand-rolled rather than a layout library, because the hard part is already
 * done: the server sends each node's `depth` — the shallowest hop at which it
 * was reached — and that is exactly the tier a tree layout would compute. What
 * is left is the order within a tier, and one pass is enough for trees this
 * size (the export caps them).
 *
 * Pure, and independent of anything but the nodes and edges, so the same tree
 * always lands in the same place — a refresh that changes nothing moves
 * nothing.
 */

export const PROVIDER_NODE_WIDTH = 220
export const PROVIDER_NODE_HEIGHT = 64

/** Horizontal space between tiers, where the edges run. */
const COLUMN_GAP = 120
/** Vertical space between boxes in one tier. */
const ROW_GAP = 24

export interface Position {
  x: number
  y: number
}

/**
 * Root on the left, one column per depth, each column centred on the root.
 *
 * Within a column, a node sits at the average height of the nodes pointing at
 * it from shallower columns, so a contract's concretes land beside it rather
 * than wherever the server happened to list them. Arrows between nodes in the
 * same or a deeper column — a cycle closing, a class injected twice — do not
 * pull, since they would make the order depend on itself.
 */
export function layoutProviderTree(
  nodes: ProviderNode[],
  edges: ProviderEdge[],
): Record<string, Position> {
  const positions: Record<string, Position> = {}
  const depthOf = new Map(nodes.map((node) => [node.id, node.depth]))
  const rowPitch = PROVIDER_NODE_HEIGHT + ROW_GAP

  /** Parents per node, from shallower tiers only. */
  const parents = new Map<string, string[]>()

  for (const edge of edges) {
    const from = depthOf.get(edge.source)
    const to = depthOf.get(edge.target)

    if (from === undefined || to === undefined || from >= to) continue

    parents.set(edge.target, [...(parents.get(edge.target) ?? []), edge.source])
  }

  const tiers = new Map<number, { node: ProviderNode; index: number }[]>()

  nodes.forEach((node, index) => {
    tiers.set(node.depth, [...(tiers.get(node.depth) ?? []), { node, index }])
  })

  // Shallowest first, so every parent is placed before the tier that sorts on it.
  for (const depth of [...tiers.keys()].sort((a, b) => a - b)) {
    const tier = tiers.get(depth)!

    const keyed = tier.map(({ node, index }) => {
      const placed = (parents.get(node.id) ?? [])
        .map((id) => positions[id]?.y)
        .filter((y): y is number => y !== undefined)

      return {
        node,
        index,
        // A node nothing shallower points at keeps the server's order, after
        // the ones that have somewhere to be.
        key: placed.length ? placed.reduce((sum, y) => sum + y, 0) / placed.length : Infinity,
      }
    })

    keyed.sort((a, b) => a.key - b.key || a.index - b.index)

    const x = depth * (PROVIDER_NODE_WIDTH + COLUMN_GAP)
    const offset = ((keyed.length - 1) * rowPitch) / 2

    keyed.forEach(({ node }, row) => {
      positions[node.id] = { x, y: row * rowPitch - offset }
    })
  }

  return positions
}
