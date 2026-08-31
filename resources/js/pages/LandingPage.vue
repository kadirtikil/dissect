<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { ArrowRight, Boxes, Route, Timer } from '@lucide/vue'
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'
import { useViewsStore } from '@/stores/views'
import { useRoutesStore } from '@/stores/routes'
import { useJobsStore } from '@/stores/jobs'
import { useNavigationStore } from '@/stores/navigation'

const schema = useSchemaStore()
const layout = useLayoutStore()
const views = useViewsStore()
const routes = useRoutesStore()
const jobs = useJobsStore()
const nav = useNavigationStore()

const { status, stats, generatedAt } = storeToRefs(schema)
const { positions } = storeToRefs(layout)
const { views: savedViews } = storeToRefs(views)
// Only ever read, never awaited: see the cards below.
const { loaded: routesLoaded, stats: routeStats } = storeToRefs(routes)
const { loaded: jobsLoaded, stats: jobStats } = storeToRefs(jobs)

/**
 * Everything here comes off the boot payload the page was rendered with.
 *
 * That is the whole design of this page: an overview that fetched its own
 * numbers would make the first screen the most expensive one, which is the
 * opposite of what an overview is for.
 */
const tiles = computed(() => [
  { label: 'Models', value: stats.value.models, hint: 'Eloquent models in the scan' },
  { label: 'Relations', value: stats.value.relations, hint: 'Edges between them' },
  {
    label: 'External',
    value: stats.value.external,
    // Not a failure — vendor models legitimately sit outside the scan — but it
    // is the number that tells you the blueprint has edges leading off it.
    hint: 'Referenced but outside the scan',
  },
  { label: 'Views', value: savedViews.value.length, hint: 'Saved subsets of the graph' },
  {
    label: 'Placed',
    value: Object.keys(positions.value).length,
    hint: 'Models arranged by hand',
  },
])

const generated = computed(() => {
  if (!generatedAt.value) return null

  const at = new Date(generatedAt.value)

  return Number.isNaN(at.getTime()) ? null : at.toLocaleString()
})
</script>

<template>
  <div class="h-full overflow-y-auto">
    <div class="mx-auto max-w-3xl px-8 py-12">
      <h1 class="font-mono text-lg font-semibold tracking-tight">The blueprint of your system</h1>
      <p class="mt-1 font-mono text-xs text-muted-foreground">
        What your data looks like, and how it is reached.
      </p>

      <!-- The graph loads on its own schedule whichever page is open, so this
           reads as pending rather than empty until it lands. -->
      <p v-if="status !== 'ready'" class="mt-8 font-mono text-xs text-muted-foreground">
        Reading schema…
      </p>

      <template v-else>
        <dl
          class="mt-8 grid grid-cols-2 gap-px overflow-hidden rounded-md border bg-border sm:grid-cols-5"
        >
          <div v-for="tile in tiles" :key="tile.label" class="bg-card px-3 py-3" :title="tile.hint">
            <dt class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
              {{ tile.label }}
            </dt>
            <dd class="mt-1 font-mono text-xl tabular-nums">{{ tile.value }}</dd>
          </div>
        </dl>

        <p v-if="generated" class="mt-2 font-mono text-[10px] text-muted-foreground/70">
          Exported {{ generated }}
        </p>
      </template>

      <div class="mt-8 grid gap-3 sm:grid-cols-2">
        <button
          class="group rounded-md border bg-card px-4 py-4 text-left transition-shadow hover:shadow-md"
          @click="nav.go('models')"
        >
          <span class="flex items-center gap-2 font-mono text-sm font-semibold">
            <Boxes class="size-4" />
            Models
            <ArrowRight class="size-3.5 opacity-0 transition-opacity group-hover:opacity-60" />
          </span>
          <span class="mt-1 block font-mono text-[11px] text-muted-foreground">
            The graph — every model, its columns, and what it is related to.
          </span>
        </button>

        <button
          class="group rounded-md border bg-card px-4 py-4 text-left transition-shadow hover:shadow-md"
          @click="nav.go('routes')"
        >
          <span class="flex items-center gap-2 font-mono text-sm font-semibold">
            <Route class="size-4" />
            Routes
            <ArrowRight class="size-3.5 opacity-0 transition-opacity group-hover:opacity-60" />
          </span>
          <!-- A count here would mean fetching routes.json on every visit to
               the overview, which is the one cost this page must not add. Once
               somebody has opened the surface it is free to say. -->
          <span class="mt-1 block font-mono text-[11px] text-muted-foreground">
            <template v-if="routesLoaded">
              {{ routeStats.total }} endpoints — what each one accepts and returns.
            </template>
            <template v-else>
              Every endpoint, what it accepts and returns, and the models behind it.
            </template>
          </span>
        </button>

        <button
          class="group rounded-md border bg-card px-4 py-4 text-left transition-shadow hover:shadow-md"
          @click="nav.go('jobs')"
        >
          <span class="flex items-center gap-2 font-mono text-sm font-semibold">
            <Timer class="size-4" />
            Jobs
            <ArrowRight class="size-3.5 opacity-0 transition-opacity group-hover:opacity-60" />
          </span>
          <!-- Same bargain as the routes card: a count here would mean parsing
               every file under the watched paths on every visit to the overview,
               which is the one cost this page must not add. -->
          <span class="mt-1 block font-mono text-[11px] text-muted-foreground">
            <template v-if="jobsLoaded">
              {{ jobStats.total }} queueables — where each runs and what puts it there.
            </template>
            <template v-else>
              Everything that reaches a worker, which queue it lands on, and what dispatches it.
            </template>
          </span>
        </button>
      </div>

      <p class="mt-8 font-mono text-[10px] text-muted-foreground/60">
        More surfaces to come — OpenAPI, contract compliance.
      </p>
    </div>
  </div>
</template>
