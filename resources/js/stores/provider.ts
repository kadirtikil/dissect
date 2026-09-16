import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { Provider, ProvidersFile } from '@/types/providers'
import { bootstrap } from '@/lib/bootstrap'
import { filterProviders, type ProviderFilterState } from '@/lib/providerFilters'

type Status = 'idle' | 'loading' | 'ready' | 'error'

/**
 * Holds the provider list, every tree inline.
 *
 * Fetched on first open like the job list: building it parses every provider
 * and reflects each constructor down its tree, and a session that never opens
 * this surface should never pay for that.
 */
export const useProviderStore = defineStore('provider-store', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const providers = shallowRef<Provider[]>([])

  /** Server change signal this list was built from. */
  const fingerprint = ref<string | null>(null)

  const search = ref('')

  const selectedId = ref<string | null>(null)

  /**
   * Node ids whose doc block is open.
   *
   * Held here rather than in the card: Vue Flow replaces the node array when a
   * refresh lands, and a component ref would snap every card shut each time
   * somebody saved a file.
   */
  const expanded = ref<Set<string>>(new Set())

  const loaded = computed(() => status.value === 'ready')

  const filters = computed<ProviderFilterState>(() => ({ search: search.value }))

  const visible = computed(() => filterProviders(providers.value, filters.value))

  /**
   * Looked up among the visible providers, as the job list does: a tree on the
   * canvas for a provider the list has filtered out would be a selection
   * nobody can see the source of.
   */
  const selected = computed(() => visible.value.find((p) => p.id === selectedId.value) ?? null)

  const stats = computed(() => ({
    total: providers.value.length,
    visible: visible.value.length,
  }))

  function select(id: string | null) {
    selectedId.value = id
  }

  function toggleExpanded(id: string) {
    const next = new Set(expanded.value)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    expanded.value = next
  }

  function clearFilters() {
    search.value = ''
  }

  function endpoint(): string {
    return bootstrap().providersUrl ?? `${import.meta.env.BASE_URL}providers.json`
  }

  /** The payload, or null when it is not the shape this store expects. */
  async function fetchList(): Promise<(ProvidersFile & { fingerprint?: string }) | null> {
    const res = await fetch(endpoint(), { cache: 'no-store' })
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

    const payload = (await res.json()) as Partial<ProvidersFile> & { fingerprint?: string }

    return Array.isArray(payload?.providers) ? (payload as ProvidersFile & { fingerprint?: string }) : null
  }

  /**
   * Fetches the list once. Later calls are a no-op, so opening and closing the
   * surface does not re-fetch — {@see refresh} is what picks up a change.
   */
  async function load(): Promise<void> {
    if (status.value === 'loading' || status.value === 'ready') return

    status.value = 'loading'
    error.value = null

    try {
      const payload = await fetchList()
      if (payload === null) throw new Error('Expected an object with a "providers" array')

      providers.value = payload.providers
      fingerprint.value = payload.fingerprint ?? null
      status.value = 'ready'
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      status.value = 'error'
    }
  }

  /**
   * Pulls a fresh list and swaps it in, leaving the current one alone if it
   * cannot — a failed refresh must not replace a working tree with an error
   * card.
   *
   * The selection survives by id, so the provider somebody is reading stays
   * open across a re-export unless the class itself is gone.
   */
  async function refresh(): Promise<boolean> {
    try {
      const payload = await fetchList()
      if (payload === null) return false

      providers.value = payload.providers
      fingerprint.value = payload.fingerprint ?? fingerprint.value
      status.value = 'ready'

      return true
    } catch {
      return false
    }
  }

  return {
    status,
    error,
    providers,
    loaded,
    fingerprint,
    search,
    selectedId,
    expanded,
    filters,
    visible,
    selected,
    stats,
    select,
    toggleExpanded,
    clearFilters,
    load,
    refresh,
  }
})
