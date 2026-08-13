<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { Layers, X } from '@lucide/vue'
import { useRoutesStore } from '@/stores/routes'
import { useViewsStore } from '@/stores/views'
import { methodColor, methodRank } from '@/lib/httpMethods'

const store = useRoutesStore()
const views = useViewsStore()
const { search, methods, groups, modelFilter, scopeToView, facets, stats } = storeToRefs(store)
const { active } = storeToRefs(views)

/**
 * Only the verbs and groups this application actually has.
 *
 * Offering DELETE to a codebase with no DELETE route is a chip that can only
 * ever return nothing — the facets describe the data, they are not a fixed menu.
 */
const methodOptions = computed(() =>
  Object.keys(facets.value.methods).sort((a, b) => methodRank(a) - methodRank(b)),
)

// Fixed order: "mine, then everybody else's" is the order somebody reads them in.
const GROUP_ORDER = ['app', 'vendor', 'framework']

const groupOptions = computed(() =>
  Object.keys(facets.value.groups).sort(
    (a, b) => GROUP_ORDER.indexOf(a) - GROUP_ORDER.indexOf(b),
  ),
)

const filtered = computed(
  () =>
    search.value !== '' ||
    methods.value.length > 0 ||
    groups.value.length > 0 ||
    modelFilter.value !== null,
)
</script>

<template>
  <div class="shrink-0 border-b px-3 py-2">
    <div class="flex items-center gap-2">
      <input
        v-model="search"
        class="min-w-0 flex-1 rounded-sm border bg-background px-2 py-1 font-mono text-xs outline-none focus:border-ring"
        placeholder="Filter by path, name, controller or verb…"
        aria-label="Filter endpoints"
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
        v-for="method in methodOptions"
        :key="method"
        class="rounded-sm border px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wide"
        :class="methods.includes(method) ? '' : 'border-transparent text-muted-foreground'"
        :style="
          methods.includes(method)
            ? {
                color: methodColor([method]),
                borderColor: `color-mix(in oklch, ${methodColor([method])} 45%, transparent)`,
                backgroundColor: `color-mix(in oklch, ${methodColor([method])} 10%, transparent)`,
              }
            : {}
        "
        :aria-pressed="methods.includes(method)"
        @click="store.toggleMethod(method)"
      >
        {{ method }}
        <span class="ml-1 opacity-60 tabular-nums">{{ facets.methods[method] }}</span>
      </button>

      <span v-if="methodOptions.length && groupOptions.length" class="mx-1 text-muted-foreground">
        ·
      </span>

      <!-- Nothing is filtered out of the export, so this is the whole route
           table sliced by whose code it is — not a hidden set being revealed. -->
      <button
        v-for="group in groupOptions"
        :key="group"
        class="rounded-sm border px-1.5 py-0.5 font-mono text-[10px]"
        :class="
          groups.includes(group)
            ? 'border-ring/40 bg-accent/10 text-foreground'
            : 'border-transparent text-muted-foreground'
        "
        :aria-pressed="groups.includes(group)"
        @click="store.toggleGroup(group)"
      >
        {{ group }}
        <span class="ml-1 opacity-60 tabular-nums">{{ facets.groups[group] }}</span>
      </button>
    </div>

    <!-- A view means "the billing models"; the endpoints touching them are part
         of that same bounded context, so the scope carries across by default. -->
    <div v-if="active || modelFilter" class="mt-2 flex flex-wrap items-center gap-2">
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
            : `Only endpoints touching the models in “${active.name}”`
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
        touching {{ modelFilter }}
        <X class="size-3" />
      </button>
    </div>
  </div>
</template>
