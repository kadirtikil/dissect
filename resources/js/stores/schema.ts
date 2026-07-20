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
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

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

  // shallowRef: these arrays are replaced wholesale, never mutated in place,
  // and deep-reactivity over hundreds of nodes is wasted work.
  const nodes = shallowRef<Node<ModelNodeData>[]>([])
  const edges = shallowRef<Edge[]>([])

  // Retained so the graph can be re-placed (e.g. after clearing saved
  // positions) without refetching schema.json.
  const lastSchema = shallowRef<Schema | null>(null)

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

  const stats = computed(() => ({
    // Scanned models only — externals are counted separately so the two
    // numbers sum to the node count rather than overlapping.
    models: nodes.value.length - externalModels.value.length,
    relations: edges.value.length,
    external: externalModels.value.length,
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

  /** Re-places every node from the current payload, picking up whatever saved
   *  positions exist now — used after the layout is cleared. */
  function relayout() {
    if (lastSchema.value) applySchema(lastSchema.value)
  }

  return { status, error, nodes, edges, stats, externalModels, load, applySchema, relayout }
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
