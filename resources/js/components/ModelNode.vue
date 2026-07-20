<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position, type NodeProps } from '@vue-flow/core'
import type { ModelNodeData } from '@/types/schema'
import { LAYOUT_DIRECTION, MAX_VISIBLE_COLUMNS } from '@/lib/layout'

// Handles must face the way the ranks flow, or edges loop back on themselves.
const targetPosition = LAYOUT_DIRECTION === 'LR' ? Position.Left : Position.Top
const sourcePosition = LAYOUT_DIRECTION === 'LR' ? Position.Right : Position.Bottom

// NodeProps (rather than a hand-rolled prop list) is what makes this component
// assignable to Vue Flow's NodeTypesObject.
const props = defineProps<NodeProps<ModelNodeData>>()

// MAX_VISIBLE_COLUMNS is shared with the layout, which reserves vertical space
// from the same number — diverging here would overlap the node below.
const visibleColumns = computed(() => props.data.columns.slice(0, MAX_VISIBLE_COLUMNS))
const hiddenColumnCount = computed(() =>
  Math.max(0, props.data.columns.length - MAX_VISIBLE_COLUMNS),
)
</script>

<template>
  <div
    class="model-node group relative flex w-[200px] flex-col gap-0.5 rounded-md border bg-card px-3 py-2 text-left transition-shadow"
    :class="
      data.external
        ? 'border-dashed border-border/70 bg-card/60'
        : 'border-border shadow-sm hover:shadow-md'
    "
  >
    <!-- Sigil bar: solid for scanned models, muted for external ones. -->
    <span
      class="absolute inset-y-0 left-0 w-[2px] rounded-l-md"
      :class="data.external ? 'bg-muted-foreground/40' : 'bg-primary'"
    />

    <div class="flex items-baseline justify-between gap-2">
      <span class="font-mono text-[13px] font-semibold tracking-tight">{{ data.label }}</span>
      <span class="font-mono text-[10px] text-muted-foreground tabular-nums">
        {{ data.relationCount }}
      </span>
    </div>

    <span class="truncate font-mono text-[10px] text-muted-foreground">
      {{ data.external ? 'not in scan' : data.table }}
    </span>

    <!-- Only models the exporter found columns for get a list. -->
    <ul v-if="data.columns.length" class="mt-1 flex flex-col gap-px border-t pt-1">
      <li
        v-for="col in visibleColumns"
        :key="col.name"
        class="flex items-baseline justify-between gap-2 font-mono text-[10px] leading-[14px] text-muted-foreground"
      >
        <span class="truncate" :class="{ 'italic opacity-70': col.virtual }">{{ col.name }}</span>
        <!-- Short label is shown; the verbatim database type is on the tooltip,
             since that is what you want when chasing a migration issue. -->
        <span
          v-if="col.label"
          class="shrink-0 text-[9px] opacity-60"
          :title="col.type ?? undefined"
        >
          {{ col.label }}
        </span>
      </li>
      <li
        v-if="hiddenColumnCount"
        class="font-mono text-[10px] leading-[14px] text-muted-foreground/70 italic"
      >
        +{{ hiddenColumnCount }} more
      </li>
    </ul>

    <Handle type="target" :position="targetPosition" />
    <Handle type="source" :position="sourcePosition" />
  </div>
</template>

<style scoped>
.model-node {
  /* Vue Flow's .selected styling targets its own node wrapper, which this
     custom node replaces, so selection is styled here instead. */
  outline: none;
}

:global(.vue-flow__node.selected) .model-node {
  border-color: var(--ring);
  box-shadow:
    0 0 0 1px var(--ring),
    0 0 16px -2px color-mix(in oklch, var(--ring) 60%, transparent);
}
</style>
