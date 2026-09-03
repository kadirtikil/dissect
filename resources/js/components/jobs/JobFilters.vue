<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { Layers, X } from '@lucide/vue'
import { useJobsStore } from '@/stores/jobs'
import { useViewsStore } from '@/stores/views'
import { kindColor, kindRank, kindSpec } from '@/lib/jobKinds'
import { DEFAULT_QUEUE, MIXED_QUEUE } from '@/lib/jobFilters'

const store = useJobsStore()
const views = useViewsStore()
const { search, kinds, queues, modelFilter, scopeToView, undispatchedOnly, facets, stats } =
  storeToRefs(store)
const { active } = storeToRefs(views)

/**
 * Only the kinds and queues this application actually has.
 *
 * Offering `notification` to a codebase with no queued notification is a chip
 * that can only ever return nothing — the facets describe the data, they are
 * not a fixed menu.
 */
const kindOptions = computed(() =>
  Object.keys(facets.value.kinds).sort((a, b) => kindRank(a) - kindRank(b)),
)

const queueOptions = computed(() =>
  Object.keys(facets.value.queues).sort((a, b) => {
    // The two that are not really queue names sort last: everything above them
    // is somewhere a worker can be pointed.
    const rank = (key: string) => (key === DEFAULT_QUEUE ? 1 : key === MIXED_QUEUE ? 2 : 0)

    return rank(a) - rank(b) || a.localeCompare(b)
  }),
)

const filtered = computed(
  () =>
    search.value !== '' ||
    kinds.value.length > 0 ||
    queues.value.length > 0 ||
    undispatchedOnly.value ||
    modelFilter.value !== null,
)
</script>

<template>
  <div class="shrink-0 border-b px-3 py-2">
    <div class="flex items-center gap-2">
      <input
        v-model="search"
        class="min-w-0 flex-1 rounded-sm border bg-background px-2 py-1 font-mono text-xs outline-none focus:border-ring"
        placeholder="Filter by name, class, queue or dispatcher…"
        aria-label="Filter jobs"
      />

      <span class="shrink-0 font-mono text-[10px] text-muted-foreground tabular-nums">
        {{ stats.visible }} / {{ stats.total }}
      </span>

      <button
        v-if="filtered"
        class="shrink-0 rounded-sm p-1 text-muted-foreground hover:text-foreground"
        aria-label="Clear all filters"
        @click="store.clearFilters()"
      >
        <X class="size-3.5" />
      </button>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-1">
      <button
        v-for="kind in kindOptions"
        :key="kind"
        class="rounded-sm border px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wide"
        :class="kinds.includes(kind) ? '' : 'border-transparent text-muted-foreground'"
        :style="
          kinds.includes(kind)
            ? {
                color: kindColor(kind),
                borderColor: `color-mix(in oklch, ${kindColor(kind)} 45%, transparent)`,
                backgroundColor: `color-mix(in oklch, ${kindColor(kind)} 10%, transparent)`,
              }
            : {}
        "
        :aria-pressed="kinds.includes(kind)"
        @click="store.toggleKind(kind)"
      >
        {{ kindSpec(kind).label }}
        <span class="ml-1 opacity-60 tabular-nums">{{ facets.kinds[kind] }}</span>
      </button>

      <span v-if="kindOptions.length && queueOptions.length" class="mx-1 text-muted-foreground">
        ·
      </span>

      <!-- The queue is where a worker is pointed, so it is the facet that maps
           to something operational rather than to what the class happens to be. -->
      <button
        v-for="queue in queueOptions"
        :key="queue"
        class="rounded-sm border px-1.5 py-0.5 font-mono text-[10px]"
        :class="
          queues.includes(queue)
            ? 'border-ring/40 bg-accent/10 text-foreground'
            : 'border-transparent text-muted-foreground'
        "
        :aria-pressed="queues.includes(queue)"
        @click="store.toggleQueue(queue)"
      >
        {{ queue }}
        <span class="ml-1 opacity-60 tabular-nums">{{ facets.queues[queue] }}</span>
      </button>
    </div>

    <div
      v-if="facets.undispatched || active || modelFilter"
      class="mt-2 flex flex-wrap items-center gap-2"
    >
      <!-- The finding no other tool reports: a queueable nothing was found to
           dispatch. Offered only when there is one, because a chip reading 0 is
           a control that cannot do anything. -->
      <button
        v-if="facets.undispatched"
        class="flex items-center gap-1 rounded-sm border px-1.5 py-0.5 font-mono text-[10px]"
        :class="
          undispatchedOnly
            ? 'border-ring/40 text-foreground'
            : 'border-transparent text-muted-foreground'
        "
        :aria-pressed="undispatchedOnly"
        title="Queueables no dispatch site was found for — dead code, or dispatched dynamically"
        @click="undispatchedOnly = !undispatchedOnly"
      >
        undispatched
        <span class="opacity-60 tabular-nums">{{ facets.undispatched }}</span>
      </button>

      <!-- A view means "the billing models"; the jobs carrying them are part of
           that same bounded context, so the scope carries across by default. -->
      <button
        v-if="active"
        class="flex items-center gap-1 rounded-sm border px-1.5 py-0.5 font-mono text-[10px]"
        :class="
          scopeToView && !modelFilter
            ? 'border-ring/40 text-foreground'
            : 'border-transparent text-muted-foreground'
        "
        :disabled="!!modelFilter"
        :aria-pressed="scopeToView && !modelFilter"
        :title="
          modelFilter
            ? 'Superseded while a single model is pinned'
            : `Only jobs carrying the models in “${active.name}”`
        "
        @click="scopeToView = !scopeToView"
      >
        <Layers class="size-3" />
        in “{{ active.name }}”
      </button>

      <button
        v-if="modelFilter"
        class="flex items-center gap-1 rounded-sm border border-ring/40 px-1.5 py-0.5 font-mono text-[10px]"
        :aria-label="`Stop filtering to ${modelFilter}`"
        @click="store.filterByModel(null)"
      >
        carrying {{ modelFilter }}
        <X class="size-3" />
      </button>
    </div>
  </div>
</template>
