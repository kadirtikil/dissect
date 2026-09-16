<script setup lang="ts">
import { computed, markRaw, watch } from 'vue'
import { MarkerType, VueFlow, useVueFlow, type Edge, type Node, type Styles } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { storeToRefs } from 'pinia'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
// Must come last so it overrides Vue Flow's bundled theme.
import '@/assets/vue-flow-theme.css'

import ProviderNode from '@/components/provider-tree/tree/ProviderNode.vue'
import type { ProviderNodeData } from '@/types/providers'
import { confidenceDash, edgeKindSpec } from '@/lib/providerKinds'
import { layoutProviderTree } from '@/lib/providerLayout'
import { useProviderStore } from '@/stores/provider'

/**
 * One provider's tree.
 *
 * Its own Vue Flow instance, addressed by id: the model graph stays mounted for
 * the whole session, and two canvases sharing a default store would share a
 * viewport and a selection.
 */
const FLOW_ID = 'dissect-provider-tree'

const store = useProviderStore()
const { selected, expanded } = storeToRefs(store)

const { fitView, onNodesInitialized } = useVueFlow(FLOW_ID)

// markRaw: a stable, non-reactive definition keeps Vue Flow from re-registering
// the node type on every render.
const nodeTypes = markRaw({ provider: ProviderNode })

const positions = computed(() =>
  selected.value ? layoutProviderTree(selected.value.nodes, selected.value.edges) : {},
)

const nodes = computed<Node<ProviderNodeData>[]>(() => {
  const provider = selected.value
  if (!provider) return []

  return provider.nodes.map((node) => ({
    id: node.id,
    type: 'provider',
    position: positions.value[node.id] ?? { x: 0, y: 0 },
    // An open card floats over its neighbours rather than pushing them, so it
    // has to sit above them.
    zIndex: expanded.value.has(node.id) ? 10 : 0,
    data: {
      node,
      root: node.id === provider.id,
      sideEffects: node.id === provider.id ? provider.side_effects : {},
      deferred: node.id === provider.id && provider.deferred,
      provides: node.id === provider.id ? provider.provides : [],
    },
  }))
})

const edges = computed<Edge[]>(() =>
  (selected.value?.edges ?? []).map((edge) => {
    const spec = edgeKindSpec(edge.kind)
    const consumer = edge.consumer?.split('\\').pop()

    return {
      id: edge.id,
      source: edge.source,
      target: edge.target,
      type: 'default',
      label: consumer ? `${spec.label} · when ${consumer}` : spec.label,
      labelStyle: { fill: spec.color, fontFamily: 'var(--font-mono)', fontSize: '10px' },
      labelBgStyle: { fill: 'var(--background)' },
      markerEnd: { type: MarkerType.ArrowClosed, color: spec.color },
      style: {
        stroke: spec.color,
        strokeWidth: 1.5,
        strokeDasharray: confidenceDash(edge.confidence),
        // Read by the .selected glow in vue-flow-theme.css.
        '--edge-glow': spec.color,
      } as Styles,
    }
  }),
)

// Fit once the new tree's cards have been measured — before that there is
// nothing with a size to fit. Only on a change of provider: a refresh of the
// same one keeps whatever viewport somebody arranged.
let fitPending = true

watch(
  () => selected.value?.id,
  () => {
    fitPending = true
  },
)

onNodesInitialized(() => {
  if (!fitPending) return
  fitPending = false
  fitView({ padding: 0.2, maxZoom: 1.2 })
})
</script>

<template>
  <!-- Vue Flow needs an explicitly sized container or it renders zero-height. -->
  <div class="provider-tree relative h-full w-full">
    <VueFlow
      :id="FLOW_ID"
      :nodes="nodes"
      :edges="edges"
      :node-types="nodeTypes"
      :nodes-draggable="false"
      :nodes-connectable="false"
      :min-zoom="0.1"
      elevate-edges-on-select
    >
      <Background :gap="16" />
      <Controls :show-interactive="false" />
    </VueFlow>
  </div>
</template>
