import type { GraphView } from '@/types/schema'

/**
 * The views the host says it kept, or null when it does not say. The
 * standalone dev server does not; the package does.
 */
export function keptViews(answer: unknown): GraphView[] | null {
  const views = (answer as { views?: unknown } | null)?.views
  if (!Array.isArray(views)) return null

  return views.filter(
    (v): v is GraphView =>
      typeof v?.id === 'string' && typeof v?.name === 'string' && Array.isArray(v?.models),
  )
}

/**
 * What a save sent and the host did not keep, in words, or null when it kept
 * it all.
 *
 * The host caps how many views a file holds and how many models a view does.
 * This page never sends an empty or duplicate view, which are the only other
 * things it drops, so what is missing was over a cap, and what was kept says
 * where the cap is.
 */
export function lostInSave(sent: GraphView[], kept: GraphView[]): string | null {
  const byId = new Map(kept.map((v) => [v.id, v]))
  const lines: string[] = []

  const dropped = sent.filter((v) => !byId.has(v.id))
  if (dropped.length) {
    const names = dropped.map((v) => `"${v.name}"`).join(', ')
    lines.push(
      `${names} ${dropped.length === 1 ? 'was' : 'were'} not saved: views.json holds at most ${kept.length} views.`,
    )
  }

  for (const view of sent) {
    const was = byId.get(view.id)
    if (was && was.models.length < view.models.length) {
      lines.push(
        `"${view.name}" was saved with ${was.models.length} of its ${view.models.length} models, the most a view holds.`,
      )
    }
  }

  return lines.length ? lines.join(' ') : null
}
