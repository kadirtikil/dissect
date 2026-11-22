/**
 * Shape of boundaries.json, as exported from the Laravel side.
 *
 * The boundary rules written in `.dissect/domains.json`, and every place the
 * code breaks one. A domain with no rule is never checked.
 */

/** One domain's rule. Mirrors BoundaryRule::toArray(). */
export interface BoundaryRule {
  domain: string
  /** Other domains it may use; null when every domain is allowed. */
  may_use: string[] | null
  /** Namespaces (or classes) none of its classes may name. */
  must_not_use: string[]
}

export interface Violation {
  /** Which half of the rule was broken. */
  rule: 'may_use' | 'must_not_use' | string
  /** The domain whose rule it is: the one the referring class is in. */
  domain: string
  from: string
  /** Relative to the app root. */
  file: string
  to: string
  /** The domain of the class named, if it is in one. */
  to_domain: string | null
  /** The must_not_use entry that matched, for that rule. */
  namespace: string | null
  kind: string
  lines: number[]
  message: string
}

export interface BoundariesFile {
  /** False when `domains.enabled` is off in config. */
  enabled: boolean
  rules: BoundaryRule[]
  violations: Violation[]
  /** Rules that name a domain nothing defines. */
  problems: string[]
  /** Files that could not be parsed, so were not checked. */
  unchecked: string[]
  generated_at?: string
}
