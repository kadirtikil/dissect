import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import { bootstrap } from '@/lib/bootstrap'
import { persist, revisionOf, type Revision } from '@/lib/persist'

export interface NodePosition {
  x: number
  y: number
}

/** Matches the payload the dev-server plugin writes to public/layout.json. */
export interface LayoutFile {
  version: number
  positions: Record<string, NodePosition>
  /** Only from the package; see lib/persist. */
  revision?: string | null
}

/** Standalone dev-server fallback; the package injects its own route. */
const DEV_ENDPOINT = '/__layout'
const SAVE_DEBOUNCE_MS = 400

/**
 * Human-authored node positions, kept deliberately separate from the schema
 * store: schema.json is regenerated from the Laravel app and would clobber any
 * layout merged into it, whereas layout.json is edited only by dragging.
 *
 * Saving goes through the dev-server middleware (see vite-plugin-layout.ts).
 * In a production build that endpoint does not exist, so drags stay in memory
 * for the session and `saveError` explains why nothing was written.
 */
export const useLayoutStore = defineStore('layout', () => {
  // shallowRef: replaced wholesale on save, never deep-mutated.
  const positions = shallowRef<Record<string, NodePosition>>({})
  const loaded = ref(false)
  const saving = ref(false)
  const saveError = ref<string | null>(null)

  let timer: ReturnType<typeof setTimeout> | undefined
  let pendingIds: string[] | null = null

  /** The file this page last read or wrote; sent back with every save. */
  let revision: Revision

  async function load(url = `${import.meta.env.BASE_URL}layout.json`) {
    // Inlined by the package alongside the schema — no round trip needed.
    const injected = bootstrap().layout
    if (injected) {
      positions.value = sanitise(injected.positions)
      revision = revisionOf(injected)
      loaded.value = true
      return
    }

    try {
      const res = await fetch(url)
      // A missing layout.json is the normal first-run state, not an error.
      if (res.ok) {
        const payload = (await res.json()) as Partial<LayoutFile>
        positions.value = sanitise(payload?.positions)
        revision = revisionOf(payload)
      }
    } catch {
      // Malformed layout must never stop the graph rendering — fall back to
      // the computed grid.
      positions.value = {}
    } finally {
      loaded.value = true
    }
  }

  /** Records a drag. `knownIds` prunes models that no longer exist. */
  function setPosition(id: string, position: NodePosition, knownIds?: string[]) {
    positions.value = { ...positions.value, [id]: position }
    if (knownIds) pendingIds = knownIds
    scheduleSave()
  }

  function scheduleSave() {
    // Dragging fires continuously; only the settled position is worth writing.
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => void save(), SAVE_DEBOUNCE_MS)
  }

  /**
   * Writes the layout, one request at a time.
   *
   * Queued like the views store's writes, and for a sharper reason: the
   * debounce only spaces saves out, it does not stop a slow one overlapping
   * the next. Two POSTs in flight can land out of order, leaving the file
   * holding the earlier of the two layouts.
   */
  let queue: Promise<void> = Promise.resolve()

  function save(): Promise<void> {
    queue = queue.then(write)
    return queue
  }

  async function write() {
    saving.value = true
    saveError.value = null

    // Read at send time, not when the save was scheduled: a queued write
    // must carry every drag made while it waited.
    const sent = positions.value
    const keep = pendingIds
    const body = keep
      ? Object.fromEntries(Object.entries(sent).filter(([id]) => keep.includes(id)))
      : sent

    try {
      revision = await persist(
        bootstrap().saveUrl ?? DEV_ENDPOINT,
        { version: 1, positions: body },
        revision,
      )

      // Forget the models that were pruned from the file — and only those.
      // Assigning what was sent back to `positions` would also erase every
      // drag made while the request was in flight. A position replaced since
      // (a model that came back and was dragged again) is a new object, so
      // the identity check leaves it alone.
      if (keep) {
        const pruned = Object.keys(sent).filter(
          (id) => !(id in body) && positions.value[id] === sent[id],
        )
        if (pruned.length > 0) {
          positions.value = Object.fromEntries(
            Object.entries(positions.value).filter(([id]) => !pruned.includes(id)),
          )
        }
      }
    } catch (e) {
      saveError.value = e instanceof Error ? e.message : String(e)
    } finally {
      saving.value = false
    }
  }

  /**
   * Clears every saved position and writes the empty file immediately.
   *
   * Awaited rather than debounced: the caller re-places the graph straight
   * afterwards, and a pending timer would race that by re-saving stale state.
   */
  async function reset() {
    if (timer) clearTimeout(timer)
    positions.value = {}
    pendingIds = []
    await save()
  }

  return { positions, loaded, saving, saveError, load, setPosition, save, reset }
})

function sanitise(input: unknown): Record<string, NodePosition> {
  if (typeof input !== 'object' || input === null) return {}

  const out: Record<string, NodePosition> = {}
  for (const [id, value] of Object.entries(input as Record<string, unknown>)) {
    const { x, y } = (value ?? {}) as { x?: unknown; y?: unknown }
    if (
      typeof x === 'number' &&
      typeof y === 'number' &&
      Number.isFinite(x) &&
      Number.isFinite(y)
    ) {
      out[id] = { x, y }
    }
  }
  return out
}
