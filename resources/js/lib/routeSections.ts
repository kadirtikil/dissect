/**
 * Colour per section of an endpoint's detail pane, drawn from the same
 * categorical ramp the HTTP verbs, job kinds and relation families use, so the
 * surfaces read as one product.
 *
 * **The mapping is by section, never by endpoint.** `Request` is the same blue
 * on every route in the list, `Response` the same teal. Selecting a different
 * endpoint changes what the pane says and nothing about how it is laid out or
 * coloured — a palette that reshuffled per selection would make two endpoints
 * look like two different screens, and the pane is meant to be read by shape
 * once somebody has learned it.
 *
 * Colour is redundant by construction: every section keeps its heading, so this
 * speeds up finding the right block without ever being the only thing carrying
 * the meaning.
 *
 * Hues are ordered so that no two adjacent sections sit near each other on the
 * wheel — the ramp is validated for categorical separation, but adjacency is
 * what the eye actually compares.
 */

export type RouteSection = 'endpoint' | 'middleware' | 'parameters' | 'request' | 'response'

interface SectionSpec {
  /** Design token, resolved against the active theme at render time. */
  color: string
  label: string
}

const SECTIONS: Record<RouteSection, SectionSpec> = {
  endpoint: { color: 'var(--chart-5)', label: 'Endpoint' },
  middleware: { color: 'var(--chart-1)', label: 'Middleware' },
  parameters: { color: 'var(--chart-2)', label: 'Parameters' },
  request: { color: 'var(--chart-3)', label: 'Request' },
  response: { color: 'var(--chart-4)', label: 'Response' },
}

export function sectionSpec(section: RouteSection): SectionSpec {
  return SECTIONS[section]
}

export function sectionColor(section: RouteSection): string {
  return SECTIONS[section].color
}

/**
 * How strongly each layer of a section card is tinted.
 *
 * Low percentages on purpose: these sit behind monospace text at 10–11px, and
 * the pane stacks five of them. Enough to separate the blocks at a glance,
 * never enough to fight the foreground. `color-mix` against `transparent` means
 * one set of numbers works on both themes — the card beneath shows through
 * rather than each mode needing its own pair.
 */
const TINT = 7
const EDGE = 22

/**
 * Inline style for a section card: a wash, a hairline, and a heavier left edge.
 *
 * Returned as properties rather than classes because the colour is a token
 * looked up at runtime, and because setting `borderLeftColor` here removes any
 * dependence on which of two equally specific border utilities Tailwind happens
 * to emit last.
 */
export function sectionSurface(section: RouteSection): Record<string, string> {
  const { color } = SECTIONS[section]

  return {
    backgroundColor: `color-mix(in oklab, ${color} ${TINT}%, transparent)`,
    borderColor: `color-mix(in oklab, ${color} ${EDGE}%, transparent)`,
    borderLeftColor: color,
  }
}
