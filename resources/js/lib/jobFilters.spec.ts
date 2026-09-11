import { describe, expect, it } from 'vitest'
import type { QueueJob } from '@/types/jobs'
import {
  DEFAULT_QUEUE,
  EMPTY_FILTERS,
  MIXED_QUEUE,
  facetCounts,
  filterJobs,
  groupJobs,
  isUndispatched,
  matchesSearch,
  queueKey,
} from '@/lib/jobFilters'

/** A job with everything empty, so each test states only what it is about. */
function job(overrides: Partial<QueueJob> = {}): QueueJob {
  return {
    id: 'App\\Jobs\\Thing',
    class: 'App\\Jobs\\Thing',
    name: 'Thing',
    kind: 'job',
    queue: null,
    queue_source: 'default',
    connection: null,
    retry: { tries: null, timeout: null, maxExceptions: null, backoff: null, retryUntil: false },
    traits: { batchable: false, unique: false, encrypted: false, afterCommit: false },
    payload: [],
    middleware: [],
    events: [],
    confidence: 'certain',
    dispatched_by: [],
    models: [],
    ...overrides,
  }
}

function site(overrides: Partial<QueueJob['dispatched_by'][number]> = {}) {
  return {
    file: 'app/Http/Controllers/ThingController.php',
    line: 10,
    label: 'ThingController@store',
    context: 'App\\Http\\Controllers\\ThingController@store',
    method: 'dispatch',
    queue: null,
    connection: null,
    delayed: false,
    afterCommit: false,
    route: null,
    ...overrides,
  }
}

describe('queueKey', () => {
  it('uses the declared queue', () => {
    expect(queueKey(job({ queue: 'mail' }))).toBe('mail')
  })

  it('buckets an undeclared queue as default', () => {
    // Not a placeholder: Laravel's default queue is genuinely named "default",
    // so this job and one that says `default` land on the same worker.
    expect(queueKey(job())).toBe(DEFAULT_QUEUE)
  })

  it('keeps a job dispatched onto several queues in its own bucket', () => {
    // The export refuses to pick between them, and so does this.
    expect(queueKey(job({ queue: null, queue_source: 'mixed' }))).toBe(MIXED_QUEUE)
  })
})

describe('isUndispatched', () => {
  it('is true only when no site was found', () => {
    expect(isUndispatched(job())).toBe(true)
    expect(isUndispatched(job({ dispatched_by: [site()] }))).toBe(false)
  })
})

describe('matchesSearch', () => {
  it('matches the name and the class', () => {
    const target = job({ name: 'SendInvoice', class: 'App\\Jobs\\SendInvoice' })

    expect(matchesSearch(target, 'invoice')).toBe(true)
    expect(matchesSearch(target, 'app\\jobs')).toBe(true)
    expect(matchesSearch(target, 'refund')).toBe(false)
  })

  it('matches the queue', () => {
    expect(matchesSearch(job({ queue: 'billing' }), 'billing')).toBe(true)
  })

  it('matches what dispatches it', () => {
    // Half of why anybody looks a job up is what puts it on the queue.
    const target = job({ dispatched_by: [site({ label: 'InvoiceController@store' })] })

    expect(matchesSearch(target, 'invoicecontroller')).toBe(true)
  })

  it('matches everything when the term is blank', () => {
    expect(matchesSearch(job(), '   ')).toBe(true)
  })
})

describe('filterJobs', () => {
  const jobs = [
    job({ id: 'a', name: 'A', kind: 'job', queue: 'mail', models: ['Invoice'] }),
    job({ id: 'b', name: 'B', kind: 'listener', queue: 'listeners', dispatched_by: [site()] }),
    job({ id: 'c', name: 'C', kind: 'mailable', queue: 'mail', models: ['Customer'] }),
  ]

  it('narrows by kind', () => {
    expect(filterJobs(jobs, { ...EMPTY_FILTERS, kinds: ['mailable'] }).map((j) => j.id)).toEqual([
      'c',
    ])
  })

  it('narrows by queue', () => {
    expect(filterJobs(jobs, { ...EMPTY_FILTERS, queues: ['mail'] }).map((j) => j.id)).toEqual([
      'a',
      'c',
    ])
  })

  it('narrows by model', () => {
    expect(filterJobs(jobs, { ...EMPTY_FILTERS, models: ['Invoice'] }).map((j) => j.id)).toEqual([
      'a',
    ])
  })

  it('treats a null model filter as no filter at all', () => {
    // Distinct from an empty list, which would match nothing.
    expect(filterJobs(jobs, { ...EMPTY_FILTERS, models: null })).toHaveLength(3)
    expect(filterJobs(jobs, { ...EMPTY_FILTERS, models: [] })).toHaveLength(0)
  })

  it('narrows to what nothing dispatches', () => {
    expect(filterJobs(jobs, { ...EMPTY_FILTERS, undispatchedOnly: true }).map((j) => j.id)).toEqual(
      ['a', 'c'],
    )
  })
})

describe('facetCounts', () => {
  const jobs = [
    job({ id: 'a', kind: 'job', queue: 'mail' }),
    job({ id: 'b', kind: 'listener', queue: 'mail' }),
    job({ id: 'c', kind: 'job', queue: 'indexing', dispatched_by: [site()] }),
  ]

  it('excludes a facet from its own counts', () => {
    // With `job` selected, the listener chip must still say how many listeners
    // there are, or it reads as "there are none" and becomes a dead end.
    const counts = facetCounts(jobs, { ...EMPTY_FILTERS, kinds: ['job'] })

    expect(counts.kinds).toEqual({ job: 2, listener: 1 })
  })

  it('narrows one facet by the other', () => {
    const counts = facetCounts(jobs, { ...EMPTY_FILTERS, kinds: ['job'] })

    expect(counts.queues).toEqual({ mail: 1, indexing: 1 })
  })

  it('counts what nothing dispatches within everything else selected', () => {
    expect(facetCounts(jobs, EMPTY_FILTERS).undispatched).toBe(2)
    expect(facetCounts(jobs, { ...EMPTY_FILTERS, kinds: ['listener'] }).undispatched).toBe(1)
  })
})

describe('groupJobs', () => {
  it('groups by queue and sorts real queues above the two that are not', () => {
    const grouped = groupJobs([
      job({ id: 'a', queue: null }),
      job({ id: 'b', queue: null, queue_source: 'mixed' }),
      job({ id: 'c', queue: 'mail' }),
      job({ id: 'd', queue: 'indexing' }),
    ])

    // Everything above `default` is somewhere a worker can actually be pointed.
    expect(grouped.map((g) => g.key)).toEqual(['indexing', 'mail', DEFAULT_QUEUE, MIXED_QUEUE])
  })

  it('keeps every job in exactly one bucket', () => {
    const grouped = groupJobs([job({ id: 'a', queue: 'mail' }), job({ id: 'b', queue: 'mail' })])

    expect(grouped).toHaveLength(1)
    expect(grouped[0]?.jobs.map((j) => j.id)).toEqual(['a', 'b'])
  })
})
