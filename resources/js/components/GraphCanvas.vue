<script setup lang="ts">
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import { storeToRefs } from 'pinia'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/minimap/dist/style.css'
// Must come last so it overrides Vue Flow's bundled theme.
import '@/assets/vue-flow-theme.css'

import ModelNode from '@/components/ModelNode.vue'
import GraphLegend from '@/components/GraphLegend.vue'
import ViewMenu from '@/components/ViewMenu.vue'
import { Button } from '@/components/ui/button'
import { ChevronsDownUp, RotateCcw } from '@lucide/vue'
import { PAGE_ACTIONS, PAGE_CONTEXT } from '@/lib/toolbar'
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'
import { useViewsStore } from '@/stores/views'
import { useNavigationStore } from '@/stores/navigation'
import type { NodeDragEvent } from '@vue-flow/core'

const store = useSchemaStore()
const layout = useLayoutStore()
const views = useViewsStore()
const nav = useNavigationStore()
const { visibleNodes, visibleEdges, status, error, focusRequest, stats, expanded } =
  storeToRefs(store)
const { activeId, active } = storeToRefs(views)
const { positions, saving } = storeToRefs(layout)
const { current: page } = storeToRefs(nav)

const pinnedCount = computed(() => Object.keys(positions.value).length)

// Two-step rather than a modal: resetting throws away hand-placed nodes, but a
// dialog for a one-key action is heavier than the decision warrants.
const confirming = ref(false)
let revertTimer: ReturnType<typeof setTimeout> | undefined

function askReset() {
  confirming.value = true
  clearTimeout(revertTimer)
  // Don't leave the button armed indefinitely if the user walks away.
  revertTimer = setTimeout(() => (confirming.value = false), 4000)
}

async function confirmReset() {
  clearTimeout(revertTimer)
  confirming.value = false
  await layout.reset()
  // Positions are gone; re-place every node back onto the grid.
  store.relayout()
}

const { fitView, getSelectedNodes, removeSelectedElements } = useVueFlow()

// markRaw: Vue must not try to make the component definition reactive, and a
// stable object keeps Vue Flow from re-registering node types on every render.
const nodeTypes = markRaw({ model: ModelNode })

let stopWatching: (() => void) | undefined

onMounted(async () => {
  // Layout first: applySchema reads saved positions while placing nodes, so
  // loading it second would render the grid and then jump.
  await layout.load()
  await views.load()
  await store.load()

  // Keeps the graph in step with the app: an edited relation or a migration
  // that has actually run re-exports in place, without a reload.
  stopWatching = store.watchForChanges()
})

onBeforeUnmount(() => stopWatching?.())

/**
 * Switching view leaves the remaining nodes wherever they were on the full
 * board — which, for a handful of models out of eighty, is mostly empty canvas.
 * Refitting is what makes the switch read as "now showing these".
 *
 * Membership counts as a change too: a model ticked into the view sits wherever
 * it sits on the whole board, which can be well outside the current viewport.
 * Without the refit, adding one would look like nothing had happened.
 */
const shownModels = computed(
  () => `${activeId.value ?? ''}:${active.value?.models.join(',') ?? ''}`,
)

watch(shownModels, async (_next, previous) => {
  // The selection a view was just built from would otherwise stay lit on the
  // nodes that survived the switch, reading as "these are special" when they
  // are simply what the view contains.
  if (previous.split(':')[0] !== (activeId.value ?? '')) removeSelectedElements()

  await nextTick()
  fitView({ padding: 0.2, duration: 200 })
})

/**
 * Somebody followed a model link from an endpoint.
 *
 * Landing on the canvas with the model somewhere off screen is the same as not
 * having followed anything, so the viewport goes to it. A hidden model — one
 * the active view filters out — has nothing to fit, and zooming to an empty
 * spot would be worse than staying put.
 */
watch(focusRequest, async (request) => {
  if (!request) return
  await nextTick()
  if (!visibleNodes.value.some((n) => n.id === request.id)) return

  fitView({ nodes: [request.id], padding: 0.6, maxZoom: 1.2, duration: 300 })
})

/**
 * Vue Flow owns the selection; the view menu needs it to offer "save these as
 * a view". Mirrored into the store because the menu lives in the header, well
 * outside the provider this canvas sets up.
 */
watch(getSelectedNodes, (selected) => {
  views.selection = selected.map((n) => n.id)
})

/** Below this, a "drag" is an accidental nudge rather than a placement. */
const DRAG_THRESHOLD_PX = 3
const dragOrigins = new Map<string, { x: number; y: number }>()

function onNodeDragStart(event: NodeDragEvent) {
  const dragging = event.nodes?.length ? event.nodes : [event.node]
  for (const node of dragging) {
    dragOrigins.set(node.id, { x: node.position.x, y: node.position.y })
  }
}

function onNodeDragStop(event: NodeDragEvent) {
  // Dragging a multi-selection moves several nodes; `nodes` carries all of
  // them, while `node` is only the one under the cursor.
  const moved = event.nodes?.length ? event.nodes : [event.node]
  const knownIds = store.nodes.map((n) => n.id)

  for (const node of moved) {
    const from = dragOrigins.get(node.id)
    // A stray click registers as a drag of a pixel or two. Persisting those
    // silently pins nodes at their current spot, which then stops them being
    // re-flowed by the grid — so only a deliberate move is written.
    if (from && Math.abs(from.x - node.position.x) < DRAG_THRESHOLD_PX) {
      if (Math.abs(from.y - node.position.y) < DRAG_THRESHOLD_PX) continue
    }

    layout.setPosition(node.id, { x: node.position.x, y: node.position.y }, knownIds)
  }

  dragOrigins.clear()
}
</script>

<template>
  <!-- Vue Flow needs an explicitly sized container or it renders zero-height. -->
  <div class="relative h-full w-full">
    <!-- Declared in here to keep this component single-root, which is what lets
         the shell hide it with v-show. Teleport moves the content out either
         way, so where it is written costs nothing.

         Withheld by hand because this canvas stays mounted while another
         surface is on screen — a page that unmounts gets the same for free. -->
    <template v-if="page === 'models'">
    <Teleport defer :to="PAGE_CONTEXT">
      <span v-if="status === 'ready'" class="font-mono text-xs text-muted-foreground">
        <!-- While a view is open the totals describe the schema, not what is on
             screen, so the visible count is what leads. -->
        <template v-if="active">
          {{ stats.visible }} of {{ stats.models + stats.external }} models
        </template>
        <template v-else>
          {{ stats.models }} models · {{ stats.relations }} relations
          <template v-if="stats.external"> · {{ stats.external }} external </template>
        </template>
      </span>
      <span v-else class="font-mono text-xs text-muted-foreground">Model relationships</span>

      <ViewMenu v-if="status === 'ready'" />
    </Teleport>

    <Teleport defer :to="PAGE_ACTIONS">
      <!-- Expanded cards float over their neighbours, so with several open the
           board is hard to read — this is the way back out without hunting for
           each one. -->
      <Button
        v-if="expanded.size"
        variant="ghost"
        size="sm"
        class="font-mono text-xs"
        :aria-label="`Collapse ${expanded.size} expanded models`"
        @click="store.collapseAll()"
      >
        <ChevronsDownUp class="size-3.5" />
        Collapse · {{ expanded.size }}
      </Button>

      <!-- Only offer the reset once something has actually been placed. -->
      <Button
        v-if="pinnedCount"
        :variant="confirming ? 'destructive' : 'ghost'"
        size="sm"
        class="font-mono text-xs"
        :disabled="saving"
        :aria-label="
          confirming
            ? 'Confirm resetting the saved layout'
            : `Reset saved layout (${pinnedCount} placed)`
        "
        @click="confirming ? confirmReset() : askReset()"
      >
        <RotateCcw class="size-3.5" />
        {{ confirming ? 'Reset layout?' : `Reset layout · ${pinnedCount}` }}
      </Button>
    </Teleport>
    </template>

    <div
      v-if="status === 'loading'"
      class="absolute inset-0 z-20 grid place-items-center font-mono text-xs text-muted-foreground"
    >
      Reading schema…
    </div>

    <div v-else-if="status === 'error'" class="absolute inset-0 z-20 grid place-items-center px-6">
      <div class="max-w-md rounded-md border border-destructive/40 bg-card px-4 py-3">
        <p class="font-mono text-xs font-semibold text-destructive">Could not load schema.json</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>
    </div>

    <!-- A view names models by id, and those ids can stop existing: a renamed
         model, or a whole different models directory (the generated fixture).
         Filtering to nothing then looks exactly like a broken page. -->
    <div
      v-if="status === 'ready' && active && !visibleNodes.length"
      class="absolute inset-0 z-20 grid place-items-center px-6"
    >
      <div class="max-w-md rounded-md border bg-card px-4 py-3 text-center">
        <p class="font-mono text-xs font-semibold">
          Nothing to show in &ldquo;{{ active.name }}&rdquo;
        </p>
        <p class="mt-1 font-mono text-[11px] text-muted-foreground">
          None of its {{ active.models.length }} models are in the current schema. Pick another
          view, or tick models into this one.
        </p>
      </div>
    </div>

    <GraphLegend v-if="status === 'ready'" />

    <VueFlow
      :nodes="visibleNodes"
      :edges="visibleEdges"
      :node-types="nodeTypes"
      :min-zoom="0.1"
      :default-edge-options="{ type: 'default' }"
      elevate-edges-on-select
      fit-view-on-init
      @node-drag-start="onNodeDragStart"
      @node-drag-stop="onNodeDragStop"
    >
      <Background :gap="16" />
      <Controls />
      <MiniMap pannable zoomable />
    </VueFlow>
  </div>
</template>
