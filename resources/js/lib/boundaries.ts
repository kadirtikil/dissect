import type { BoundaryRule, Violation } from '@/types/boundaries'

/**
 * What a boundary rule allows, and how it reads.
 *
 * The same reading the server checks with (BoundaryRule::allows), so a link
 * drawn as forbidden here is one `dissect:check` fails on.
 */

/** The rule for a domain, or null: a domain without one is not checked. */
export function ruleFor(rules: readonly BoundaryRule[], domain: string): BoundaryRule | null {
  return rules.find((rule) => rule.domain === domain) ?? null
}

/**
 * Whether a class in `domain` may name one in `other`.
 *
 * True with no rule, with no `may_use` (every domain allowed), and always
 * for the domain itself.
 */
export function allows(rule: BoundaryRule | null, domain: string, other: string): boolean {
  return !rule || rule.may_use === null || other === domain || rule.may_use.includes(other)
}

/** One line, as the Markdown digest writes it: "may use Billing; must not use Illuminate\Http". */
export function describeRule(rule: BoundaryRule): string {
  const parts: string[] = []

  if (rule.may_use !== null) {
    parts.push(
      rule.may_use.length ? `may use ${rule.may_use.join(', ')}` : 'may use no other domain',
    )
  }

  if (rule.must_not_use.length) parts.push(`must not use ${rule.must_not_use.join(', ')}`)

  return parts.length ? parts.join('; ') : 'an empty rule'
}

/** Violations by the domain whose rule they break, in the order given. */
export function violationsByDomain(violations: readonly Violation[]): Map<string, Violation[]> {
  const byDomain = new Map<string, Violation[]>()

  for (const violation of violations) {
    byDomain.set(violation.domain, [...(byDomain.get(violation.domain) ?? []), violation])
  }

  return byDomain
}
