import type { QueueJob } from '@/types/jobs'

/**
 * Narrowing the job list.
 *
 * Every queueable class is exported — nothing is hidden on the PHP side — so
 * this is where an application's several dozen become the handful somebody is
 * looking at. Kept pure and separate from the store so it can be tested without
 * mounting anything, which is the shape lib/routeFilters.ts already has and
 * stores/schema.ts still wants.
 */

/**
 * The bucket a job with no declared queue belongs to.
 *
 * Not a placeholder: Laravel's default queue is genuinely *named* `default`, so
 * a job that names nothing and a job that names `default` land on the same
 * worker and belong under the same heading.
 */
export const DEFAULT_QUEUE = 'default'

/** Two dispatch sites naming two different queues — see the exporter. */
export const MIXED_QUEUE = 'mixed'

export interface JobFilterState {
  search: string
  /** Empty means every kind (job / listener / mailable / notification). */
  kinds: string[]
  /** Empty means every queue. Keys come from {@see queueKey}. */
  queues: string[]
  /** Node ids. Null means no model filter at all — not "an empty set". */
  models: string[] | null
  /** Only jobs no dispatch site was found for. */
  undispatchedOnly: boolean
}

export const EMPTY_FILTERS: JobFilterState = {
  search: '',
  kinds: [],
  queues: [],
  models: null,
  undispatchedOnly: false,
}

/**
 * Which queue heading a job belongs under.
 *
 * `mixed` is its own bucket rather than being resolved to one of the queues it
 * was dispatched onto: the export refuses to pick between them, and so does
 * this.
 */
export function queueKey(job: QueueJob): string {
  if (job.queue) return job.queue

  return job.queue_source === 'mixed' ? MIXED_QUEUE : DEFAULT_QUEUE
}

/** A job nothing was found to dispatch — dead code, or dispatched dynamically. */
export function isUndispatched(job: QueueJob): boolean {
  return job.dispatched_by.length === 0
}

export function matchesSearch(job: QueueJob, search: string): boolean {
  const term = search.trim().toLowerCase()
  if (!term) return true

  return (
    job.name.toLowerCase().includes(term) ||
    job.class.toLowerCase().includes(term) ||
    (job.queue?.toLowerCase().includes(term) ?? false) ||
    // What dispatches a job is half of why anybody is looking for it, so the
    // call sites are searchable text too.
    job.dispatched_by.some((site) => site.label.toLowerCase().includes(term))
  )
}

export function matchesKinds(job: QueueJob, kinds: string[]): boolean {
  return kinds.length === 0 || kinds.includes(job.kind)
}

export function matchesQueues(job: QueueJob, queues: string[]): boolean {
  return queues.length === 0 || queues.includes(queueKey(job))
}

/** A job matches a model filter when it carries at least one of the models. */
export function matchesModels(job: QueueJob, models: string[] | null): boolean {
  if (models === null) return true
  return job.models.some((m) => models.includes(m))
}

export function matchesJob(job: QueueJob, state: JobFilterState): boolean {
  return (
    matchesSearch(job, state.search) &&
    matchesKinds(job, state.kinds) &&
    matchesQueues(job, state.queues) &&
    matchesModels(job, state.models) &&
    (!state.undispatchedOnly || isUndispatched(job))
  )
}

export function filterJobs(jobs: QueueJob[], state: JobFilterState): QueueJob[] {
  return jobs.filter((job) => matchesJob(job, state))
}

/**
 * Counts beside each facet option.
 *
 * A facet's own selection is deliberately excluded from its counts: with
 * `listener` selected, the `job` chip should still say how many jobs there are,
 * or it reads as "there are none" and the filter becomes a dead end.
 */
export function facetCounts(
  jobs: QueueJob[],
  state: JobFilterState,
): { kinds: Record<string, number>; queues: Record<string, number>; undispatched: number } {
  const kinds: Record<string, number> = {}
  const queues: Record<string, number> = {}
  let undispatched = 0

  for (const job of jobs) {
    const base =
      matchesSearch(job, state.search) &&
      matchesModels(job, state.models) &&
      (!state.undispatchedOnly || isUndispatched(job))

    if (base && matchesQueues(job, state.queues)) {
      kinds[job.kind] = (kinds[job.kind] ?? 0) + 1
    }

    if (base && matchesKinds(job, state.kinds)) {
      const key = queueKey(job)
      queues[key] = (queues[key] ?? 0) + 1
    }

    // The undispatched count answers "is this worth looking at", so it ignores
    // its own toggle and counts within everything else that is selected.
    if (
      matchesSearch(job, state.search) &&
      matchesModels(job, state.models) &&
      matchesKinds(job, state.kinds) &&
      matchesQueues(job, state.queues) &&
      isUndispatched(job)
    ) {
      undispatched++
    }
  }

  return { kinds, queues, undispatched }
}

export interface JobGrouping {
  /** Stable key for the collapse state and the v-for. */
  key: string
  label: string
  jobs: QueueJob[]
}

/**
 * Buckets the list by queue.
 *
 * The queue is the unit people think in here, because it is the unit the
 * *worker* is configured in — "who is draining `mail`" is a question with an
 * operational answer, in a way that "who is draining the mailables" is not.
 * Grouping by kind would sort the list by what the classes are rather than by
 * where they run.
 */
export function groupJobs(jobs: QueueJob[]): JobGrouping[] {
  const buckets = new Map<string, JobGrouping>()

  for (const job of jobs) {
    const key = queueKey(job)
    const bucket = buckets.get(key)

    if (bucket) bucket.jobs.push(job)
    else buckets.set(key, { key, label: key, jobs: [job] })
  }

  return [...buckets.values()].sort((a, b) => {
    // The two buckets that are not really queue names sort last, in that order:
    // everything above them is somewhere a worker can be pointed.
    const rank = (key: string) => (key === DEFAULT_QUEUE ? 1 : key === MIXED_QUEUE ? 2 : 0)

    return rank(a.key) - rank(b.key) || a.label.localeCompare(b.label)
  })
}
