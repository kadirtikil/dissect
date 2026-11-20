/**
 * How a domain is drawn: its colour, and how it was found, in words.
 *
 * Colour is never the only thing saying which domain something is in. The
 * name is always written beside it, so a palette that runs out of clearly
 * separable hues past five costs scanning speed, never meaning.
 */

/**
 * The validated categorical ramp first, then evenly spread hues at the same
 * lightness and chroma for apps with more domains than it has colours.
 */
export function domainColor(name: string, all: readonly string[]): string {
  const index = all.indexOf(name)

  if (index === -1) return 'var(--muted-foreground)'
  if (index < 5) return `var(--chart-${index + 1})`

  // The golden angle keeps neighbours in the list far apart on the wheel.
  return `oklch(0.6 0.15 ${Math.round((index * 137.5) % 360)})`
}

/**
 * The facet key for a row with no domain.
 *
 * An empty string, because no domain can be called that: domains.json and the
 * attribute both refuse a blank name.
 */
export const UNASSIGNED = ''

/** A row's `context` as a facet key. */
export function contextKey(context: string | null | undefined): string {
  return context ?? UNASSIGNED
}

/** Empty means every domain; otherwise a row matches if it is in one of them. */
export function matchesContexts(context: string | null | undefined, selected: readonly string[]) {
  return selected.length === 0 || selected.includes(contextKey(context))
}

/**
 * The order colours are handed out in: the domains the page was given, then
 * any others seen since, sorted.
 *
 * Every page builds its palette from the same list first, so Billing is the
 * same colour on the graph, the route list and the domain list, whichever of
 * them happens to have rows in it.
 */
export function domainPalette(known: readonly string[], seen: Iterable<string>): string[] {
  const extra = [...new Set(seen)].filter((name) => name !== UNASSIGNED && !known.includes(name))

  return [...known, ...extra.sort((a, b) => a.localeCompare(b))]
}

const SOURCES: Record<string, string> = {
  config: 'domains.json',
  attribute: '#[Domain] attribute',
  modules: 'Modules/ folder',
  'app-modules': 'app-modules/ folder',
  'domain-folder': 'Domain/ folder',
  hexagonal: 'hexagonal layers',
  'layer-folders': 'layer sub-folders',
  packages: 'packages/ folder',
}

export function sourceLabel(source: string): string {
  return SOURCES[source] ?? source
}

/** The namespace before the class name, or an empty string. */
export function namespaceOf(name: string): string {
  const cut = name.lastIndexOf('\\')
  return cut === -1 ? '' : name.slice(0, cut)
}
