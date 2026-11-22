<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { Handle, Position, useVueFlow, type NodeProps } from '@vue-flow/core'
import type { ModelNodeData, SchemaColumn } from '@/types/schema'
import { LAYOUT_DIRECTION, MAX_VISIBLE_COLUMNS } from '@/lib/layout'
import { MORE_HANDLE, NODE_HANDLE, columnHandle } from '@/lib/relationHandles'
import { useSchemaStore } from '@/stores/schema'
import { useJobsStore } from '@/stores/jobs'
import { useNavigationStore } from '@/stores/navigation'

// Handles must face the way the ranks flow, or edges loop back on themselves.
const targetPosition = LAYOUT_DIRECTION === 'LR' ? Position.Left : Position.Top
const sourcePosition = LAYOUT_DIRECTION === 'LR' ? Position.Right : Position.Bottom

// NodeProps (rather than a hand-rolled prop list) is what makes this component
// assignable to Vue Flow's NodeTypesObject.
const props = defineProps<NodeProps<ModelNodeData>>()

const store = useSchemaStore()
const expanded = computed(() => store.expanded.has(props.id))

/**
 * Ringed because a job on the jobs surface carries this model.
 *
 * Read from the store rather than carried on the node's data: the nodes array
 * is replaced wholesale by applySchema, so putting it there would mean
 * rebuilding every node each time a different endpoint is selected.
 */
const highlighted = computed(() => store.highlighted.has(props.id))

/** The domain's colour, or null for a model in none. */
const contextColor = computed(() =>
  props.data.context ? store.contextColor(props.data.context) : null,
)

const nav = useNavigationStore()
const jobs = useJobsStore()

/**
 * Jobs carrying this model, or null when the list has not been fetched.
 *
 * Null is offered as a link anyway: the whole point of the card's link is to go
 * and look, and refusing to show it until jobs.json has been loaded would mean
 * it only ever appears to somebody who has already been there.
 */
const jobCount = computed(() => (jobs.loaded ? (jobs.countByModel[props.id] ?? 0) : null))

/** The reverse of the "carries" chip on a job. */
function openJobs(event: MouseEvent) {
  // The card itself toggles on click; this is a different intent.
  event.stopPropagation()
  jobs.filterByModel(props.id)
  nav.go('jobs')
}

// MAX_VISIBLE_COLUMNS is shared with the layout, which reserves vertical space
// from the same number — diverging here would overlap the node below. Expanded
// nodes deliberately ignore it: they float above the graph instead of taking
// part in it, so their height costs the layout nothing.
const visibleColumns = computed(() =>
  expanded.value ? props.data.columns : props.data.columns.slice(0, MAX_VISIBLE_COLUMNS),
)
const hiddenColumnCount = computed(() =>
  expanded.value ? 0 : Math.max(0, props.data.columns.length - MAX_VISIBLE_COLUMNS),
)

/**
 * The columns of this model a relation leaves from or arrives at. Each gets a
 * handle on its own row, so an edge starts at `author_id` rather than at the
 * card as a whole; see lib/relationHandles.
 */
const keys = computed(() => store.keys.get(props.id))

/** Whether a key column is folded into "+N more", which then stands in for it. */
const hiddenKeys = computed(() => {
  const hidden = props.data.columns.slice(visibleColumns.value.length).map((col) => col.name)
  return {
    in: hidden.some((name) => keys.value?.in.has(name)),
    out: hidden.some((name) => keys.value?.out.has(name)),
  }
})

/**
 * A row's handle sits on the card's border, like the card's own handles. The
 * row is inset by the card's padding (px-3), so the handle is pushed out by it.
 */
const rowHandleStyle = {
  in: { left: 'calc(-0.75rem - 4px)' },
  out: { right: 'calc(-0.75rem - 4px)' },
}

/**
 * Vue Flow measures where a card's handles are when the card resizes. Opening
 * a card does resize it, but the rows it adds can render after that
 * measurement, and scrolling an open card moves rows without resizing
 * anything. Both would leave edges pointing at where a row used to be.
 */
const { updateNodeInternals } = useVueFlow()
const remeasure = () => nextTick(() => updateNodeInternals([props.id]))
watch([expanded, keys], remeasure)

// A model with no $fillable reports every column as non-fillable, which would
// stamp "guarded" on every row and say nothing. The flag is only meaningful
// once the model actually declares a fillable set.
const declaresFillable = computed(() => props.data.columns.some((col) => col.fillable))

/**
 * The short flags rendered beside a column when expanded. Only what is true is
 * shown — a row of mostly-absent markers reads as noise.
 */
function flagsFor(col: SchemaColumn): { text: string; title: string; strong?: boolean }[] {
  const flags: { text: string; title: string; strong?: boolean }[] = []

  if (col.increments) flags.push({ text: 'pk', title: 'Auto-incrementing key', strong: true })
  if (col.unique && !col.increments) flags.push({ text: 'uq', title: 'Unique', strong: true })
  if (col.nullable) flags.push({ text: 'null', title: 'Nullable' })
  // Guarded is the notable case: where a fillable set exists, the columns
  // outside it are what you want to spot.
  if (declaresFillable.value && !col.fillable && !col.virtual) {
    flags.push({ text: 'guarded', title: 'Not fillable' })
  }
  if (col.hidden) flags.push({ text: 'hidden', title: 'Hidden from serialisation' })
  if (col.cast) flags.push({ text: col.cast, title: `Cast to ${col.cast}` })

  return flags
}

// A drag ends with a click, so a click alone cannot mean "expand" — the pointer
// has to have stayed put. Same threshold the canvas uses to decide whether a
// move was deliberate.
const CLICK_SLOP_PX = 3
let pressedAt: { x: number; y: number } | null = null

function onPointerDown(event: PointerEvent) {
  pressedAt = { x: event.clientX, y: event.clientY }
}

function onClick(event: MouseEvent) {
  const from = pressedAt
  pressedAt = null
  if (!from) return

  // Shift and ⌘/Ctrl clicks are Vue Flow's multi-selection gestures — building
  // a view out of six models should not leave six cards open behind it.
  if (event.shiftKey || event.metaKey || event.ctrlKey) return

  const moved =
    Math.abs(from.x - event.clientX) > CLICK_SLOP_PX ||
    Math.abs(from.y - event.clientY) > CLICK_SLOP_PX
  if (moved) return

  store.toggleExpanded(props.id)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    store.toggleExpanded(props.id)
  } else if (event.key === 'Escape' && expanded.value) {
    store.toggleExpanded(props.id)
  }
}
</script>

<template>
  <div
    class="model-node group relative flex flex-col gap-0.5 rounded-md border bg-card px-3 py-2 text-left transition-shadow"
    :class="[
      data.external
        ? 'border-dashed border-border/70 bg-card/60'
        : 'border-border shadow-sm hover:shadow-md',
      expanded ? 'is-expanded w-[260px] shadow-lg' : 'w-[200px]',
      highlighted ? 'is-highlighted' : '',
    ]"
    role="button"
    tabindex="0"
    :aria-expanded="expanded"
    :aria-label="`${data.label} — ${expanded ? 'hide' : 'show'} all attributes`"
    @pointerdown="onPointerDown"
    @click="onClick"
    @keydown="onKeydown"
  >
    <!-- Sigil bar: the domain's colour, so a domain reads as one patch of
         colour across a big graph; solid for other scanned models, muted for
         external ones. -->
    <span
      class="absolute inset-y-0 left-0 w-[2px] rounded-l-md"
      :class="contextColor ? '' : data.external ? 'bg-muted-foreground/40' : 'bg-primary'"
      :style="contextColor ? { background: contextColor } : undefined"
    />

    <div class="flex items-baseline justify-between gap-2">
      <span class="font-mono text-[13px] font-semibold tracking-tight">{{ data.label }}</span>
      <span class="font-mono text-[10px] text-muted-foreground tabular-nums">
        {{ data.relationCount }}
      </span>
    </div>

    <!-- The domain shares the table's line, so a card is the same height in
         an app with domains as without, and the grid needs no new estimate. -->
    <div class="flex items-baseline justify-between gap-2 font-mono text-[10px]">
      <span class="truncate text-muted-foreground">
        {{ data.external ? 'not in scan' : data.table }}
      </span>
      <span
        v-if="data.context && contextColor"
        class="flex max-w-[50%] shrink-0 items-center gap-1"
        :style="{ color: contextColor }"
        :title="`In the ${data.context} domain`"
        data-testid="model-domain"
      >
        <span class="size-1.5 shrink-0 rounded-full" :style="{ background: contextColor }" />
        <span class="truncate">{{ data.context }}</span>
      </span>
    </div>

    <!-- The fully qualified class is what you need to go and open the file, but
         it is too long to carry on a collapsed card. -->
    <span
      v-if="expanded && !data.external"
      class="font-mono text-[9px] break-all text-muted-foreground/70"
    >
      {{ data.className }}
    </span>

    <!-- Only models the exporter found columns for get a list. -->
    <ul
      v-if="data.columns.length"
      class="mt-1 flex flex-col gap-px border-t pt-1"
      :class="expanded ? 'nowheel max-h-[380px] overflow-y-auto' : ''"
      @scroll.passive="remeasure"
    >
      <li
        v-for="col in visibleColumns"
        :key="col.name"
        class="font-mono text-[10px] leading-[14px] text-muted-foreground"
      >
        <div class="relative flex items-baseline justify-between gap-2">
          <Handle
            v-if="keys?.in.has(col.name)"
            :id="columnHandle(col.name, 'in')"
            type="target"
            :position="Position.Left"
            :style="rowHandleStyle.in"
          />
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
          <Handle
            v-if="keys?.out.has(col.name)"
            :id="columnHandle(col.name, 'out')"
            type="source"
            :position="Position.Right"
            :style="rowHandleStyle.out"
          />
        </div>

        <div v-if="expanded" class="flex flex-wrap gap-1 pb-0.5">
          <span
            v-for="flag in flagsFor(col)"
            :key="flag.text"
            class="rounded-sm px-1 text-[8px] leading-[12px]"
            :class="
              flag.strong ? 'bg-primary/15 text-foreground/80' : 'bg-muted text-muted-foreground/80'
            "
            :title="flag.title"
          >
            {{ flag.text }}
          </span>
        </div>
      </li>
      <li
        v-if="hiddenColumnCount"
        class="relative font-mono text-[10px] leading-[14px] text-muted-foreground/70 italic"
      >
        <Handle
          v-if="hiddenKeys.in"
          :id="MORE_HANDLE.in"
          type="target"
          :position="Position.Left"
          :style="rowHandleStyle.in"
        />
        +{{ hiddenColumnCount }} more
        <Handle
          v-if="hiddenKeys.out"
          :id="MORE_HANDLE.out"
          type="source"
          :position="Position.Right"
          :style="rowHandleStyle.out"
        />
      </li>
    </ul>

    <!-- The way out to the other surface. Only on an expanded card: a collapsed
         one is a shape on a board, and adding a control to two hundred of them
         would cost more than it gives.

         Endpoints are deliberately not here. A route is described by its own
         contract, not by which models it happens to mention, so there is no
         "this model's endpoints" to link to. -->
    <div v-if="expanded && !data.external" class="mt-1 flex items-center gap-3 border-t pt-1">
      <button
        class="flex items-center gap-1 font-mono text-[10px] text-muted-foreground hover:text-foreground"
        :aria-label="`Show jobs carrying ${data.label}`"
        @pointerdown.stop
        @click="openJobs"
      >
        Jobs
        <span v-if="jobCount !== null" class="tabular-nums">· {{ jobCount }}</span>
        <span aria-hidden="true">↗</span>
      </button>
    </div>

    <!-- The card as a whole: for a relation whose columns are not known.
         Always present, so an edge can attach the moment it needs to, but
         only drawn while one does. -->
    <Handle
      :id="NODE_HANDLE.in"
      type="target"
      :position="targetPosition"
      :class="{ 'opacity-0': !store.cardHandlesInUse.in.has(id) }"
    />
    <Handle
      :id="NODE_HANDLE.out"
      type="source"
      :position="sourcePosition"
      :class="{ 'opacity-0': !store.cardHandlesInUse.out.has(id) }"
    />
  </div>
</template>

<style scoped>
.model-node {
  /* Vue Flow's .selected styling targets its own node wrapper, which this
     custom node replaces, so selection is styled here instead. */
  outline: none;
}

/* Arrived at from an endpoint. Deliberately a different colour from selection:
   one is "you picked this", the other is "this is what that endpoint touches",
   and both can be true of different nodes at the same time. */
.model-node.is-highlighted {
  border-color: var(--accent);
  box-shadow:
    0 0 0 1px var(--accent),
    0 0 20px -2px color-mix(in oklch, var(--accent) 55%, transparent);
}

:global(.vue-flow__node.selected) .model-node {
  border-color: var(--ring);
  box-shadow:
    0 0 0 1px var(--ring),
    0 0 16px -2px color-mix(in oklch, var(--ring) 60%, transparent);
}

/* The stacking that floats .is-expanded over the graph lives in
   vue-flow-theme.css, with the rest of the canvas layering it has to agree
   with. */
</style>
