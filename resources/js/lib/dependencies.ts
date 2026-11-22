import type { ClassEntry } from '@/types/classes'

/**
 * How domains depend on each other, read off the class index.
 *
 * A domain depends on another when one of its classes names one of the
 * other's. A class in no domain says nothing about where a boundary is, so a
 * reference to or from one never counts, the same rule a crossing relation on
 * the graph follows.
 */

/** One class naming another across a boundary. */
export interface CrossingReference {
  /** The class that names the other. */
  from: string
  /** Where that happens, relative to the app root. */
  file: string
  to: string
  kind: string
  lines: number[]
}

/** Everything between one domain and one other, in one direction. */
export interface DomainLink {
  /** The other domain. */
  domain: string
  /** References, one per class pair and kind: what the digest counts too. */
  count: number
  /** Count per kind, most first. */
  kinds: [kind: string, count: number][]
  references: CrossingReference[]
}

export interface DomainDependencies {
  /** Domains this one's classes name, most-named first. */
  dependsOn: DomainLink[]
  /** Domains whose classes name this one's. */
  usedBy: DomainLink[]
}

const NONE: DomainDependencies = { dependsOn: [], usedBy: [] }

export function domainDependencies(
  classes: readonly ClassEntry[],
): Map<string, DomainDependencies> {
  const contexts = new Map(classes.map((c) => [c.id, c.context]))

  // from domain → to domain → references
  const pairs = new Map<string, Map<string, CrossingReference[]>>()

  for (const entry of classes) {
    const from = entry.context
    if (!from) continue

    for (const use of entry.uses) {
      const to = contexts.get(use.to)
      if (!to || to === from) continue

      const row = pairs.get(from) ?? new Map<string, CrossingReference[]>()
      pairs.set(from, row)
      row.set(to, [
        ...(row.get(to) ?? []),
        { from: entry.id, file: entry.file, to: use.to, kind: use.kind, lines: use.lines },
      ])
    }
  }

  const result = new Map<string, DomainDependencies>()
  const entry = (name: string) => {
    const deps = result.get(name) ?? { dependsOn: [], usedBy: [] }
    result.set(name, deps)
    return deps
  }

  for (const [from, row] of pairs) {
    for (const [to, references] of row) {
      references.sort(
        (a, b) =>
          a.from.localeCompare(b.from) || a.to.localeCompare(b.to) || a.kind.localeCompare(b.kind),
      )

      entry(from).dependsOn.push(link(to, references))
      entry(to).usedBy.push(link(from, references))
    }
  }

  for (const deps of result.values()) {
    deps.dependsOn.sort(byCount)
    deps.usedBy.sort(byCount)
  }

  return result
}

/** A domain's links, or none: most domains depend on nothing. */
export function dependenciesOf(
  all: ReadonlyMap<string, DomainDependencies>,
  domain: string,
): DomainDependencies {
  return all.get(domain) ?? NONE
}

/** Two domains that each name the other: the boundary a cycle runs through. */
export function isMutual(deps: DomainDependencies, other: string): boolean {
  return (
    deps.dependsOn.some((l) => l.domain === other) && deps.usedBy.some((l) => l.domain === other)
  )
}

function link(domain: string, references: CrossingReference[]): DomainLink {
  const counts = new Map<string, number>()
  for (const ref of references) counts.set(ref.kind, (counts.get(ref.kind) ?? 0) + 1)

  return {
    domain,
    count: references.length,
    kinds: [...counts].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])),
    references,
  }
}

function byCount(a: DomainLink, b: DomainLink): number {
  return b.count - a.count || a.domain.localeCompare(b.domain)
}
