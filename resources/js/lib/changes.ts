import type { PageId } from '@/pages/registry'
import type { BaselineName, Change, ChangesFile, Part, Severity } from '@/types/changes'

/**
 * How the Changes page groups, colours and words a report.
 *
 * What changed and how bad it is are both decided on the server, so the page,
 * `dissect:diff` and `dissect_changes` never disagree. What is left here is
 * presentation, kept pure so it can be tested without mounting anything.
 */

/** Worst first, as everywhere else that lists them. */
export const SEVERITIES: readonly Severity[] = ['breaking', 'risky', 'additive']

interface PartSpec {
  label: string
  /** Where an item of this part is shown, for "open". */
  page: PageId
}

/** In the order the sidebar lists their pages. */
export const PARTS: Readonly<Record<Part, PartSpec>> = {
  models: { label: 'Models', page: 'models' },
  routes: { label: 'Routes', page: 'routes' },
  jobs: { label: 'Jobs', page: 'jobs' },
  providers: { label: 'Service providers', page: 'providertree' },
}

/**
 * Colour per severity, as a theme token. Redundant by construction: the word
 * is always written beside it.
 */
export function severityColor(severity: Severity): string {
  switch (severity) {
    case 'breaking':
      return 'var(--destructive)'
    case 'risky':
      return 'var(--chart-1)'
    default:
      return 'var(--chart-4)'
  }
}

export interface PartGroup {
  part: Part
  label: string
  changes: Change[]
}

/**
 * The changes to show, grouped by part in sidebar order. The server already
 * sorts each part worst first, and that order is kept.
 */
export function groupByPart(changes: Change[], shown: ReadonlySet<Severity>): PartGroup[] {
  const groups: PartGroup[] = []

  for (const part of Object.keys(PARTS) as Part[]) {
    const inPart = changes.filter((c) => c.part === part && shown.has(c.severity))

    if (inPart.length) groups.push({ part, label: PARTS[part].label, changes: inPart })
  }

  return groups
}

/** "since watching started", "since the snapshot". */
export function since(name: BaselineName | null): string {
  if (name === 'watch') return 'since watching started'
  if (name === 'snapshot') return 'since the snapshot'

  return ''
}

/**
 * One line for the header: "3 changes since the snapshot · 1 breaking".
 * Only the worst kind present is named, since that is what decides whether
 * anybody needs to look.
 */
export function headline(file: ChangesFile): string {
  if (!file.against) return 'What changed'

  const count = file.changes.length
  if (!count) return `Nothing changed ${since(file.against)}`

  const worst = SEVERITIES.find((s) => file.summary[s] > 0)
  const line = `${count} ${count === 1 ? 'change' : 'changes'} ${since(file.against)}`

  return worst ? `${line} · ${file.summary[worst]} ${worst}` : line
}

/**
 * Whether an item can be opened on its own page. One that was removed is
 * no longer on any page, and opening it would land on nothing.
 */
export function canOpen(change: Change): boolean {
  return change.change !== 'removed'
}

/** The "+ ", "- " or "~ " the server starts its text with, split off. */
export function splitMark(text: string): { mark: string; rest: string } {
  const match = /^([+\-~]) (.*)$/s.exec(text)

  return match ? { mark: match[1]!, rest: match[2]! } : { mark: '', rest: text }
}
