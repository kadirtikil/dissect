<script setup lang="ts">
import GraphCanvas from '@/components/GraphCanvas.vue'
import RoutesPanel from '@/components/RoutesPanel.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Moon, Sun } from '@lucide/vue'
import { useDark, useToggle } from '@vueuse/core'
import { storeToRefs } from 'pinia'
import { ref, watch } from 'vue'
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'
import { useNavigationStore } from '@/stores/navigation'

const isDark = useDark()
const toggleDark = useToggle(isDark)

const schema = useSchemaStore()
const layout = useLayoutStore()
const nav = useNavigationStore()
const { lastUpdated } = storeToRefs(schema)
const { saveError } = storeToRefs(layout)
const { current: page } = storeToRefs(nav)

// The graph re-exports itself when models or migrations change. That can be a
// single new column somewhere off-screen, so say so briefly — otherwise a live
// update is indistinguishable from nothing having happened.
//
// Not a graph-page control despite coming from the schema: the poller runs for
// the whole session, so the news can arrive while another surface is open.
const justUpdated = ref(false)
let updateTimer: ReturnType<typeof setTimeout> | undefined

watch(lastUpdated, (at) => {
  if (!at) return
  justUpdated.value = true
  clearTimeout(updateTimer)
  updateTimer = setTimeout(() => (justUpdated.value = false), 2500)
})
</script>

<template>
  <div class="flex h-screen flex-col bg-background text-foreground">
    <header class="flex h-14 shrink-0 items-center gap-3 border-b px-4">
      <span class="font-mono text-sm font-semibold tracking-tight">dissect</span>
      <Badge class="bg-accent text-accent-foreground font-mono text-[10px] hover:bg-accent">
        beta
      </Badge>

      <Separator orientation="vertical" class="mx-1 h-full" />

      <!-- Two surfaces, and still no router. The page is registered as exactly
           one GET route with no SPA fallback behind it, so a path-matching
           router would 404 on reload however the prefix were resolved — the
           navigation store addresses pages by fragment instead. -->
      <div class="flex items-center gap-0.5 rounded-md bg-muted p-0.5" role="tablist">
        <button
          v-for="tab in ['models', 'routes'] as const"
          :key="tab"
          class="rounded-sm px-2 py-1 font-mono text-xs capitalize"
          :class="page === tab ? 'bg-background shadow-sm' : 'text-muted-foreground'"
          role="tab"
          :aria-selected="page === tab"
          @click="nav.go(tab)"
        >
          {{ tab }}
        </button>
      </div>

      <!-- Whatever the open page has to say about itself — see lib/toolbar.
           `contents` rather than a box of its own: the page's controls become
           direct children of this header, spaced by its own gap, so a page
           contributing nothing costs no gap and a page contributing something
           sits exactly where a hand-written control would have. -->
      <div id="dissect-page-context" class="contents"></div>

      <div class="ml-auto flex items-center gap-2">
        <span
          v-if="justUpdated"
          class="font-mono text-[10px] text-accent-foreground"
          role="status"
          title="Models or migrations changed — the graph was re-exported"
        >
          updated
        </span>

        <!-- Global, not a graph control: a layout write that was refused is
             worth knowing about from whichever surface you are on. -->
        <span
          v-if="saveError"
          class="font-mono text-[10px] text-destructive"
          :title="`Layout not saved: ${saveError}`"
        >
          not saved
        </span>

        <div id="dissect-page-actions" class="contents"></div>
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
      <GraphCanvas v-show="page === 'models'" />

      <!-- Mounted on first use, though — that is what makes routes.json a
           fetch somebody asked for rather than a cost every page load pays. -->
      <RoutesPanel v-if="page === 'routes'" />
    </main>
  </div>
</template>
