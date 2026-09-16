import { describe, expect, it } from 'vitest'
import type { Provider, ProviderNode } from '@/types/providers'
import { EMPTY_PROVIDER_FILTERS, filterProviders, matchesSearch } from '@/lib/providerFilters'

/** A node with everything empty, so each test states only what it is about. */
function node(overrides: Partial<ProviderNode> = {}): ProviderNode {
  return {
    id: 'App\\Contracts\\Thing',
    kind: 'contract',
    label: 'Thing',
    origin: 'app',
    depth: 1,
    confidence: 'certain',
    class: 'App\\Contracts\\Thing',
    file: null,
    summary: null,
    ...overrides,
  }
}

/** A provider whose tree is only its root unless a test says otherwise. */
function provider(overrides: Partial<Provider> = {}): Provider {
  const id = overrides.id ?? 'App\\Providers\\AppServiceProvider'

  return {
    id,
    class: id,
    name: id.split('\\').pop() ?? id,
    file: 'app/Providers/AppServiceProvider.php',
    provider: id,
    nodes: [node({ id, kind: 'provider', label: id.split('\\').pop(), class: id, depth: 0 })],
    edges: [],
    side_effects: {},
    deferred: false,
    provides: [],
    partial: false,
    truncated: false,
    ...overrides,
  }
}

describe('matchesSearch', () => {
  it('matches everything when the search is empty or blank', () => {
    expect(matchesSearch(provider(), '')).toBe(true)
    expect(matchesSearch(provider(), '   ')).toBe(true)
  })

  it('matches the provider name, case-insensitively', () => {
    expect(matchesSearch(provider(), 'appservice')).toBe(true)
    expect(matchesSearch(provider(), 'Billing')).toBe(false)
  })

  it('matches the namespace, which the name alone does not carry', () => {
    const domain = provider({ id: 'Domain\\Billing\\Providers\\AppServiceProvider' })

    expect(matchesSearch(domain, 'domain\\billing')).toBe(true)
  })

  it('matches a class anywhere in the tree', () => {
    // "Which provider binds the transcoder?" — the answer is only in the nodes.
    const binds = provider({
      nodes: [
        node({ id: 'App\\Providers\\MediaServiceProvider', kind: 'provider', depth: 0 }),
        node({ id: 'App\\Services\\FfmpegTranscoder', label: 'FfmpegTranscoder', class: 'App\\Services\\FfmpegTranscoder' }),
      ],
    })

    expect(matchesSearch(binds, 'transcoder')).toBe(true)
  })

  it('matches a container key, which has a label and no class', () => {
    const keyed = provider({
      nodes: [node({ id: 'media.transcoder', label: 'media.transcoder', class: null, origin: 'none' })],
    })

    expect(matchesSearch(keyed, 'media.')).toBe(true)
  })

  it('does not match the code written into an unresolved node', () => {
    // `$this->app->bind($abstract, $concrete)` contains "app", "bind" and
    // "concrete" — matching on it would put this provider under most searches.
    const opaque = provider({
      id: 'App\\Providers\\OpaqueServiceProvider',
      nodes: [
        node({ id: 'unresolved:0', kind: 'unresolved', label: '$this->app->bind($abstract, $concrete)', class: null }),
      ],
    })

    expect(matchesSearch(opaque, 'concrete')).toBe(false)
  })
})

describe('filterProviders', () => {
  const providers = [
    provider({ id: 'App\\Providers\\AppServiceProvider' }),
    provider({ id: 'App\\Providers\\BillingServiceProvider' }),
  ]

  it('keeps everything with no filters', () => {
    expect(filterProviders(providers, EMPTY_PROVIDER_FILTERS)).toHaveLength(2)
  })

  it('keeps only what matches, in order', () => {
    expect(filterProviders(providers, { search: 'billing' }).map((p) => p.name)).toEqual([
      'BillingServiceProvider',
    ])
  })
})
