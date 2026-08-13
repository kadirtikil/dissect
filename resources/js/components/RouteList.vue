<script setup lang="ts">
import { ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { ChevronDown, ChevronRight } from '@lucide/vue'
import MethodBadge from '@/components/MethodBadge.vue'
import { useRoutesStore } from '@/stores/routes'

const store = useRoutesStore()
const { grouped, selectedId, search } = storeToRefs(store)

/**
 * Which controllers are folded shut. Collapsed rather than expanded is stored,
 * so a group that appears after a re-export opens by default — a new endpoint
 * hiding itself is the opposite of what somebody watching for changes wants.
 */
const collapsed = ref<Set<string>>(new Set())

function toggle(key: string) {
  if (collapsed.value.has(key)) collapsed.value.delete(key)
  else collapsed.value.add(key)
  collapsed.value = new Set(collapsed.value)
}

// Typing a filter is asking to see what matched, so folds get out of the way.
watch(search, () => collapsed.value.clear())
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto">
    <div v-for="group in grouped" :key="group.key">
      <button
        class="sticky top-0 z-10 flex w-full items-center gap-1.5 border-b bg-background/95 px-3 py-1.5 text-left font-mono text-[11px] backdrop-blur"
        :aria-expanded="!collapsed.has(group.key)"
        @click="toggle(group.key)"
      >
        <ChevronDown v-if="!collapsed.has(group.key)" class="size-3 shrink-0" />
        <ChevronRight v-else class="size-3 shrink-0" />
        <span class="truncate font-semibold">{{ group.label }}</span>
        <span class="ml-auto shrink-0 text-[10px] text-muted-foreground tabular-nums">
          {{ group.routes.length }}
        </span>
      </button>

      <ul v-if="!collapsed.has(group.key)">
        <li v-for="route in group.routes" :key="route.id">
          <button
            class="flex w-full items-center gap-2 px-3 py-1 text-left hover:bg-accent/40"
            :class="selectedId === route.id ? 'bg-accent/60' : ''"
            :aria-current="selectedId === route.id ? 'true' : undefined"
            @click="store.select(route.id)"
          >
            <MethodBadge :methods="route.methods" />

            <span class="min-w-0 flex-1 truncate font-mono text-xs">
              {{ route.uri }}
            </span>

            <!-- Endpoints reaching the graph are the ones worth spotting from
                 the list, so the count sits on the row rather than only in the
                 detail pane. -->
            <span
              v-if="route.models.length"
              class="shrink-0 font-mono text-[10px] text-muted-foreground tabular-nums"
              :title="route.models.join(', ')"
            >
              {{ route.models.length }} ⬡
            </span>
          </button>
        </li>
      </ul>
    </div>

    <p v-if="!grouped.length" class="px-3 py-4 font-mono text-[11px] text-muted-foreground">
      No endpoint matches the current filters.
    </p>
  </div>
</template>
