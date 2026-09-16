import type { Confidence } from '@/types/routes'

/**
 * Colour and wording for a provider tree, drawn from the same categorical ramp
 * the relation families, HTTP verbs and job kinds use.
 *
 * Every colour is redundant by construction — a node writes its kind out, an
 * edge carries its kind as a label — so the palette speeds up reading a tree
 * without ever being the only thing carrying the meaning.
 */

interface Spec {
  /** Design token, resolved against the active theme at render time. */
  color: string
  label: string
}

const NODE_KINDS: Record<string, Spec> = {
  provider: { color: 'var(--primary)', label: 'provider' },
  contract: { color: 'var(--chart-3)', label: 'contract' },
  concrete: { color: 'var(--chart-1)', label: 'class' },
  unresolved: { color: 'var(--muted-foreground)', label: 'unresolved' },
}

export function nodeKindSpec(kind: string): Spec {
  return NODE_KINDS[kind] ?? { color: 'var(--muted-foreground)', label: kind }
}

/**
 * Edges are coloured by what kind of decision they are, not one colour each:
 * nine hues would be a legend nobody reads. The four container calls are the
 * same act — the provider deciding what a name resolves to — and share one.
 */
const EDGE_KINDS: Record<string, Spec> = {
  bind: { color: 'var(--chart-3)', label: 'bind' },
  singleton: { color: 'var(--chart-3)', label: 'singleton' },
  scoped: { color: 'var(--chart-3)', label: 'scoped' },
  instance: { color: 'var(--chart-3)', label: 'instance' },
  contextual: { color: 'var(--chart-5)', label: 'contextual' },
  registers: { color: 'var(--primary)', label: 'registers' },
  resolves: { color: 'var(--chart-4)', label: 'resolves' },
  injects: { color: 'var(--chart-1)', label: 'injects' },
  calls: { color: 'var(--muted-foreground)', label: 'calls' },
}

export function edgeKindSpec(kind: string): Spec {
  return EDGE_KINDS[kind] ?? { color: 'var(--muted-foreground)', label: kind }
}

/**
 * Confidence is the line, not the colour: solid for what was written
 * literally, dashed for what a constructor implied, dotted for what could not
 * be read at all.
 */
export function confidenceDash(confidence: Confidence | string): string | undefined {
  if (confidence === 'inferred') return '6 4'
  if (confidence === 'unknown') return '2 3'
  return undefined
}

export const CONFIDENCE_NOTE: Record<Confidence, string> = {
  certain: 'Written literally in the provider.',
  inferred: 'Implied by a constructor type-hint or a closure, not stated outright.',
  unknown: 'Seen, but could not be read statically.',
}

export const SIDE_EFFECT_LABELS: Record<string, string> = {
  events: 'events',
  gates: 'gates',
  config: 'config',
  publishes: 'publishes',
  migrations: 'migrations',
  routes: 'routes',
  views: 'views',
  commands: 'commands',
}

export const ORIGIN_NOTE: Record<string, string> = {
  app: '',
  framework: 'Framework code — not followed further.',
  vendor: 'Package code — not followed further.',
  none: 'A container key, not a class.',
}
