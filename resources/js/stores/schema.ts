import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { Edge, Node, Styles } from '@vue-flow/core'
import type {
  ModelNodeData,
  RawColumn,
  Schema,
  SchemaColumn,
  RelationKeys,
  SchemaEdge,
  SchemaNode,
} from '@/types/schema'
import { familyFor } from '@/lib/relations'
import { NODE_HANDLE, handleFor, keyColumns } from '@/lib/relationHandles'
import { layoutGraph } from '@/lib/layout'
import { useLayoutStore } from '@/stores/layout'
import { DOMAIN_VIEW_PREFIX, useViewsStore } from '@/stores/views'
import { useRoutesStore } from '@/stores/routes'
import { useJobsStore } from '@/stores/jobs'
import { useProviderStore } from '@/stores/provider'
import { useDomainsStore } from '@/stores/domains'
import { useClassesStore } from '@/stores/classes'
import { useBoundariesStore } from '@/stores/boundaries'
import { bootstrap } from '@/lib/bootstrap'
import { baseName, shortNames } from '@/lib/classNames'
import { crossesInto, domainColor, domainPalette } from '@/lib/domains'

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

  /**
   * Models the routes surface is pointing at — ringed on the canvas.
   *
   * Kept apart from Vue Flow's own selection: selection is a gesture somebody
   * made on the canvas and feeds view membership, whereas this is an answer to
   * "which models does that endpoint touch". Conflating them would let opening
   * an endpoint quietly rewrite what a new view would contain.
   */
  const highlighted = ref<Set<string>>(new Set())

  /**
   * A request to bring one model into view. A counter rather than a plain id,
   * so asking for the same model twice still moves the viewport — the second
   * click means the same thing as the first.
   */
  const focusRequest = ref<{ id: string; seq: number } | null>(null)
  let focusSeq = 0

  function highlight(ids: string[]) {
    highlighted.value = new Set(ids)
  }

  /** Ring these models and centre the first of them. */
  function focusModels(ids: string[]) {
    highlight(ids)
    if (ids.length) focusRequest.value = { id: ids[0]!, seq: ++focusSeq }
  }

  /** Models referenced by a relation but missing from `nodes`. */
  /**
   * What each node is called on screen. Ids are fully qualified class names;
   * see lib/classNames for how they are shortened.
   */
  const labels = computed(() => new Map(nodes.value.map((n) => [n.id, n.data?.label ?? n.id])))

  /** A model's on-screen name, including one the graph does not hold. */
  function labelOf(id: string): string {
    return labels.value.get(id) ?? baseName(id)
  }

  /** Whether any relation leaves its domain, so the legend can explain it. */
  const crossesDomains = computed(() =>
    edges.value.some((e) => (e.data as { crosses?: string | null } | undefined)?.crosses),
  )

  /**
   * The colour order for the graph: the page's domains first, as on every
   * other page, then any a model names that the page was not told about.
   */
  const palette = computed(() =>
    domainPalette(
      useDomainsStore().palette,
      nodes.value.flatMap((n) => (n.data?.context ? [n.data.context] : [])),
    ),
  )

  /** A domain's colour on the graph. */
  function contextColor(name: string): string {
    return domainColor(name, palette.value)
  }

  // One read-only view per domain with a model in it, in colour order. Read
  // from the graph rather than domains.json, so it costs nothing and follows
  // every live update. Models outside the scan have no domain.
  useViewsStore().deriveDomainViews(() => {
    const byDomain = new Map<string, string[]>()

    for (const node of nodes.value) {
      const context = node.data?.context
      if (context) byDomain.set(context, [...(byDomain.get(context) ?? []), node.id])
    }

    return palette.value
      .filter((name) => byDomain.has(name))
      .map((name) => ({
        id: DOMAIN_VIEW_PREFIX + name,
        name,
        models: byDomain.get(name)!,
        domain: name,
      }))
  })

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
   *
   * Saved positions are laid over the top for the same reason `applySchema`
   * lays them over the grid. `nodes` is only rebuilt when the schema changes,
   * so it still holds where each node was before anybody dragged it — and
   * every view switch hands Vue Flow this list afresh, which used to snap
   * each dragged node back to that spot. Only moved nodes are copied, so the
   * rest keep their identity.
   */
  const visibleNodes = computed(() => {
    const members = useViewsStore().activeModels
    const saved = useLayoutStore().positions
    const shown = members ? nodes.value.filter((n) => members.has(n.id)) : nodes.value

    return shown.map((node) => {
      const position = saved[node.id]
      if (!position || (position.x === node.position.x && position.y === node.position.y)) {
        return node
      }
      return { ...node, position: { ...position } }
    })
  })

  /**
   * The columns each model has to offer a handle for, so a card draws a dot on
   * exactly the rows an edge attaches to.
   */
  const keys = computed(() => keyColumns(lastSchema.value?.edges ?? []))

  /** Each model's column names, in the order its card lists them. */
  const columnNames = computed(
    () => new Map(nodes.value.map((n) => [n.id, n.data?.columns.map((c) => c.name) ?? []])),
  )

  /**
   * An edge needs both ends on screen, or it points into nothing.
   *
   * Handles are chosen here rather than in `applySchema` because they depend on
   * whether a card is expanded: a key column past the first few is listed only
   * then, and until it is the edge lands on the "+N more" row instead.
   */
  const visibleEdges = computed(() => {
    const members = useViewsStore().activeModels
    const shown = members
      ? edges.value.filter((e) => members.has(e.source) && members.has(e.target))
      : edges.value

    return shown.map((edge) => {
      const keys = (edge.data as { keys?: RelationKeys } | undefined)?.keys
      if (!keys) return edge

      const sourceHandle = handleFor(
        keys.source,
        columnNames.value.get(edge.source) ?? [],
        expanded.value.has(edge.source),
        'out',
      )
      const targetHandle = handleFor(
        keys.target,
        columnNames.value.get(edge.target) ?? [],
        expanded.value.has(edge.target),
        'in',
      )

      // Unchanged edges keep their identity, so Vue Flow is not handed a whole
      // new edge list every time one card opens.
      return sourceHandle === edge.sourceHandle && targetHandle === edge.targetHandle
        ? edge
        : { ...edge, sourceHandle, targetHandle }
    })
  })

  /**
   * Cards an edge attaches to as a whole, by side. A card's own handle sits
   * halfway down it, beside whichever column happens to be there; drawn where
   * nothing uses it, it would read as that column being a key.
   */
  const cardHandlesInUse = computed(() => {
    const used = { in: new Set<string>(), out: new Set<string>() }
    for (const edge of visibleEdges.value) {
      if ((edge.sourceHandle ?? NODE_HANDLE.out) === NODE_HANDLE.out) used.out.add(edge.source)
      if ((edge.targetHandle ?? NODE_HANDLE.in) === NODE_HANDLE.in) used.in.add(edge.target)
    }
    return used
  })

  /**
   * When the export behind the current graph was built.
   *
   * Distinct from `lastUpdated`, which is when this browser noticed a change —
   * the overview wants the age of the data, not the age of the tab.
   */
  const generatedAt = computed(() => lastSchema.value?.generated_at ?? null)

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

    const names = shortNames([...declared.keys(), ...externalIds])

    const toNode = (id: string, model: SchemaNode | null): Node<ModelNodeData> => ({
      id,
      type: 'model',
      // Overwritten by layoutGraph below; the grid owns position.
      position: { x: 0, y: 0 },
      data: {
        label: names.get(id) ?? baseName(id),
        table: model?.table ?? '—',
        className: model?.class ?? id,
        columns: normaliseColumns(model?.columns),
        relationCount: relationCounts.get(id) ?? 0,
        external: model === null,
        context: model?.context ?? null,
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

    const nextEdges = schema.edges.map((e) =>
      buildEdge(e, crossesInto(declared.get(e.source)?.context, declared.get(e.target)?.context)),
    )

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

      const routes = useRoutesStore()
      const jobs = useJobsStore()
      const providers = useProviderStore()
      const domains = useDomainsStore()
      const classes = useClassesStore()
      const boundaries = useBoundariesStore()

      try {
        // The derived surfaces all walk a far wider tree than the model signal
        // does, so each is asked for only once somebody has actually opened
        // the surface that needs it. A session on the graph asks for none; a
        // session on one asks for one.
        const asked = [
          routes.loaded ? 'routes=1' : null,
          jobs.loaded ? 'jobs=1' : null,
          providers.loaded ? 'providers=1' : null,
          domains.loaded ? 'domains=1' : null,
          classes.loaded ? 'classes=1' : null,
          boundaries.loaded ? 'boundaries=1' : null,
        ].filter((part) => part !== null)

        const res = await fetch(asked.length ? `${url!}?${asked.join('&')}` : url!, {
          cache: 'no-store',
        })
        if (!res.ok) return

        const payload = await res.json()
        const next = payload?.fingerprint

        if (typeof payload?.routes === 'string' && payload.routes !== routes.fingerprint) {
          // Independent of the schema half: a controller can change without any
          // model or migration moving, and the reverse is just as common.
          await routes.refresh()
        }

        // Independent again, and of the route half too: adding a dispatch to a
        // controller moves this signal and the route one, while adding a
        // property to a job moves only this one.
        if (typeof payload?.jobs === 'string' && payload.jobs !== jobs.fingerprint) {
          await jobs.refresh()
        }

        // And the provider trees, which move when a provider does or when any
        // constructor down a tree gains or loses a parameter — neither of which
        // need touch a model, a route or a job.
        if (typeof payload?.providers === 'string' && payload.providers !== providers.fingerprint) {
          await providers.refresh()
        }

        // And the domain list, which moves when a class is added, moved or
        // re-attributed anywhere under the source folders, or domains.json
        // is edited.
        if (typeof payload?.domains === 'string' && payload.domains !== domains.fingerprint) {
          await domains.refresh()
        }

        // And the class index, which walks the same folders but moves on far
        // more: any edit that adds or drops a reference between two classes.
        if (typeof payload?.classes === 'string' && payload.classes !== classes.fingerprint) {
          await classes.refresh()
        }

        // And the boundary check, which also moves when a rule is edited.
        if (
          typeof payload?.boundaries === 'string' &&
          payload.boundaries !== boundaries.fingerprint
        ) {
          await boundaries.refresh()
        }

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
    keys,
    cardHandlesInUse,
    stats,
    generatedAt,
    externalModels,
    labelOf,
    palette,
    contextColor,
    crossesDomains,
    lastUpdated,
    expanded,
    highlighted,
    focusRequest,
    highlight,
    focusModels,
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

/**
 * One relation as Vue Flow draws it.
 *
 * `crosses` is the domain the relation reaches into, when it leaves its own.
 * Such an edge is drawn thicker and says where it goes on its label, so a
 * boundary shows without colour: the relation families already use the ramp.
 */
function buildEdge(e: SchemaEdge, crosses: string | null = null): Edge {
  const family = familyFor(e.type)
  const selfLoop = e.source === e.target
  const name = e.keys?.via ? `${e.name} · ${e.keys.via}` : e.name
  return {
    // 12 model pairs share a source+target, so the relation name is required
    // for uniqueness — without it Vue Flow silently drops the duplicates.
    id: `${e.source}--${e.name}--${e.target}`,
    source: e.source,
    target: e.target,
    // The whole card until visibleEdges picks the key columns. Named rather
    // than left out: without an id Vue Flow takes the first handle on the
    // card, which is now whichever key row happens to come first.
    sourceHandle: NODE_HANDLE.out,
    targetHandle: NODE_HANDLE.in,
    // A many-to-many's columns are both `id`; the pivot table is the part
    // worth reading, so it rides along on the label.
    label: crosses ? `${name} → ${crosses}` : name,
    type: selfLoop ? 'smoothstep' : 'default',
    class: crosses ? 'crosses-domain' : undefined,
    data: { relationType: e.type, family: family.id, keys: e.keys, crosses },
    style: {
      stroke: family.color,
      strokeWidth: crosses ? 3 : 1.5,
      strokeDasharray: family.dashed ? '4 3' : undefined,
      // Read by the .selected glow in vue-flow-theme.css — CSS cannot recover
      // the stroke paint value, so the colour is handed over explicitly.
      '--edge-glow': family.color,
    } as Styles,
  }
}
