<script setup lang="ts">
import GraphCanvas from '@/components/GraphCanvas.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Moon, RotateCcw, Sun } from '@lucide/vue'
import { useDark, useToggle } from '@vueuse/core'
import { storeToRefs } from 'pinia'
import { computed, ref } from 'vue'
import { useSchemaStore } from '@/stores/schema'
import { useLayoutStore } from '@/stores/layout'

const isDark = useDark()
const toggleDark = useToggle(isDark)

const schema = useSchemaStore()
const layout = useLayoutStore()
const { stats, status } = storeToRefs(schema)
const { positions, saving, saveError } = storeToRefs(layout)

const pinnedCount = computed(() => Object.keys(positions.value).length)

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

      <span v-if="status === 'ready'" class="font-mono text-xs text-muted-foreground">
        {{ stats.models }} models · {{ stats.relations }} relations
        <template v-if="stats.external"> · {{ stats.external }} external </template>
      </span>
      <span v-else class="font-mono text-xs text-muted-foreground">Model relationships</span>

      <!-- Only offer the reset once something has actually been placed. -->
      <div class="ml-auto flex items-center gap-2">
        <span
          v-if="saveError"
          class="font-mono text-[10px] text-destructive"
          :title="`Layout not saved: ${saveError}`"
        >
          not saved
        </span>

        <Button
          v-if="pinnedCount"
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
      <GraphCanvas />
    </main>
  </div>
</template>
