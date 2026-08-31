<script setup lang="ts">
import { ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { ChevronDown, ChevronRight } from '@lucide/vue'
import JobKindBadge from '@/components/JobKindBadge.vue'
import { useJobsStore } from '@/stores/jobs'
import { isUndispatched } from '@/lib/jobFilters'

const store = useJobsStore()
const { grouped, selectedId, search } = storeToRefs(store)

/**
 * Which queues are folded shut. Collapsed rather than expanded is stored, so a
 * queue that appears after a re-export opens by default — a new job hiding
 * itself is the opposite of what somebody watching for changes wants.
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
          {{ group.jobs.length }}
        </span>
      </button>

      <ul v-if="!collapsed.has(group.key)">
        <li v-for="job in group.jobs" :key="job.id">
          <button
            class="flex w-full items-center gap-2 px-3 py-1 text-left hover:bg-accent/40"
            :class="selectedId === job.id ? 'bg-accent/60' : ''"
            :aria-current="selectedId === job.id ? 'true' : undefined"
            @click="store.select(job.id)"
          >
            <JobKindBadge :kind="job.kind" />

            <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ job.name }}</span>

            <!-- The one thing worth spotting from the list without opening a
                 row: nothing was found that puts this on a queue. -->
            <span
              v-if="isUndispatched(job)"
              class="shrink-0 font-mono text-[10px] text-muted-foreground/70"
              title="No dispatch site found — dead code, or dispatched dynamically"
            >
              ⌀
            </span>

            <span
              v-if="job.models.length"
              class="shrink-0 font-mono text-[10px] text-muted-foreground tabular-nums"
              :title="job.models.join(', ')"
            >
              {{ job.models.length }} ⬡
            </span>
          </button>
        </li>
      </ul>
    </div>

    <p v-if="!grouped.length" class="px-3 py-4 font-mono text-[11px] text-muted-foreground">
      No job matches the current filters.
    </p>
  </div>
</template>
