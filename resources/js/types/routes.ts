/**
 * Shape of routes.json, as exported from the Laravel side.
 *
 * Endpoints carry no model ids. The graph is a different context, and an
 * endpoint is described by its own contract — how it is addressed, what goes
 * in, what comes back. A rule that names a table travels verbatim.
 *
 * Kept deliberately close to the wire format, like types/schema.ts.
 */

/** Whose code the endpoint runs. Every route is exported; this is the facet. */
export type RouteGroup = 'app' | 'vendor' | 'framework'

export type ActionType = 'controller' | 'closure' | 'view' | 'redirect'

/**
 * How sure the exporter is about a shape it inferred.
 *
 * Rendered rather than hidden: an API description that is confidently wrong is
 * worse than one that admits what it could not work out.
 */
export type Confidence = 'certain' | 'inferred' | 'unknown'

export interface RouteAction {
  type: ActionType | string
  class: string | null
  /** `__invoke` for an invokable controller; null for a closure. */
  method: string | null
  /** What the list shows: `PostController@store`, or `api.php:22` for a closure. */
  label: string
}

export interface RouteParameter {
  name: string
  optional: boolean
  /** Custom binding column — the `slug` in `{post:slug}`. */
  field: string | null
  /** The `where()` constraint, if one was declared. */
  pattern: string | null
}

export interface RequestField {
  /** Dotted path with `[]` for repeats, e.g. `tags[].name`. */
  path: string
  /** The rule that carries a type — `integer`, `string`, `date`. */
  type?: string | null
  required?: boolean
  /** Verbatim, `exists:authors,id` included — nothing is resolved. */
  rules: string[]
}

export interface RequestShape {
  source: 'form-request' | 'inline-validate' | 'none' | string
  class: string | null
  confidence: Confidence
  fields: RequestField[]
}

export type FieldKind = 'scalar' | 'object' | 'array'

export interface ResponseField {
  path: string
  kind: FieldKind | string
  /** Wrapped in `when()` / `whenLoaded()` — not always present in the payload. */
  conditional?: boolean
}

export interface ResponseShape {
  source: 'resource' | 'resource-collection' | 'json' | 'view' | 'redirect' | 'unknown' | string
  class: string | null
  status?: number | null
  confidence: Confidence
  fields: ResponseField[]
}

export interface ApiRoute {
  /** `POST:api/posts` — verbs and path, since plenty of routes have no name. */
  id: string
  methods: string[]
  uri: string
  name: string | null
  domain: string | null
  middleware: string[]
  group: RouteGroup | string
  action: RouteAction
  parameters: RouteParameter[]
  /**
   * Absent until the exporter analyses bodies, and absent for routes where
   * there is nothing to analyse. Optional rather than empty so "not looked at"
   * stays distinguishable from "looked at, found nothing".
   */
  request?: RequestShape
  response?: ResponseShape
}

export interface RoutesFile {
  routes: ApiRoute[]
  generated_at?: string
}
