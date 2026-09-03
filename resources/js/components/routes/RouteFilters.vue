<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { X } from '@lucide/vue'
import { useRoutesStore } from '@/stores/routes'
import { methodColor, methodRank } from '@/lib/httpMethods'
import { STACK_ORDER, stackSpec } from '@/lib/routeStacks'

const store = useRoutesStore()
const { search, methods, groups, stacks, facets, stats } = storeToRefs(store)

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

/**
 * The switcher's options.
 *
 * `web` and `api` are always offered — an application with none of one is worth
 * saying so about, and a switch that appears and disappears is worse than one
 * reading zero. `other` is different: most applications have no such route, and
 * an option that can only ever return nothing is a dead end.
 */
const stackOptions = computed(() =>
  STACK_ORDER.filter((stack) => stack !== 'other' || (facets.value.stacks.other ?? 0) > 0),
)

const activeStack = computed(() => stacks.value[0] ?? null)

const filtered = computed(
  () =>
    search.value !== '' ||
    methods.value.length > 0 ||
    groups.value.length > 0 ||
    stacks.value.length > 0,
)
</script>

<template>
  <div class="shrink-0 border-b px-3 py-2">
    <!-- The first question anybody has about an endpoint list: is this
         something my own frontend calls, or something I have promised to the
         outside world. A switcher rather than another chip, because the answer
         changes what breaking the endpoint costs. -->
    <div class="mb-2 flex items-center gap-1 rounded-md bg-muted/60 p-0.5">
      <button
        class="flex-1 rounded-sm px-2 py-1 font-mono text-[10px] tracking-wide uppercase"
        :class="
          activeStack === null
            ? 'bg-background text-foreground shadow-sm'
            : 'text-muted-foreground hover:text-foreground'
        "
        :aria-pressed="activeStack === null"
        title="Every route the router knows about"
        @click="store.showStack(null)"
      >
        All
        <span class="ml-1 opacity-60 tabular-nums">{{ stats.total }}</span>
      </button>

      <button
        v-for="stack in stackOptions"
        :key="stack"
        class="flex-1 rounded-sm px-2 py-1 font-mono text-[10px] tracking-wide uppercase"
        :style="activeStack === stack ? { color: stackSpec(stack).color } : undefined"
        :class="
          activeStack === stack
            ? 'bg-background shadow-sm'
            : 'text-muted-foreground hover:text-foreground'
        "
        :aria-pressed="activeStack === stack"
        :title="stackSpec(stack).hint"
        @click="store.showStack(stack)"
      >
        {{ stackSpec(stack).label }}
        <span class="ml-1 opacity-60 tabular-nums">{{ facets.stacks[stack] ?? 0 }}</span>
      </button>
    </div>

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
  </div>
</template>
