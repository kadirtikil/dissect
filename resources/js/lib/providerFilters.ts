import type { Provider } from '@/types/providers'
import { contextKey, matchesContexts } from '@/lib/domains'

/**
 * Narrowing the provider list.
 *
 * Kept pure and separate from the store so it can be tested without mounting
 * anything — the shape lib/jobFilters.ts and lib/routeFilters.ts already have.
 */

export interface ProviderFilterState {
  search: string
  /**
   * Empty means every domain. Keys come from `contextKey`. A provider is in
   * the domain its own class is in, not the domains of what it binds.
   */
  contexts: string[]
}

export const EMPTY_PROVIDER_FILTERS: ProviderFilterState = {
  search: '',
  contexts: [],
}

/**
 * Whether a provider matches the search box.
 *
 * The tree is searched as well as the provider's own name. "Which provider
 * binds the transcoder?" is the question somebody arrives with far more often
 * than a provider's name, and the answer is only in the nodes.
 */
export function matchesSearch(provider: Provider, search: string): boolean {
  const term = search.trim().toLowerCase()
  if (!term) return true

  return (
    provider.name.toLowerCase().includes(term) ||
    provider.class.toLowerCase().includes(term) ||
    provider.nodes.some(
      (node) =>
        // Unresolved labels are code as written — `$this->app->bind($abstract,
        // $concrete)` — which matches almost any term and names nothing.
        node.kind !== 'unresolved' &&
        (node.label.toLowerCase().includes(term) ||
          (node.class?.toLowerCase().includes(term) ?? false)),
    )
  )
}

export function filterProviders(providers: Provider[], state: ProviderFilterState): Provider[] {
  return providers.filter(
    (provider) =>
      matchesSearch(provider, state.search) && matchesContexts(provider.context, state.contexts),
  )
}

/** Providers per domain key within the search, the selection left out. */
export function contextCounts(
  providers: Provider[],
  state: ProviderFilterState,
): Record<string, number> {
  const counts: Record<string, number> = {}

  for (const provider of providers) {
    if (!matchesSearch(provider, state.search)) continue

    const key = contextKey(provider.context)
    counts[key] = (counts[key] ?? 0) + 1
  }

  return counts
}
