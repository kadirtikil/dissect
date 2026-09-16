<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position, type NodeProps } from '@vue-flow/core'
import type { ProviderNodeData } from '@/types/providers'
import type { Confidence } from '@/types/routes'
import {
  CONFIDENCE_NOTE,
  ORIGIN_NOTE,
  SIDE_EFFECT_LABELS,
  nodeKindSpec,
} from '@/lib/providerKinds'
import { PROVIDER_NODE_WIDTH } from '@/lib/providerLayout'
import { useProviderStore } from '@/stores/provider'

// NodeProps (rather than a hand-rolled prop list) is what makes this component
// assignable to Vue Flow's NodeTypesObject.
const props = defineProps<NodeProps<ProviderNodeData>>()

const store = useProviderStore()

const node = computed(() => props.data.node)
const spec = computed(() => nodeKindSpec(node.value.kind))
const expanded = computed(() => store.expanded.has(props.id))

/** Anything that is not the app's own class is drawn as a boundary, not a box. */
const outside = computed(() => node.value.kind === 'unresolved' || node.value.origin !== 'app')

/** Only a card with something more to say is worth making clickable. */
const expandable = computed(
  () => node.value.summary !== null || node.value.class !== null || props.data.provides.length > 0,
)

const badges = computed(() =>
  Object.entries(props.data.sideEffects).map(([effect, count]) => ({
    label: SIDE_EFFECT_LABELS[effect] ?? effect,
    count: count ?? 0,
  })),
)

function toggle() {
  if (expandable.value) store.toggleExpanded(props.id)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    toggle()
  } else if (event.key === 'Escape' && expanded.value) {
    toggle()
  }
}
</script>

<template>
  <div
    class="relative flex flex-col gap-0.5 rounded-md border bg-card px-3 py-2 text-left transition-shadow"
    :class="[
      outside ? 'border-dashed bg-card/60' : 'shadow-sm hover:shadow-md',
      expandable ? 'cursor-pointer' : 'cursor-default',
      expanded ? 'shadow-lg' : '',
    ]"
    :style="{ width: `${PROVIDER_NODE_WIDTH}px` }"
    :role="expandable ? 'button' : undefined"
    :tabindex="expandable ? 0 : undefined"
    :aria-expanded="expandable ? expanded : undefined"
    :aria-label="`${node.label} — ${spec.label}`"
    @click="toggle"
    @keydown="onKeydown"
  >
    <Handle v-if="!data.root" type="target" :position="Position.Left" />

    <!-- Sigil bar in the kind's colour; muted for anything outside the app. -->
    <span
      class="absolute inset-y-0 left-0 w-[2px] rounded-l-md"
      :style="{ background: spec.color, opacity: outside ? 0.5 : 1 }"
    />

    <!-- The name gets the whole row: it is what somebody scans a tree for, and
         a provider's name is long before anything else is added to it. -->
    <span
      class="truncate font-mono text-[13px] font-semibold tracking-tight"
      :class="node.kind === 'unresolved' ? 'font-normal italic text-muted-foreground' : ''"
      :title="node.class ?? node.label"
    >
      {{ node.label }}
    </span>

    <div class="flex items-center gap-1.5 font-mono text-[10px] text-muted-foreground">
      <span class="text-[9px] tracking-wide uppercase" :style="{ color: spec.color }">
        {{ spec.label }}
      </span>

      <!-- An unresolved box has no origin to speak of, and a container key is
           better called what it is than "none". -->
      <span
        v-if="node.origin !== 'app' && node.kind !== 'unresolved'"
        :title="ORIGIN_NOTE[node.origin]"
      >
        {{ node.origin === 'none' ? 'key' : node.origin }}
      </span>

      <!-- Certain is the default and says nothing; the other two are the
           reason to look twice at a box. -->
      <span
        v-if="node.confidence !== 'certain'"
        class="rounded-sm bg-muted px-1 text-[9px]"
        :title="CONFIDENCE_NOTE[node.confidence as Confidence]"
      >
        {{ node.confidence }}
      </span>

      <span v-if="data.root && data.deferred" title="Deferred — loaded only when something it provides is resolved">
        deferred
      </span>
    </div>

    <!-- Side effects ride on the provider as counts, not as edges: drawn as
         arrows they would bury the dependencies. -->
    <div v-if="data.root && badges.length" class="mt-1 flex flex-wrap gap-1">
      <span
        v-for="badge in badges"
        :key="badge.label"
        class="rounded-sm bg-primary/10 px-1 font-mono text-[9px] leading-[14px] text-foreground/80"
      >
        {{ badge.label }}<template v-if="badge.count > 1"> ×{{ badge.count }}</template>
      </span>
    </div>

    <div v-if="expanded" class="mt-1 flex flex-col gap-1 border-t pt-1">
      <p v-if="node.summary" class="text-[11px] leading-snug text-foreground/90">{{ node.summary }}</p>

      <!-- The fully qualified class is what you need to go and open the file,
           and too long to carry on a collapsed card. -->
      <span v-if="node.class" class="font-mono text-[9px] break-all text-muted-foreground/80">
        {{ node.class }}
      </span>

      <span v-if="node.file" class="font-mono text-[9px] break-all text-muted-foreground/60">
        {{ node.file }}
      </span>

      <div v-if="data.root && data.provides.length" class="font-mono text-[9px] text-muted-foreground">
        provides: {{ data.provides.join(', ') }}
      </div>
    </div>

    <Handle type="source" :position="Position.Right" />
  </div>
</template>
