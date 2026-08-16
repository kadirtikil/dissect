<script setup lang="ts">
import AppSidebar from '@/components/AppSidebar.vue'
import GraphCanvas from '@/components/GraphCanvas.vue'
import { storeToRefs } from 'pinia'
import { computed, defineAsyncComponent, markRaw, ref, watch } from 'vue'
import { pages } from '@/pages/registry'
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'
import { useNavigationStore } from '@/stores/navigation'

const schema = useSchemaStore()
const layout = useLayoutStore()
const nav = useNavigationStore()
const { lastUpdated } = storeToRefs(schema)
const { saveError } = storeToRefs(layout)
const { current: page } = storeToRefs(nav)

/**
 * Every page the registry mounts, resolved once.
 *
 * markRaw: these are component definitions, and Vue has no business making one
 * reactive — doing so would also re-register the async wrapper on every render.
 */
const lazyPages = new Map(
  pages
    .filter((entry) => !entry.persistent && entry.component)
    .map((entry) => [entry.id, markRaw(defineAsyncComponent(entry.component!))]),
)

const CurrentPage = computed(() => lazyPages.get(page.value))

const pageLabel = computed(() => pages.find((entry) => entry.id === page.value)?.label ?? '')

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
  <div class="flex h-screen bg-background text-foreground">
    <AppSidebar />

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-14 shrink-0 items-center gap-3 border-b px-4">
        <span class="font-mono text-sm font-semibold tracking-tight">{{ pageLabel }}</span>

        <!-- Whatever the open page has to say about itself — see lib/toolbar. -->
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
      </header>

      <main class="min-h-0 flex-1">
        <!-- Held here rather than mounted by the registry: it owns the schema
             load and the change poller the routes surface also depends on, and
             remounting it would drop the viewport somebody arranged. The one
             page the shell knows by name, and the comment in registry.ts says
             why nobody should tidy that away. -->
        <GraphCanvas v-show="page === 'models'" />

        <!-- Everything else mounts on arrival and unmounts on leaving, which is
             what keeps routes.json a fetch somebody asked for. -->
        <component :is="CurrentPage" v-if="CurrentPage" />
      </main>
    </div>
  </div>
</template>
