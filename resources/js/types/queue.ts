/**
 * Shape of queue.json — the one payload here that is runtime state rather than
 * a fact about the code.
 *
 * Everything else the viewer loads is derived from source and true until
 * somebody edits a file. This is true for as long as it took to read, which is
 * why it is polled, never cached, and carries the time it was taken.
 */

export interface QueueDepth {
  name: string
  waiting: number
  delayed: number
  reserved: number
}

export interface QueueRow {
  /** Table id, or the payload's uuid on a driver that has no rows. */
  id: string | null
  uuid: string | null
  /** The FQCN — the same id jobs.json uses, and so the join to that surface. */
  job: string | null
  name: string
  queue: string | null
  attempts: number
  maxTries: number | null
  queued_at: string | null
  available_at?: string | null
  reserved_at?: string | null
  reserved_until?: string | null
  failed_at?: string | null
  connection?: string | null
  /** First line only: the exception and its message, never the whole trace. */
  exception?: string | null
}

export interface QueueSection {
  rows: QueueRow[]
  /** The real depth, which is not the same as how many rows came back. */
  total: number
  truncated: boolean
  /**
   * Why this section could not be read, when it could not be.
   *
   * Only the history is ever unreadable on its own — a missing `failed_jobs`
   * table, say. Null everywhere else, so the client has one shape to render:
   * an empty section and an unreadable one must not look the same.
   */
  unreadable: string | null
}

export interface QueueBatch {
  id: string
  name: string
  total: number
  pending: number
  failed: number
  created_at: string | null
  finished_at: string | null
  cancelled_at: string | null
}

export interface QueueFile {
  connection: string
  driver: string
  /** Every configured connection, so the surface can offer a switcher. */
  connections: string[]
  /** False for a driver that cannot be enumerated, or storage that is missing. */
  readable: boolean
  /** Why, in words. Null when it is readable. */
  refusal: string | null
  queues: QueueDepth[]
  now: { waiting: QueueSection; reserved: QueueSection }
  next: { delayed: QueueSection }
  past: { failed: QueueSection; batches: QueueBatch[] }
  limit: number
  /**
   * Always false, and on the wire deliberately: Laravel records nothing at all
   * about a job that succeeded. An empty history means nothing failed, not that
   * nothing ran, and the client should not have to know that on its own.
   */
  records_completions: boolean
  read_at: string
}
