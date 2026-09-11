/**
 * The two halves of an application's HTTP surface, and how to say them.
 *
 * `web` and `api` are the middleware groups Laravel applies per route file, and
 * they are the closest thing the router keeps to which file a route was written
 * in — it loads `web.php` and `api.php` into one flat table and retains nothing
 * else about where an entry came from.
 *
 * The distinction is worth a switcher of its own rather than another chip
 * because it is the first question somebody has about an endpoint list: is this
 * something my own frontend calls, or something I have promised to the outside
 * world. The answer changes what breaking it costs.
 *
 * Colours come from the same categorical ramp the verbs, job kinds and detail
 * sections use. As everywhere else they are redundant — the label is written
 * next to them — so they speed up the switch without carrying it.
 */

export type RouteStack = 'web' | 'api' | 'other'

interface StackSpec {
  label: string
  /** Design token, resolved against the active theme at render time. */
  color: string
  /** Said in full on hover, since two words on a chip cannot carry it. */
  hint: string
}

const STACKS: Record<RouteStack, StackSpec> = {
  web: {
    label: 'Web',
    color: 'var(--chart-5)',
    hint: 'Session-backed — the routes your own frontend calls',
  },
  api: {
    label: 'API',
    color: 'var(--chart-4)',
    hint: 'Stateless — the surface external services call',
  },
  other: {
    label: 'Other',
    color: 'var(--muted-foreground)',
    hint: 'Registered outside both middleware groups',
  },
}

const FALLBACK: StackSpec = {
  label: 'Other',
  color: 'var(--muted-foreground)',
  hint: 'Registered outside both middleware groups',
}

export function stackSpec(stack: string): StackSpec {
  return STACKS[stack as RouteStack] ?? FALLBACK
}

export function stackColor(stack: string): string {
  return stackSpec(stack).color
}

export function stackLabel(stack: string): string {
  return stackSpec(stack).label
}

/**
 * The order the switcher offers them in.
 *
 * `other` is last and, unlike the two real halves, is only shown when the
 * application actually has such a route — an option that can only ever return
 * nothing is a dead end, and most applications have none.
 */
export const STACK_ORDER: RouteStack[] = ['web', 'api', 'other']
