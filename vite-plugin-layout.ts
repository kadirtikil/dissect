import { writeFile, rename } from 'node:fs/promises'
import path from 'node:path'
import type { Plugin } from 'vite'

export const LAYOUT_ENDPOINT = '/__layout'
export const LAYOUT_FILE = 'public/layout.json'

interface LayoutPayload {
  version: number
  positions: Record<string, { x: number; y: number }>
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
  return {
    name: 'dissect:layout-persistence',
    apply: 'serve',

    /**
     * Vite full-reloads the page whenever anything under public/ changes. Since
     * saving a drag writes public/layout.json, every node move reloaded the app
     * — losing selection, viewport and any in-flight interaction.
     *
     * The file is excluded from the watcher so saving is silent. schema.json is
     * deliberately still watched: a re-export *should* refresh the graph.
     */
    config() {
      return {
        server: {
          watch: {
            ignored: [`**/${LAYOUT_FILE}`, `**/${LAYOUT_FILE}.tmp`],
          },
        },
      }
    },

    configureServer(server) {
      const target = path.resolve(server.config.root, LAYOUT_FILE)

      // Belt and braces: `server.watch.ignored` is merged with Vite's own
      // defaults, so drop the path from the live watcher too rather than
      // relying on that merge behaviour.
      server.watcher.unwatch(target)
      server.watcher.unwatch(`${target}.tmp`)

      server.middlewares.use(LAYOUT_ENDPOINT, async (req, res) => {
        if (req.method !== 'POST') {
          res.statusCode = 405
          return res.end('Only POST is supported')
        }

        try {
          const raw = await readBody(req)
          const payload = sanitise(JSON.parse(raw))

          // Write-then-rename: a crash mid-write leaves the previous layout
          // intact rather than a truncated file the app cannot parse.
          const tmp = `${target}.tmp`
          await writeFile(tmp, JSON.stringify(payload, null, 2) + '\n', 'utf8')
          await rename(tmp, target)

          res.setHeader('content-type', 'application/json')
          res.end(JSON.stringify({ ok: true, saved: Object.keys(payload.positions).length }))
        } catch (error) {
          server.config.logger.error(
            `[layout] failed to save: ${error instanceof Error ? error.message : String(error)}`,
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
function sanitise(input: unknown): LayoutPayload {
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
