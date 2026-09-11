/**
 * How a payload shape was arrived at, said in words.
 *
 * The exporter's `source` is a wire value — `resource-collection`,
 * `json-api-identifier` — chosen to be unambiguous rather than readable. This
 * is where it becomes something to put on screen.
 *
 * The distinction that matters most here is between a shape with no fields
 * because there is nothing to send, and one with no fields because nothing
 * could be read. A `204` and an unreadable `toArray()` both arrive as an empty
 * list, and rendering them the same way would turn an answer into a failure.
 */

const LABELS: Record<string, string> = {
  'form-request': 'form request',
  'inline-validate': 'inline validation',
  none: 'no validation',
  resource: 'resource',
  'resource-collection': 'resource collection',
  json: 'json',
  view: 'view',
  redirect: 'redirect',
  unknown: 'unknown',
  'json-api': 'JSON:API document',
  'json-api-identifier': 'JSON:API identifiers',
  'json-api-none': 'no content',
}

export function sourceLabel(source: string): string {
  return LABELS[source] ?? source
}

/**
 * Sources that describe an endpoint with no JSON body at all.
 *
 * Each is a finding rather than a gap: a view renders HTML, a redirect sends a
 * Location, and a JSON:API delete answers 204 — where a body would be a
 * protocol violation rather than an omission.
 */
const BODYLESS: Record<string, string> = {
  view: 'Renders a view',
  redirect: 'Redirects',
  'json-api-none': 'Answers 204 No Content',
}

export function bodylessNote(source: string | undefined): string | null {
  return source === undefined ? null : (BODYLESS[source] ?? null)
}

/** Whether a shape is one of the JSON:API ones, which are read from a schema. */
export function isJsonApi(source: string | undefined): boolean {
  return source?.startsWith('json-api') ?? false
}
