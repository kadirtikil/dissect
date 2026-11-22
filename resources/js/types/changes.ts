/**
 * Shape of changes.json, as exported from the Laravel side.
 *
 * What changed in the models, routes, jobs and providers since a baseline:
 * since somebody started watching, or since the committed snapshot. Judged
 * and worded on the server (Changes\Report), so the page, `dissect:diff` and
 * an agent's `dissect_changes` say the same thing.
 */

export type Severity = 'breaking' | 'risky' | 'additive'

export type Part = 'models' | 'routes' | 'jobs' | 'providers'

export type BaselineName = 'watch' | 'snapshot'

/** One fact of a changed item. */
export interface ChangedFact {
  /** Where in the item: ["columns", "total", "nullable"]. */
  path: string[]
  change: 'added' | 'removed' | 'changed'
  before: unknown
  after: unknown
  severity: Severity
  /** "~ column total: now nullable". */
  text: string
}

/** One model, route, job or provider that is new, gone or different. */
export interface Change {
  part: Part
  /** The id the item has on its own page: a class, or `POST:api/x`. */
  id: string
  /** As a reader names it: a short class name, or `POST api/x`. */
  name: string
  change: 'added' | 'removed' | 'changed'
  /** The worst of its facts, or its own for one new or gone. */
  severity: Severity
  /** "~ model Invoice". */
  text: string
  facts: ChangedFact[]
}

export interface Baseline {
  exists: boolean
  /** Relative to the app when inside it. */
  path: string
  /** ISO 8601, from the file's own time. */
  taken_at: string | null
}

export interface ChangesFile {
  /** The baseline compared against, or null when there is none. */
  against: BaselineName | null
  baselines: Record<BaselineName, Baseline>
  /** Why the baseline could not be read, in words. */
  problem: string | null
  summary: Record<Severity, number>
  changes: Change[]
}
