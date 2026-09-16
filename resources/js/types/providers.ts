/**
 * Shape of providers.json, as exported from the Laravel side.
 *
 * Every provider carries its whole tree inline — nodes and edges already
 * deduplicated by id on the server, so the canvas can hand them to Vue Flow
 * without inventing identities of its own.
 *
 * Kept deliberately close to the wire format, like types/jobs.ts.
 */

import type { Confidence } from '@/types/routes'

/**
 * What a box stands for. A contract is a name somebody asks the container for;
 * a concrete is the class that gets built — drawing both as "a class" loses the
 * one interesting fact about a binding.
 */
export type ProviderNodeKind = 'provider' | 'contract' | 'concrete' | 'unresolved'

/** Whose code it is — the walk stops at anything that is not the app's. */
export type ProviderOrigin = 'app' | 'framework' | 'vendor' | 'none'

export type ProviderEdgeKind =
  | 'bind'
  | 'singleton'
  | 'scoped'
  | 'instance'
  | 'contextual'
  | 'registers'
  | 'resolves'
  | 'injects'
  | 'calls'

/** Counted on the provider as badges rather than drawn as edges. */
export type SideEffect =
  | 'events'
  | 'gates'
  | 'config'
  | 'publishes'
  | 'migrations'
  | 'routes'
  | 'views'
  | 'commands'

export interface ProviderNode {
  /** Container name or class string; an unresolved node is `unresolved:<n>`. */
  id: string
  kind: ProviderNodeKind | string
  label: string
  origin: ProviderOrigin | string
  /** Hops from the provider, at the shallowest place it was reached. */
  depth: number
  confidence: Confidence
  class: string | null
  /** Project-relative, for a class the application owns. */
  file: string | null
  /** First line of the class doc block. */
  summary: string | null
}

export interface ProviderEdge {
  id: string
  source: string
  target: string
  kind: ProviderEdgeKind | string
  confidence: Confidence
  /** Only on a contextual edge: who has to be asking for the binding to apply. */
  consumer: string | null
}

export interface Provider {
  id: string
  class: string
  name: string
  file: string
  /** Id of the root node — the same as `id`. */
  provider: string
  /** The root first, then in the order reached. */
  nodes: ProviderNode[]
  edges: ProviderEdge[]
  side_effects: Partial<Record<SideEffect, number>>
  deferred: boolean
  /** What a deferred provider says it binds. */
  provides: string[]
  /** Something in the tree could not be named statically. */
  partial: boolean
  /** A depth or node cap cut the walk short. */
  truncated: boolean
}

export interface ProvidersFile {
  providers: Provider[]
  generated_at?: string
}

/**
 * What a card on the canvas is handed.
 *
 * The provider-level facts ride only on the root, which is the one box they
 * describe — badges on every node would say the same thing eleven times.
 */
export interface ProviderNodeData {
  node: ProviderNode
  root: boolean
  sideEffects: Provider['side_effects']
  deferred: boolean
  provides: string[]
}
