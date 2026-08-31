/**
 * Shape of jobs.json, as exported from the Laravel side.
 *
 * Two fields carry the joins, and they are why this surface belongs in dissect
 * rather than beside it: `models` holds node ids from schema.json, and
 * `dispatched_by[].route` holds route ids from routes.json. Everything else
 * describes one queueable class.
 *
 * Kept deliberately close to the wire format, like types/routes.ts.
 */

import type { Confidence } from '@/types/routes'

/** The four shapes that all implement ShouldQueue and all reach the same worker. */
export type JobKind = 'job' | 'listener' | 'mailable' | 'notification'

/**
 * Where the queue name came from.
 *
 * On the wire rather than smoothed over, because a job using the `Queueable`
 * trait cannot declare a `$queue` property at all — PHP rejects it — so the
 * answer usually comes from the constructor or from the dispatch site, and how
 * far it travelled is worth knowing.
 */
export type QueueSource = 'property' | 'constructor' | 'dispatch' | 'mixed' | 'default'

export interface JobRetry {
  tries: number | null
  timeout: number | null
  maxExceptions: number | null
  /** One int, a list, or a method returning either — always a list here. */
  backoff: number[] | null
  /** Whether a deadline is set. Not when: it is computed at queue time. */
  retryUntil: boolean
}

export interface JobTraits {
  batchable: boolean
  unique: boolean
  encrypted: boolean
  afterCommit: boolean
}

export interface JobPayloadField {
  name: string
  /** The type hint verbatim, including a union, which links to nothing. */
  type: string | null
  /** Node id, when the hint names a model the graph holds. */
  model: string | null
  optional: boolean
  variadic: boolean
  default: string | number | boolean | null
}

export interface DispatchSite {
  /** Relative to the application root, so it is a path you can open. */
  file: string
  /** Where the dispatch is written — not where its enclosing action starts. */
  line: number
  /** `InvoiceController@store`, or `api.php:22` for a closure. */
  label: string
  /** The fully qualified join key. Shown to nobody; it addresses the route. */
  context: string
  /** The call that queued it: `dispatch`, `queue`, `notify`, `batch`, `new`… */
  method: string
  /** Only when chained as a literal — `->onQueue($tenant->queue)` names nothing. */
  queue: string | null
  connection: string | null
  delayed: boolean
  afterCommit: boolean
  /** Route id from routes.json, when this site sits inside an endpoint. */
  route: string | null
}

export interface QueueJob {
  /** The FQCN. Unlike a route, a class is unique on its own. */
  id: string
  class: string
  name: string
  kind: JobKind | string
  queue: string | null
  queue_source: QueueSource | string
  connection: string | null
  retry: JobRetry
  traits: JobTraits
  /** The constructor signature — literally what is serialised onto the queue. */
  payload: JobPayloadField[]
  middleware: string[]
  /** Events a listener is registered against; empty for everything else. */
  events: string[]
  confidence: Confidence
  /** Empty means "no site was found", which is not the same as "never runs". */
  dispatched_by: DispatchSite[]
  /** Node ids this job carries — the join back to the graph. */
  models: string[]
}

export interface JobsFile {
  jobs: QueueJob[]
  generated_at?: string
}
