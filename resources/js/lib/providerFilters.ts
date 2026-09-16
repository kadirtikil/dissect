import type { Provider } from '@/types/providers'

/**
 * Narrowing the provider list.
 *
 * Kept pure and separate from the store so it can be tested without mounting
 * anything — the shape lib/jobFilters.ts and lib/routeFilters.ts already have.
 */

export interface ProviderFilterState {
  search: string
}

export const EMPTY_PROVIDER_FILTERS: ProviderFilterState = {
  search: '',
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
  return providers.filter((provider) => matchesSearch(provider, state.search))
}
