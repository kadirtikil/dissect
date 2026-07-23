<script setup lang="ts">
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
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
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'
import { useViewsStore } from '@/stores/views'
import type { NodeDragEvent } from '@vue-flow/core'

const store = useSchemaStore()
const layout = useLayoutStore()
const views = useViewsStore()
const { visibleNodes, visibleEdges, status, error } = storeToRefs(store)
const { activeId, active } = storeToRefs(views)

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
