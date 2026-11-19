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
