import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { JobsFile, QueueJob } from '@/types/jobs'
import type { JobFilterState } from '@/lib/jobFilters'
import { facetCounts, filterJobs, groupJobs, isUndispatched } from '@/lib/jobFilters'
import { useViewsStore } from '@/stores/views'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

/**
 * Holds the job list.
 *
 * Fetched on first open like the endpoint list, and for a sharper version of
 * the same reason: describing jobs means *parsing* every file under the watched
 * paths looking for dispatch sites, where the route signal only stats them. A
 * session that never opens this surface never pays for it.
 */
export const useJobsStore = defineStore('jobs', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const jobs = shallowRef<QueueJob[]>([])

  /** Server change signal this list was built from. */
  const fingerprint = ref<string | null>(null)

  const search = ref('')
  const kinds = ref<string[]>([])
  const queues = ref<string[]>([])
  const undispatchedOnly = ref(false)

  /**
   * "Jobs carrying this model" — set when somebody arrives here from a model
   * card rather than by opening the list.
   */
  const modelFilter = ref<string | null>(null)

  /**
   * Whether the active saved view narrows the job list too.
   *
   * The same call the endpoint list makes: a view means "the billing models",
   * and the jobs that carry them are part of that bounded context.
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

  const filters = computed<JobFilterState>(() => ({
    search: search.value,
    kinds: kinds.value,
    queues: queues.value,
    // An explicit "show me this model's jobs" is a deliberate act and outranks
    // the ambient view scope.
    models: modelFilter.value ? [modelFilter.value] : viewModels.value,
    undispatchedOnly: undispatchedOnly.value,
  }))

  const visible = computed(() => filterJobs(jobs.value, filters.value))

  const grouped = computed(() => groupJobs(visible.value))

  const facets = computed(() => facetCounts(jobs.value, filters.value))

  const selected = computed(() => visible.value.find((j) => j.id === selectedId.value) ?? null)

  /** Models the selected job carries — what the canvas rings. */
  const highlightedModels = computed<string[]>(() => selected.value?.models ?? [])

  const stats = computed(() => ({
    total: jobs.value.length,
    visible: visible.value.length,
    /** Worth a number of its own: it is the finding nothing else reports. */
    undispatched: jobs.value.filter(isUndispatched).length,
  }))

  /**
   * How many jobs carry each model, for the link on a model card.
   *
   * Computed once over the whole list rather than per card, for the reason the
   * routes store gives: a few hundred nodes each asking the same question of a
   * few dozen jobs is one answer arrived at expensively.
   */
  const countByModel = computed(() => {
    const counts: Record<string, number> = {}

    for (const job of jobs.value) {
      for (const model of job.models) {
        counts[model] = (counts[model] ?? 0) + 1
      }
    }

    return counts
  })

  function select(id: string | null) {
    selectedId.value = id
  }

  function toggleKind(kind: string) {
    kinds.value = kinds.value.includes(kind)
      ? kinds.value.filter((k) => k !== kind)
      : [...kinds.value, kind]
  }

  function toggleQueue(queue: string) {
    queues.value = queues.value.includes(queue)
      ? queues.value.filter((q) => q !== queue)
      : [...queues.value, queue]
  }

  /** Arriving from a model card: one model, and nothing else in the way. */
  function filterByModel(model: string | null) {
    modelFilter.value = model
    search.value = ''
    kinds.value = []
    queues.value = []
    undispatchedOnly.value = false
    selectedId.value = null
  }

  /**
   * Arriving from somewhere that named one job — an endpoint's list of what it
   * queues.
   *
   * Filters are cleared first, and the view scope with them: landing on this
   * surface with the job you asked for filtered out of it is the one thing a
   * link like this must not do.
   */
  function focusJob(id: string) {
    clearFilters()
    scopeToView.value = false
    selectedId.value = id
  }

  function clearFilters() {
    search.value = ''
    kinds.value = []
    queues.value = []
    modelFilter.value = null
    undispatchedOnly.value = false
  }

  function endpoint(): string {
    return bootstrap().jobsUrl ?? `${import.meta.env.BASE_URL}jobs.json`
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

      const payload = (await res.json()) as Partial<JobsFile> & { fingerprint?: string }
      if (!Array.isArray(payload?.jobs)) {
        throw new Error('Expected an object with a "jobs" array')
      }

      jobs.value = payload.jobs
      fingerprint.value = payload.fingerprint ?? null
      status.value = 'ready'
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      status.value = 'error'
    }
  }

  /**
   * Pulls a fresh list and swaps it in, leaving the current one alone if it
   * cannot — the same bargain the other two stores make. A failed refresh must
   * not replace a working list with an error card.
   *
   * The selection survives by id, so a job somebody is reading stays open
   * across a re-export unless the class itself is gone.
   */
  async function refresh(): Promise<boolean> {
    try {
      const res = await fetch(endpoint(), { cache: 'no-store' })
      if (!res.ok) return false

      const payload = (await res.json()) as Partial<JobsFile> & { fingerprint?: string }
      if (!Array.isArray(payload?.jobs)) return false

      jobs.value = payload.jobs
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
    jobs,
    loaded,
    fingerprint,
    search,
    kinds,
    queues,
    undispatchedOnly,
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
    toggleKind,
    toggleQueue,
    filterByModel,
    focusJob,
    clearFilters,
    load,
    refresh,
  }
})
