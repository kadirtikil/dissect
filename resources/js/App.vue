<script setup lang="ts">
import GraphCanvas from '@/components/GraphCanvas.vue'
import RoutesPanel from '@/components/RoutesPanel.vue'
import ViewMenu from '@/components/ViewMenu.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { ChevronsDownUp, Moon, RotateCcw, Sun } from '@lucide/vue'
import { useDark, useToggle } from '@vueuse/core'
import { storeToRefs } from 'pinia'
import { computed, ref, watch } from 'vue'
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'
import { useViewsStore } from '@/stores/views'
import { useRoutesStore } from '@/stores/routes'
import { useUiStore } from '@/stores/ui'

const isDark = useDark()
const toggleDark = useToggle(isDark)

const schema = useSchemaStore()
const layout = useLayoutStore()
const views = useViewsStore()
const routes = useRoutesStore()
const ui = useUiStore()
const { stats, status, lastUpdated, expanded } = storeToRefs(schema)
const { positions, saving, saveError } = storeToRefs(layout)
const { active: activeView } = storeToRefs(views)
const { stats: routeStats, status: routeStatus } = storeToRefs(routes)
const { mode } = storeToRefs(ui)

const pinnedCount = computed(() => Object.keys(positions.value).length)

// The graph re-exports itself when models or migrations change. That can be a
// single new column somewhere off-screen, so say so briefly — otherwise a live
// update is indistinguishable from nothing having happened.
const justUpdated = ref(false)
let updateTimer: ReturnType<typeof setTimeout> | undefined

watch(lastUpdated, (at) => {
  if (!at) return
  justUpdated.value = true
  clearTimeout(updateTimer)
  updateTimer = setTimeout(() => (justUpdated.value = false), 2500)
})

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
  schema.relayout()
}
</script>

<template>
  <div class="flex h-screen flex-col bg-background text-foreground">
    <header class="flex h-14 shrink-0 items-center gap-3 border-b px-4">
      <span class="font-mono text-sm font-semibold tracking-tight">dissect</span>
      <Badge class="bg-accent text-accent-foreground font-mono text-[10px] hover:bg-accent">
        beta
      </Badge>

      <Separator orientation="vertical" class="mx-1 h-full" />

      <!-- Two surfaces, no router: the package mounts at an arbitrary prefix,
           so a path-matching router would find no route at all. -->
      <div class="flex items-center gap-0.5 rounded-md bg-muted p-0.5" role="tablist">
        <button
          v-for="tab in (['models', 'routes'] as const)"
          :key="tab"
          class="rounded-sm px-2 py-1 font-mono text-xs capitalize"
          :class="mode === tab ? 'bg-background shadow-sm' : 'text-muted-foreground'"
          role="tab"
          :aria-selected="mode === tab"
          @click="ui.setMode(tab)"
        >
          {{ tab }}
        </button>
      </div>

      <template v-if="mode === 'models'">
        <span v-if="status === 'ready'" class="font-mono text-xs text-muted-foreground">
          <!-- While a view is open the totals describe the schema, not what is on
               screen, so the visible count is what leads. -->
          <template v-if="activeView">
            {{ stats.visible }} of {{ stats.models + stats.external }} models
          </template>
          <template v-else>
            {{ stats.models }} models · {{ stats.relations }} relations
            <template v-if="stats.external"> · {{ stats.external }} external </template>
          </template>
        </span>
        <span v-else class="font-mono text-xs text-muted-foreground">Model relationships</span>

        <ViewMenu v-if="status === 'ready'" />
      </template>

      <span v-else class="font-mono text-xs text-muted-foreground">
        <template v-if="routeStatus === 'ready'">
          {{ routeStats.visible }} of {{ routeStats.total }} endpoints
        </template>
        <template v-else>HTTP surface</template>
      </span>

      <!-- Only offer the reset once something has actually been placed. -->
      <div class="ml-auto flex items-center gap-2">
        <span
          v-if="justUpdated"
          class="font-mono text-[10px] text-accent-foreground"
          role="status"
          title="Models or migrations changed — the graph was re-exported"
        >
          updated
        </span>

        <span
          v-if="saveError"
          class="font-mono text-[10px] text-destructive"
          :title="`Layout not saved: ${saveError}`"
        >
          not saved
        </span>

        <!-- Expanded cards float over their neighbours, so with several open the
             board is hard to read — this is the way back out without hunting
             for each one. -->
        <Button
          v-if="mode === 'models' && expanded.size"
          variant="ghost"
          size="sm"
          class="font-mono text-xs"
          :aria-label="`Collapse ${expanded.size} expanded models`"
          @click="schema.collapseAll()"
        >
          <ChevronsDownUp class="size-3.5" />
          Collapse · {{ expanded.size }}
        </Button>

        <Button
          v-if="mode === 'models' && pinnedCount"
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
      </div>

      <Button variant="ghost" size="icon" aria-label="Toggle theme" @click="toggleDark()">
        <Moon v-if="isDark" class="size-4" />
        <Sun v-else class="size-4" />
      </Button>
    </header>

    <main class="min-h-0 flex-1">
      <!-- The canvas stays mounted while the routes surface is open: it owns
           the schema load and the change poller, and remounting it would drop
           the viewport somebody arranged as well as restarting both. -->
      <GraphCanvas v-show="mode === 'models'" />

      <!-- Mounted on first use, though — that is what makes routes.json a
           fetch somebody asked for rather than a cost every page load pays. -->
      <RoutesPanel v-if="mode === 'routes'" />
    </main>
  </div>
</template>
