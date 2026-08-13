/**
 * Colour per HTTP verb, drawn from the same categorical ramp the relation
 * families use so the two surfaces read as one product.
 *
 * Colour is redundant here by construction: the verb is written out next to it,
 * so the palette speeds up scanning a long list without ever being the only
 * thing carrying the meaning.
 */

interface MethodSpec {
  /** Design token, resolved against the active theme at render time. */
  color: string
  /** True for anything that changes state — the list dims safe verbs slightly. */
  writes: boolean
}

const METHODS: Record<string, MethodSpec> = {
  GET: { color: 'var(--chart-4)', writes: false },
  HEAD: { color: 'var(--chart-4)', writes: false },
  OPTIONS: { color: 'var(--muted-foreground)', writes: false },
  POST: { color: 'var(--chart-3)', writes: true },
  PUT: { color: 'var(--chart-1)', writes: true },
  PATCH: { color: 'var(--chart-1)', writes: true },
  DELETE: { color: 'var(--destructive)', writes: true },
}

const FALLBACK: MethodSpec = { color: 'var(--muted-foreground)', writes: false }

export function methodSpec(method: string): MethodSpec {
  return METHODS[method.toUpperCase()] ?? FALLBACK
}

/**
 * A route can answer to several verbs (`PUT|PATCH`), and the badge shows all of
 * them — so the colour comes from the first, which is the one Laravel lists
 * first and the one anybody would name the endpoint by.
 */
export function methodColor(methods: string[]): string {
  return methodSpec(methods[0] ?? '').color
}

/** Sort key, so the list orders verbs by lifecycle rather than alphabetically. */
const ORDER = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']

export function methodRank(method: string): number {
  const index = ORDER.indexOf(method.toUpperCase())
  return index === -1 ? ORDER.length : index
}
