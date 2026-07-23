import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { Edge, Node, Styles } from '@vue-flow/core'
import type {
  ModelNodeData,
  RawColumn,
  Schema,
  SchemaColumn,
  SchemaEdge,
  SchemaNode,
} from '@/types/schema'
import { familyFor } from '@/lib/relations'
import { layoutGraph } from '@/lib/layout'
import { useLayoutStore } from '@/stores/layout'
import { useViewsStore } from '@/stores/views'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

/**
 * How often the change signal is checked. The endpoint stats the model files
 * and runs two indexed queries, so this is cheap enough to feel live without
 * being a poll loop anybody notices.
 */
const POLL_INTERVAL_MS = 3000

/**
 * Holds the model graph.
 *
 * `applySchema` is the single ingest path so the planned change-stream can push
 * a new payload through exactly the same normalisation as the initial fetch.
 * Node positions are remembered by id across applies, so a model that already
 * exists keeps its place instead of the whole graph jumping on every update.
 */
export const useSchemaStore = defineStore('schema', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  /** Server change signal this graph was built from; null when standalone. */
  const fingerprint = ref<string | null>(bootstrap().fingerprint ?? null)

  /** Timestamp of the last live update, so the header can acknowledge one. */
  const lastUpdated = ref<number | null>(null)

  // shallowRef: these arrays are replaced wholesale, never mutated in place,
  // and deep-reactivity over hundreds of nodes is wasted work.
  const nodes = shallowRef<Node<ModelNodeData>[]>([])
  const edges = shallowRef<Edge[]>([])

  // Retained so the graph can be re-placed (e.g. after clearing saved
  // positions) without refetching schema.json.
  const lastSchema = shallowRef<Schema | null>(null)

  /**
   * Models whose node is showing its full attribute list.
   *
   * Kept here rather than in the node component because Vue Flow remounts
   * nodes when the array is replaced — component-local state would collapse
   * every card on each live update.
   */
  const expanded = ref<Set<string>>(new Set())

  /**
   * Grid slot order, remembered across applies. Models that already exist keep
   * their slot and new ones are appended, so a stream update adds a model at
   * the end instead of reshuffling the whole board.
   */
  let slotOrder: string[] = []

  /** Models referenced by a relation but missing from `nodes`. */
  const externalModels = computed(() =>
    nodes.value.filter((n) => n.data?.external).map((n) => n.id),
  )

  /**
   * What the canvas actually draws: the whole graph, or just the models in the
   * active view.
   *
   * Filtering happens here rather than in `applySchema` so that positions,
   * slot order and the saved-layout pruning all keep working from the complete
   * set — a view changes what you see, never what is stored.
   */
  const visibleNodes = computed(() => {
    const members = useViewsStore().activeModels
    return members ? nodes.value.filter((n) => members.has(n.id)) : nodes.value
  })

  /** An edge needs both ends on screen, or it points into nothing. */
  const visibleEdges = computed(() => {
    const members = useViewsStore().activeModels
    return members
      ? edges.value.filter((e) => members.has(e.source) && members.has(e.target))
      : edges.value
  })

  const stats = computed(() => ({
    // Scanned models only — externals are counted separately so the two
    // numbers sum to the node count rather than overlapping.
    models: nodes.value.length - externalModels.value.length,
    relations: edges.value.length,
    external: externalModels.value.length,
    // What the active view is showing, so the header can say when the numbers
    // above are not what is on screen.
    visible: visibleNodes.value.length,
  }))

  function applySchema(schema: Schema) {
    lastSchema.value = schema

    const declared = new Map<string, SchemaNode>(schema.nodes.map((n) => [n.id, n]))

    // Relations can point at models outside the scan (vendor packages, e.g.
    // Passkey). Synthesise a placeholder so the edge stays visible and honest
    // rather than being silently dropped.
    const referenced = new Set<string>()
    for (const e of schema.edges) {
      referenced.add(e.source)
      referenced.add(e.target)
    }
    const externalIds = [...referenced].filter((id) => !declared.has(id)).sort()

    const relationCounts = new Map<string, number>()
    for (const e of schema.edges) {
      relationCounts.set(e.source, (relationCounts.get(e.source) ?? 0) + 1)
      if (e.target !== e.source) {
        relationCounts.set(e.target, (relationCounts.get(e.target) ?? 0) + 1)
      }
    }

    const toNode = (id: string, model: SchemaNode | null): Node<ModelNodeData> => ({
      id,
      type: 'model',
      // Overwritten by layoutGraph below; the grid owns position.
      position: { x: 0, y: 0 },
      data: {
        label: id,
        table: model?.table ?? '—',
        className: model?.class ?? id,
        columns: normaliseColumns(model?.columns),
        relationCount: relationCounts.get(id) ?? 0,
        external: model === null,
      },
    })

    const nextNodes = [
      ...schema.nodes.map((m) => toNode(m.id, m)),
      ...externalIds.map((id) => toNode(id, null)),
    ]

    // Surviving models keep their slot (holes compacted away), new ones queue
    // up behind them.
    const incoming = new Map(nextNodes.map((n) => [n.id, n]))
    const retained = slotOrder.filter((id) => incoming.has(id))
    const added = nextNodes.map((n) => n.id).filter((id) => !slotOrder.includes(id))
    slotOrder = [...retained, ...added]

    const ordered = slotOrder.map((id) => incoming.get(id)!)

    const nextEdges = schema.edges.map((e) => buildEdge(e))

    // The grid is the fallback; a saved position always wins, so re-exporting
    // the schema never disturbs a layout somebody arranged by hand. Models
    // added since the last save simply keep their computed grid slot.
    const saved = useLayoutStore().positions
    nodes.value = layoutGraph(ordered).map((node) =>
      saved[node.id] ? { ...node, position: { ...saved[node.id]! } } : node,
    ) as Node<ModelNodeData>[]

    edges.value = nextEdges

    // A model that disappeared must not keep an entry here, or re-adding it
    // later would bring back an expansion nobody asked for.
    for (const id of expanded.value) {
      if (!incoming.has(id)) expanded.value.delete(id)
    }
  }

  function toggleExpanded(id: string) {
    if (expanded.value.has(id)) expanded.value.delete(id)
    else expanded.value.add(id)
  }

  function collapseAll() {
    expanded.value.clear()
  }

  async function load(url = `${import.meta.env.BASE_URL}schema.json`) {
    status.value = 'loading'
    error.value = null

    // Under the Laravel package the schema is inlined into the page, so there
    // is nothing to fetch.
    const injected = bootstrap().schema
    if (injected) {
      try {
        applySchema(injected)
        status.value = 'ready'
      } catch (e) {
        error.value = e instanceof Error ? e.message : String(e)
        status.value = 'error'
      }
      return
    }

    try {
      const res = await fetch(url)
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)
      const payload = (await res.json()) as Schema
      if (!Array.isArray(payload?.nodes) || !Array.isArray(payload?.edges)) {
        throw new Error('Expected an object with "nodes" and "edges" arrays')
      }
      applySchema(payload)
      status.value = 'ready'
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      status.value = 'error'
    }
  }

  /**
   * Pulls a fresh payload and swaps it in, leaving the graph alone if it
   * cannot.
   *
   * Deliberately does not touch `status`: a failed refresh should leave the
   * working graph on screen rather than replacing it with an error card, and
   * the poller will simply try again.
   */
  async function refresh(): Promise<boolean> {
    const url = bootstrap().schemaUrl ?? `${import.meta.env.BASE_URL}schema.json`

    try {
      const res = await fetch(url, { cache: 'no-store' })
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)
      const payload = (await res.json()) as Schema
      if (!Array.isArray(payload?.nodes) || !Array.isArray(payload?.edges)) return false

      // Same ingest path as the initial load, so slot order and saved
      // positions survive: a model that already exists keeps its place and
      // only genuinely new ones are appended.
      applySchema(payload)
      lastUpdated.value = Date.now()
      status.value = 'ready'
      return true
    } catch {
      return false
    }
  }

  /**
   * Polls the server's change signal and re-exports when it moves.
   *
   * The signal covers model files *and* applied migrations, so editing a
   * relation or running `migrate` both land here — but writing a migration
   * without running it does not, since the columns it describes do not exist
   * yet. See SchemaExporter::fingerprint().
   *
   * Returns a stop function. No-op in the standalone Vite host, where nothing
   * inlines an endpoint and Vite's own watcher already reloads the page.
   */
  function watchForChanges(intervalMs = POLL_INTERVAL_MS): () => void {
    const url = bootstrap().fingerprintUrl
    if (!url) return () => {}

    let timer: ReturnType<typeof setInterval> | undefined
    let checking = false

    async function check() {
      // A slow response must not queue up behind itself.
      if (checking || document.hidden) return
      checking = true

      try {
        const res = await fetch(url!, { cache: 'no-store' })
        if (!res.ok) return
        const next = (await res.json())?.fingerprint

        if (typeof next !== 'string' || next === fingerprint.value) return

        // Only adopt the new signal once the payload behind it is actually in
        // hand — otherwise a failed fetch would look like a successful update
        // and the change would never be picked up again.
        if (await refresh()) fingerprint.value = next
      } catch {
        // Server restarting, tunnel dropped, laptop asleep. Try again later.
      } finally {
        checking = false
      }
    }

    // Editing models usually means the tab was in the background; check the
    // moment it comes back rather than up to an interval later.
    const onVisible = () => {
      if (!document.hidden) void check()
    }

    timer = setInterval(() => void check(), intervalMs)
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      clearInterval(timer)
      timer = undefined
      document.removeEventListener('visibilitychange', onVisible)
    }
  }

  /** Re-places every node from the current payload, picking up whatever saved
   *  positions exist now — used after the layout is cleared. */
  function relayout() {
    if (lastSchema.value) applySchema(lastSchema.value)
  }

  return {
    status,
    error,
    nodes,
    edges,
    visibleNodes,
    visibleEdges,
    stats,
    externalModels,
    lastUpdated,
    expanded,
    toggleExpanded,
    collapseAll,
    load,
    applySchema,
    refresh,
    watchForChanges,
    relayout,
  }
})

/**
 * Columns arrive as bare strings today and may become objects later, so both
 * are folded into one shape here rather than branching in the template.
 */
function normaliseColumns(columns: RawColumn[] | undefined): SchemaColumn[] {
  if (!Array.isArray(columns)) return []

  return columns.flatMap((col): SchemaColumn[] => {
    // Legacy payloads — and hosts running an older package than this viewer —
    // send bare column names.
    if (typeof col === 'string') {
      return col.trim() ? [{ name: col, kind: 'unknown' }] : []
    }

    if (!col || typeof col.name !== 'string') return []

    return [
      {
        name: col.name,
        type: col.type ?? undefined,
        // Falling back to the native type keeps older exports readable instead
        // of rendering a column with no type at all.
        label: col.label ?? col.type ?? undefined,
        kind: col.kind ?? 'unknown',
        nullable: col.nullable,
        unique: col.unique,
        increments: col.increments,
        fillable: col.fillable,
        hidden: col.hidden,
        cast: col.cast ?? null,
        virtual: col.virtual,
      },
    ]
  })
}

function buildEdge(e: SchemaEdge): Edge {
  const family = familyFor(e.type)
  const selfLoop = e.source === e.target
  return {
    // 12 model pairs share a source+target, so the relation name is required
    // for uniqueness — without it Vue Flow silently drops the duplicates.
    id: `${e.source}--${e.name}--${e.target}`,
    source: e.source,
    target: e.target,
    label: e.name,
    type: selfLoop ? 'smoothstep' : 'default',
    data: { relationType: e.type, family: family.id },
    style: {
      stroke: family.color,
      strokeWidth: 1.5,
      strokeDasharray: family.dashed ? '4 3' : undefined,
      // Read by the .selected glow in vue-flow-theme.css — CSS cannot recover
      // the stroke paint value, so the colour is handed over explicitly.
      '--edge-glow': family.color,
    } as Styles,
  }
}
