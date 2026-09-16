import { describe, expect, it } from 'vitest'
import type { ProviderEdge, ProviderNode } from '@/types/providers'
import { PROVIDER_NODE_HEIGHT, PROVIDER_NODE_WIDTH, layoutProviderTree } from '@/lib/providerLayout'

/** A node at a depth, with everything else empty. */
function node(id: string, depth: number): ProviderNode {
  return {
    id,
    kind: depth === 0 ? 'provider' : 'concrete',
    label: id,
    origin: 'app',
    depth,
    confidence: 'certain',
    class: id,
    file: null,
    summary: null,
  }
}

function edge(source: string, target: string, kind = 'bind'): ProviderEdge {
  return {
    id: `${source}->${target}:${kind}`,
    source,
    target,
    kind,
    confidence: 'certain',
    consumer: null,
  }
}

/** Ids of one tier, top to bottom. */
function column(positions: Record<string, { x: number; y: number }>, x: number): string[] {
  return Object.entries(positions)
    .filter(([, p]) => p.x === x)
    .sort(([, a], [, b]) => a.y - b.y)
    .map(([id]) => id)
}

describe('layoutProviderTree', () => {
  it('returns nothing for an empty tree', () => {
    expect(layoutProviderTree([], [])).toEqual({})
  })

  it('puts a lone root at the origin', () => {
    expect(layoutProviderTree([node('root', 0)], [])).toEqual({ root: { x: 0, y: 0 } })
  })

  it('gives each depth its own column, left to right', () => {
    const positions = layoutProviderTree(
      [node('root', 0), node('contract', 1), node('concrete', 2)],
      [edge('root', 'contract'), edge('contract', 'concrete')],
    )

    expect(positions.root!.x).toBe(0)
    expect(positions.contract!.x).toBeGreaterThanOrEqual(PROVIDER_NODE_WIDTH)
    expect(positions.concrete!.x).toBe(positions.contract!.x * 2)
  })

  it('centres each column on the root, without overlapping', () => {
    const positions = layoutProviderTree(
      [node('root', 0), node('a', 1), node('b', 1), node('c', 1)],
      [edge('root', 'a'), edge('root', 'b'), edge('root', 'c')],
    )

    const ys = ['a', 'b', 'c'].map((id) => positions[id]!.y)

    expect(ys[1]).toBe(0)
    expect(ys[0]).toBe(-ys[2]!)
    expect(ys[1]! - ys[0]!).toBeGreaterThanOrEqual(PROVIDER_NODE_HEIGHT)
  })

  it('places children beside their parents rather than in server order', () => {
    // The server lists b's child first; drawn in that order the two edges
    // would cross.
    const positions = layoutProviderTree(
      [node('root', 0), node('a', 1), node('b', 1), node('childOfB', 2), node('childOfA', 2)],
      [edge('root', 'a'), edge('root', 'b'), edge('a', 'childOfA'), edge('b', 'childOfB')],
    )

    const x = positions.childOfA!.x

    expect(column(positions, x)).toEqual(['childOfA', 'childOfB'])
  })

  it('keeps server order among nodes nothing shallower points at', () => {
    const positions = layoutProviderTree(
      [node('root', 0), node('first', 1), node('second', 1), node('third', 1)],
      [],
    )

    expect(column(positions, positions.first!.x)).toEqual(['first', 'second', 'third'])
  })

  it('ignores an arrow back up the tree, so a cycle cannot reorder anything', () => {
    const nodes = [node('root', 0), node('storage', 1), node('index', 2), node('other', 2)]
    const forward = [edge('root', 'storage'), edge('storage', 'other'), edge('storage', 'index')]

    const withoutCycle = layoutProviderTree(nodes, forward)
    const withCycle = layoutProviderTree(nodes, [...forward, edge('index', 'storage', 'injects')])

    expect(withCycle).toEqual(withoutCycle)
  })

  it('is stable: the same tree lands in the same place', () => {
    const nodes = [node('root', 0), node('a', 1), node('b', 2)]
    const edges = [edge('root', 'a'), edge('a', 'b')]

    expect(layoutProviderTree(nodes, edges)).toEqual(layoutProviderTree(nodes, edges))
  })
})
