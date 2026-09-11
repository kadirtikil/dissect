/**
 * Colour and label per queueable kind, drawn from the same categorical ramp the
 * relation families and HTTP verbs use, so the three surfaces read as one
 * product.
 *
 * Colour is redundant here by construction: the kind is written out next to it
 * wherever it appears, so the palette speeds up scanning a long list without
 * ever being the only thing carrying the meaning.
 */

interface KindSpec {
  /** Design token, resolved against the active theme at render time. */
  color: string
  label: string
}

// Fixed order: the shape people mean when they say "a queued thing" first, then
// the three that are queueable because of what they do rather than what they are.
const KINDS: Record<string, KindSpec> = {
  job: { color: 'var(--chart-1)', label: 'Job' },
  listener: { color: 'var(--chart-2)', label: 'Listener' },
  mailable: { color: 'var(--chart-3)', label: 'Mailable' },
  notification: { color: 'var(--chart-4)', label: 'Notification' },
}

const FALLBACK: KindSpec = { color: 'var(--muted-foreground)', label: 'Queueable' }

export function kindSpec(kind: string): KindSpec {
  return KINDS[kind] ?? FALLBACK
}

export function kindColor(kind: string): string {
  return kindSpec(kind).color
}

const ORDER = Object.keys(KINDS)

export function kindRank(kind: string): number {
  const index = ORDER.indexOf(kind)
  return index === -1 ? ORDER.length : index
}

/**
 * How the queue name was arrived at, in words.
 *
 * The export refuses to smooth this over — a job using `Queueable` cannot
 * declare a `$queue` property, so most queue names come from somewhere other
 * than the class's own field — and the surface says which somewhere.
 */
export function queueSourceLabel(source: string): string | null {
  switch (source) {
    case 'property':
      return 'declared as a property'
    case 'constructor':
      return 'set in the constructor'
    case 'dispatch':
      return 'named at the dispatch site'
    case 'mixed':
      return 'dispatched onto more than one queue'
    default:
      return null
  }
}
