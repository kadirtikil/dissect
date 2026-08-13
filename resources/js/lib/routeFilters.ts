import type { ApiRoute } from '@/types/routes'

/**
 * Narrowing the endpoint list.
 *
 * Every route the router knows about is exported — nothing is hidden on the PHP
 * side — so this is where a real application's several hundred become the
 * handful somebody is looking at. Kept pure and separate from the store so it
 * can be tested without mounting anything.
 */

export interface RouteFilterState {
  search: string
  /** Empty means every verb; otherwise a route matches if it answers to any. */
  methods: string[]
  /** Empty means every group (app / vendor / framework). */
  groups: string[]
  /** Node ids. Null means no model filter at all — not "an empty set of models". */
  models: string[] | null
}

export const EMPTY_FILTERS: RouteFilterState = {
  search: '',
  methods: [],
  groups: [],
  models: null,
}

export function matchesSearch(route: ApiRoute, search: string): boolean {
  const term = search.trim().toLowerCase()
  if (!term) return true

  // A bare verb is what somebody types when they mean the verb, so it matches
  // the method as well as the text — "post" finding every POST is more useful
  // than it finding every URI containing the word.
  if (route.methods.some((m) => m.toLowerCase() === term)) return true

  return (
    route.uri.toLowerCase().includes(term) ||
    (route.name?.toLowerCase().includes(term) ?? false) ||
    route.action.label.toLowerCase().includes(term) ||
    (route.action.class?.toLowerCase().includes(term) ?? false)
  )
}

export function matchesMethods(route: ApiRoute, methods: string[]): boolean {
  return methods.length === 0 || route.methods.some((m) => methods.includes(m))
}

export function matchesGroups(route: ApiRoute, groups: string[]): boolean {
  return groups.length === 0 || groups.includes(route.group)
}

/** A route matches a model filter when it touches at least one of the models. */
export function matchesModels(route: ApiRoute, models: string[] | null): boolean {
  if (models === null) return true
  return route.models.some((m) => models.includes(m))
}

export function matchesRoute(route: ApiRoute, state: RouteFilterState): boolean {
  return (
    matchesSearch(route, state.search) &&
    matchesMethods(route, state.methods) &&
    matchesGroups(route, state.groups) &&
    matchesModels(route, state.models)
  )
}

export function filterRoutes(routes: ApiRoute[], state: RouteFilterState): ApiRoute[] {
  return routes.filter((route) => matchesRoute(route, state))
}

/**
 * Counts beside each facet option.
 *
 * A facet's own selection is deliberately excluded from its counts: with GET
 * selected, the POST chip should still say how many POSTs there are, or it
 * reads as "there are none" and the filter becomes a dead end.
 */
export function facetCounts(
  routes: ApiRoute[],
  state: RouteFilterState,
): { methods: Record<string, number>; groups: Record<string, number> } {
  const methods: Record<string, number> = {}
  const groups: Record<string, number> = {}

  for (const route of routes) {
    const base =
      matchesSearch(route, state.search) && matchesModels(route, state.models)

    if (base && matchesGroups(route, state.groups)) {
      for (const method of route.methods) {
        methods[method] = (methods[method] ?? 0) + 1
      }
    }

    if (base && matchesMethods(route, state.methods)) {
      groups[route.group] = (groups[route.group] ?? 0) + 1
    }
  }

  return { methods, groups }
}

export interface RouteGrouping {
  /** Stable key for the collapse state and the v-for. */
  key: string
  label: string
  routes: ApiRoute[]
}

/**
 * Buckets the list by the class behind it.
 *
 * A controller is the unit people think in — "the invoice endpoints" — and it
 * is also what keeps a list of four hundred routes navigable without a tree
 * widget. Closures have no class, so they bucket by the file they live in.
 */
export function groupRoutes(routes: ApiRoute[]): RouteGrouping[] {
  const buckets = new Map<string, RouteGrouping>()

  for (const route of routes) {
    const key = bucketFor(route)
    const bucket = buckets.get(key)

    if (bucket) bucket.routes.push(route)
    else buckets.set(key, { key, label: key, routes: [route] })
  }

  return [...buckets.values()].sort((a, b) => a.label.localeCompare(b.label))
}

function bucketFor(route: ApiRoute): string {
  if (route.action.class) {
    const parts = route.action.class.split('\\')
    return parts[parts.length - 1] || route.action.class
  }

  // `api.php:22` → `api.php`, so every closure in one file lands together.
  const [file] = route.action.label.split(':')
  return file || 'Closures'
}
