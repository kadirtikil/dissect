import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { ApiRoute, RoutesFile } from '@/types/routes'
import type { RouteFilterState } from '@/lib/routeFilters'
import { facetCounts, filterRoutes, groupRoutes } from '@/lib/routeFilters'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

/**
 * Holds the endpoint list.
 *
 * Unlike the schema, this is **not** inlined into the page: it is fetched the
 * first time somebody opens the routes surface. Reflecting every controller,
 * form request and resource is dearer than inspecting every model, and the page
 * opens on the graph — so a session that never asks for endpoints never pays
 * for them.
 */
export const useRoutesStore = defineStore('routes', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const routes = shallowRef<ApiRoute[]>([])

  /** Server change signal this list was built from. */
  const fingerprint = ref<string | null>(null)

  const search = ref('')
  const methods = ref<string[]>([])
  const groups = ref<string[]>([])

  const selectedId = ref<string | null>(null)

  const loaded = computed(() => status.value === 'ready')

  /**
   * One filter state assembled from the controls that feed it.
   *
   * Nothing outside this surface narrows it. The active saved view scopes the
   * graph, not the endpoint list: which models somebody grouped together says
   * nothing about which endpoints they want to read, and a route quietly
   * missing because of a selection made on another surface is the one failure
   * an endpoint list must not have.
   */
  const filters = computed<RouteFilterState>(() => ({
    search: search.value,
    methods: methods.value,
    groups: groups.value,
  }))

  const visible = computed(() => filterRoutes(routes.value, filters.value))

  const grouped = computed(() => groupRoutes(visible.value))

  const facets = computed(() => facetCounts(routes.value, filters.value))

  const selected = computed(
    () => visible.value.find((r) => r.id === selectedId.value) ?? null,
  )

  const stats = computed(() => ({
    total: routes.value.length,
    visible: visible.value.length,
  }))

  function select(id: string | null) {
    selectedId.value = id
  }

  function toggleMethod(method: string) {
    methods.value = methods.value.includes(method)
      ? methods.value.filter((m) => m !== method)
      : [...methods.value, method]
  }

  function toggleGroup(group: string) {
    groups.value = groups.value.includes(group)
      ? groups.value.filter((g) => g !== group)
      : [...groups.value, group]
  }

  /**
   * Arriving from somewhere that named one endpoint — a job's list of what
   * dispatches it.
   *
   * Filters are cleared first: landing on this surface with the endpoint you
   * asked for filtered out of it is the one thing a link like this must not do.
   */
  function focusEndpoint(id: string) {
    clearFilters()
    selectedId.value = id
  }

  function clearFilters() {
    search.value = ''
    methods.value = []
    groups.value = []
  }

  function endpoint(): string {
    return bootstrap().routesUrl ?? `${import.meta.env.BASE_URL}routes.json`
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
      const res = await fetch(endpoint(), { cache: 'no-store' })
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

      const payload = (await res.json()) as Partial<RoutesFile> & { fingerprint?: string }
      if (!Array.isArray(payload?.routes)) {
        throw new Error('Expected an object with a "routes" array')
      }

      routes.value = payload.routes
      fingerprint.value = payload.fingerprint ?? null
      status.value = 'ready'
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      status.value = 'error'
    }
  }

  /**
   * Pulls a fresh list and swaps it in, leaving the current one alone if it
   * cannot — the same bargain the schema store makes. A failed refresh must not
   * replace a working list with an error card.
   *
   * The selection survives by id, so an endpoint somebody is reading stays open
   * across a re-export unless the route itself is gone.
   */
  async function refresh(): Promise<boolean> {
    try {
      const res = await fetch(endpoint(), { cache: 'no-store' })
      if (!res.ok) return false

      const payload = (await res.json()) as Partial<RoutesFile> & { fingerprint?: string }
      if (!Array.isArray(payload?.routes)) return false

      routes.value = payload.routes
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
    routes,
    loaded,
    fingerprint,
    search,
    methods,
    groups,
    selectedId,
    filters,
    visible,
    grouped,
    facets,
    selected,
    stats,
    select,
    toggleMethod,
    toggleGroup,
    focusEndpoint,
    clearFilters,
    load,
    refresh,
  }
})
