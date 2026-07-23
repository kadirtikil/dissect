import { writeFile, rename } from 'node:fs/promises'
import path from 'node:path'
import type { Plugin } from 'vite'

export const LAYOUT_ENDPOINT = '/__layout'
export const LAYOUT_FILE = 'public/layout.json'

export const VIEWS_ENDPOINT = '/__views'
export const VIEWS_FILE = 'public/views.json'

interface LayoutPayload {
  version: number
  positions: Record<string, { x: number; y: number }>
}

interface ViewsPayload {
  version: number
  views: { id: string; name: string; models: string[] }[]
}

/**
 * Dev-only endpoint that persists node positions to public/layout.json.
 *
 * The viewer is a static app, so there is no backend to POST to; this keeps
 * the layout in a real, committable file instead of hiding it in localStorage.
 * `apply: 'serve'` means it never ships in a production build — see the note in
 * the layout store about what happens there.
 */
export function layoutPersistence(): Plugin {
  return jsonPersistence('dissect:layout-persistence', LAYOUT_ENDPOINT, LAYOUT_FILE, sanitiseLayout)
}

/**
 * The same arrangement for saved views. Separate file from the layout because
 * they answer different questions — where a model sits, versus which models you
 * are looking at — and are edited at completely different rates.
 */
export function viewsPersistence(): Plugin {
  return jsonPersistence('dissect:views-persistence', VIEWS_ENDPOINT, VIEWS_FILE, sanitiseViews)
}

/**
 * Both stores want the same thing: POST a small JSON document, validate it,
 * write it atomically, and keep the write from tripping Vite's watcher.
 */
function jsonPersistence<T>(
  name: string,
  endpoint: string,
  file: string,
  sanitise: (input: unknown) => T,
): Plugin {
  return {
    name,
    apply: 'serve',

    /**
     * Vite full-reloads the page whenever anything under public/ changes. Since
     * saving writes into public/, every drag or saved view reloaded the app —
     * losing selection, viewport and any in-flight interaction.
     *
     * The file is excluded from the watcher so saving is silent. schema.json is
     * deliberately still watched: a re-export *should* refresh the graph.
     */
    config() {
      return {
        server: {
          watch: {
            ignored: [`**/${file}`, `**/${file}.tmp`],
          },
        },
      }
    },

    configureServer(server) {
      const target = path.resolve(server.config.root, file)

      // Belt and braces: `server.watch.ignored` is merged with Vite's own
      // defaults, so drop the path from the live watcher too rather than
      // relying on that merge behaviour.
      server.watcher.unwatch(target)
      server.watcher.unwatch(`${target}.tmp`)

      server.middlewares.use(endpoint, async (req, res) => {
        if (req.method !== 'POST') {
          res.statusCode = 405
          return res.end('Only POST is supported')
        }

        try {
          const raw = await readBody(req)
          const payload = sanitise(JSON.parse(raw))

          // Write-then-rename: a crash mid-write leaves the previous file
          // intact rather than a truncated one the app cannot parse.
          const tmp = `${target}.tmp`
          await writeFile(tmp, JSON.stringify(payload, null, 2) + '\n', 'utf8')
          await rename(tmp, target)

          res.setHeader('content-type', 'application/json')
          res.end(JSON.stringify({ ok: true }))
        } catch (error) {
          server.config.logger.error(
            `[${name}] failed to save: ${error instanceof Error ? error.message : String(error)}`,
          )
          res.statusCode = 400
          res.end(JSON.stringify({ ok: false }))
        }
      })
    },
  }
}

function readBody(req: NodeJS.ReadableStream): Promise<string> {
  return new Promise((resolve, reject) => {
    let body = ''
    req.on('data', (chunk) => {
      body += chunk
      // The payload is one small object per model; anything larger is a bug
      // or an attempt to fill the disk.
      if (body.length > 1_000_000) reject(new Error('payload too large'))
    })
    req.on('end', () => resolve(body))
    req.on('error', reject)
  })
}

/**
 * Only finite numeric coordinates keyed by model name survive. Without this the
 * endpoint would happily write NaN or arbitrary nested junk into the file that
 * the app then has to defend against on every load.
 */
function sanitiseLayout(input: unknown): LayoutPayload {
  if (typeof input !== 'object' || input === null) throw new Error('expected an object')

  const source = (input as { positions?: unknown }).positions
  if (typeof source !== 'object' || source === null) throw new Error('missing positions')

  const positions: LayoutPayload['positions'] = {}

  for (const [id, value] of Object.entries(source as Record<string, unknown>)) {
    if (typeof value !== 'object' || value === null) continue
    const { x, y } = value as { x?: unknown; y?: unknown }
    if (typeof x !== 'number' || typeof y !== 'number') continue
    if (!Number.isFinite(x) || !Number.isFinite(y)) continue
    positions[id] = { x: Math.round(x), y: Math.round(y) }
  }

  return { version: 1, positions }
}

/**
 * Mirrors ViewRepository::sanitise() on the PHP side — the two hosts write the
 * same file format, so a views.json produced by the dev server has to be one
 * the package would also accept.
 */
function sanitiseViews(input: unknown): ViewsPayload {
  if (typeof input !== 'object' || input === null) throw new Error('expected an object')

  const source = (input as { views?: unknown }).views
  if (!Array.isArray(source)) throw new Error('missing views')

  const views: ViewsPayload['views'] = []
  const seen = new Set<string>()

  for (const value of source) {
    if (typeof value !== 'object' || value === null) continue
    const { id, name, models } = value as { id?: unknown; name?: unknown; models?: unknown }

    if (typeof id !== 'string' || typeof name !== 'string' || !Array.isArray(models)) continue

    const slug = id
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
      .slice(0, 64)
    // eslint-disable-next-line no-control-regex
    const label = name.replace(/[\x00-\x1f\x7f]/g, '').trim().slice(0, 64)
    const members = [
      ...new Set(models.filter((m): m is string => typeof m === 'string' && /^\w+$/.test(m))),
    ].slice(0, 500)

    // A view with no name, no id or nothing in it cannot be selected or
    // displayed, so it is not worth persisting.
    if (!slug || !label || members.length === 0 || seen.has(slug)) continue

    seen.add(slug)
    views.push({ id: slug, name: label, models: members })

    if (views.length >= 100) break
  }

  return { version: 1, views }
}
