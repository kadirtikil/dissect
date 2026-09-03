import { describe, expect, it } from 'vitest'
import type { ApiRoute } from '@/types/routes'
import {
  EMPTY_FILTERS,
  facetCounts,
  filterRoutes,
  groupRoutes,
  matchesSearch,
} from '@/lib/routeFilters'

function route(overrides: Partial<ApiRoute> & Pick<ApiRoute, 'id'>): ApiRoute {
  return {
    methods: ['GET'],
    uri: 'api/things',
    name: null,
    domain: null,
    middleware: [],
    stack: 'web',
    group: 'app',
    action: { type: 'controller', class: 'App\\Http\\Controllers\\ThingController', method: 'index', label: 'ThingController@index' },
    parameters: [],
    ...overrides,
  }
}

const ROUTES: ApiRoute[] = [
  route({ id: 'GET:api/posts', uri: 'api/posts', name: 'posts.index', stack: 'api' }),
  route({
    id: 'POST:api/posts',
    uri: 'api/posts',
    methods: ['POST'],
    name: 'posts.store',
    stack: 'api',
  }),
  route({
    id: 'GET:api/authors',
    uri: 'api/authors',
    name: 'authors.index',
    action: { type: 'controller', class: 'App\\Http\\Controllers\\AuthorController', method: 'index', label: 'AuthorController@index' },
  }),
  route({
    id: 'GET:_debug',
    uri: '_debug',
    group: 'vendor',
    action: { type: 'closure', class: null, method: null, label: 'debug.php:8' },
  }),
]

describe('matchesSearch', () => {
  it('matches the path, the route name and the controller', () => {
    expect(matchesSearch(ROUTES[0]!, 'posts')).toBe(true)
    expect(matchesSearch(ROUTES[0]!, 'posts.index')).toBe(true)
    expect(matchesSearch(ROUTES[0]!, 'ThingController')).toBe(true)
    expect(matchesSearch(ROUTES[0]!, 'authors')).toBe(false)
  })

  it('treats a bare verb as the verb', () => {
    // Typing "post" almost always means the method, not the word — finding
    // every POST is more useful than finding every URI containing it.
    expect(matchesSearch(ROUTES[1]!, 'post')).toBe(true)
    expect(matchesSearch(ROUTES[0]!, 'post')).toBe(true) // its URI says posts
    expect(matchesSearch(ROUTES[3]!, 'post')).toBe(false)
  })

  it('ignores case and surrounding space, and an empty term matches all', () => {
    expect(matchesSearch(ROUTES[0]!, '  POSTS  ')).toBe(true)
    expect(matchesSearch(ROUTES[3]!, '')).toBe(true)
  })
})

describe('filterRoutes', () => {
  it('returns everything when nothing is selected', () => {
    expect(filterRoutes(ROUTES, EMPTY_FILTERS)).toHaveLength(4)
  })

  it('narrows by verb and group', () => {
    expect(filterRoutes(ROUTES, { ...EMPTY_FILTERS, methods: ['POST'] })).toHaveLength(1)
    expect(filterRoutes(ROUTES, { ...EMPTY_FILTERS, groups: ['vendor'] })).toHaveLength(1)
  })

  it('narrows only by what this surface asks for', () => {
    // The endpoint list is not scoped by anything chosen elsewhere. Every
    // filter it has is on screen, so a route can never go missing because of a
    // selection made on the graph.
    expect(Object.keys(EMPTY_FILTERS).sort()).toEqual(['groups', 'methods', 'search', 'stacks'])
    expect(filterRoutes(ROUTES, EMPTY_FILTERS)).toHaveLength(ROUTES.length)
  })

  it('splits the table into the frontend half and the external one', () => {
    // The two halves are disjoint and together they are everything, which is
    // what makes the switcher a split rather than one more filter.
    const web = filterRoutes(ROUTES, { ...EMPTY_FILTERS, stacks: ['web'] })
    const api = filterRoutes(ROUTES, { ...EMPTY_FILTERS, stacks: ['api'] })

    expect(api.map((r) => r.id)).toEqual(['GET:api/posts', 'POST:api/posts'])
    expect(web).toHaveLength(2)
    expect(web.length + api.length).toBe(ROUTES.length)
  })

  it('applies every active filter together', () => {
    const filtered = filterRoutes(ROUTES, {
      ...EMPTY_FILTERS,
      search: 'posts',
      methods: ['POST'],
    })

    expect(filtered.map((r) => r.id)).toEqual(['POST:api/posts'])
  })
})

describe('facetCounts', () => {
  it('counts a facet as if its own selection were not applied', () => {
    // With GET selected, the POST chip must still say how many POSTs there
    // are — otherwise it reads as "none" and the filter is a dead end.
    const counts = facetCounts(ROUTES, { ...EMPTY_FILTERS, methods: ['GET'] })

    expect(counts.methods.POST).toBe(1)
    expect(counts.methods.GET).toBe(3)
  })

  it('does narrow a facet by the other filters', () => {
    const counts = facetCounts(ROUTES, { ...EMPTY_FILTERS, methods: ['POST'] })

    expect(counts.groups.app).toBe(1)
    expect(counts.groups.vendor).toBeUndefined()
  })

  it('respects the search term in both facets', () => {
    const counts = facetCounts(ROUTES, { ...EMPTY_FILTERS, search: 'authors' })

    expect(counts.groups).toEqual({ app: 1 })
    expect(counts.methods).toEqual({ GET: 1 })
  })

  it('counts the other half as if the switcher were not set', () => {
    // With Web showing, the API tab must still say how many endpoints are over
    // there — a count of zero would read as "nothing to switch to".
    const counts = facetCounts(ROUTES, { ...EMPTY_FILTERS, stacks: ['web'] })

    expect(counts.stacks.api).toBe(2)
    expect(counts.stacks.web).toBe(2)
  })

  it('still narrows the stack counts by the other filters', () => {
    const counts = facetCounts(ROUTES, { ...EMPTY_FILTERS, methods: ['POST'] })

    expect(counts.stacks.api).toBe(1)
    expect(counts.stacks.web).toBeUndefined()
  })
})

describe('groupRoutes', () => {
  it('buckets by controller, alphabetically', () => {
    // localeCompare, so a lowercase file name sorts among the class names
    // rather than after all of them — the same collation the model list uses.
    const groups = groupRoutes(ROUTES)

    expect(groups.map((g) => g.label)).toEqual(['AuthorController', 'debug.php', 'ThingController'])
  })

  it('groups closures by the file they live in', () => {
    // A closure has no class, and the file is the only thing several of them
    // have in common.
    const groups = groupRoutes(ROUTES)

    expect(groups.find((g) => g.label === 'debug.php')?.routes).toHaveLength(1)
  })

  it('keeps every route in exactly one bucket', () => {
    const total = groupRoutes(ROUTES).reduce((sum, g) => sum + g.routes.length, 0)

    expect(total).toBe(ROUTES.length)
  })
})
