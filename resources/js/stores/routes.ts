import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { ApiRoute, RoutesFile } from '@/types/routes'
import type { RouteFilterState } from '@/lib/routeFilters'
import { facetCounts, filterRoutes, groupRoutes } from '@/lib/routeFilters'
import { useViewsStore } from '@/stores/views'
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

  /**
   * "Endpoints touching this model" — set when somebody arrives here from a
   * model card rather than by opening the list.
   */
  const modelFilter = ref<string | null>(null)

  /**
   * Whether the active saved view narrows the endpoint list too.
   *
   * A view means "the billing models", and the endpoints touching them are part
   * of that bounded context — so on by default, and one click from off.
   */
  const scopeToView = ref(true)

  const selectedId = ref<string | null>(null)

  const loaded = computed(() => status.value === 'ready')

  /** The active view's membership, when it is meant to apply here. */
  const viewModels = computed<string[] | null>(() => {
    if (!scopeToView.value) return null
    const members = useViewsStore().activeModels
    return members ? [...members] : null
  })

  /**
   * One filter state assembled from the several controls that feed it. An
   * explicit "show me this model's endpoints" is a deliberate act and outranks
   * the ambient view scope.
   */
  const filters = computed<RouteFilterState>(() => ({
    search: search.value,
    methods: methods.value,
    groups: groups.value,
    models: modelFilter.value ? [modelFilter.value] : viewModels.value,
  }))

  const visible = computed(() => filterRoutes(routes.value, filters.value))

  const grouped = computed(() => groupRoutes(visible.value))

  const facets = computed(() => facetCounts(routes.value, filters.value))

  const selected = computed(
    () => visible.value.find((r) => r.id === selectedId.value) ?? null,
  )

  /** Models the selected endpoint touches — what the canvas rings. */
  const highlightedModels = computed<string[]>(() => selected.value?.models ?? [])

  const stats = computed(() => ({
    total: routes.value.length,
    visible: visible.value.length,
  }))

  /**
   * How many endpoints touch each model, for the link on a model card.
   *
   * Computed once over the whole list rather than per card: the graph can hold
   * a few hundred nodes, and each of them asking the same question of a few
   * hundred routes is the same answer arrived at expensively.
   */
  const countByModel = computed(() => {
    const counts: Record<string, number> = {}

    for (const route of routes.value) {
      for (const model of route.models) {
        counts[model] = (counts[model] ?? 0) + 1
      }
    }

    return counts
  })

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

  /** Arriving from a model card: one model, and nothing else in the way. */
  function filterByModel(model: string | null) {
    modelFilter.value = model
    search.value = ''
    methods.value = []
    groups.value = []
    selectedId.value = null
  }

  function clearFilters() {
    search.value = ''
    methods.value = []
    groups.value = []
    modelFilter.value = null
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
    modelFilter,
    scopeToView,
    selectedId,
    filters,
    visible,
    grouped,
    facets,
    selected,
    highlightedModels,
    stats,
    countByModel,
    select,
    toggleMethod,
    toggleGroup,
    filterByModel,
    clearFilters,
    load,
    refresh,
  }
})
