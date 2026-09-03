<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import JobFilters from '@/components/jobs/JobFilters.vue'
import JobList from '@/components/jobs/JobList.vue'
import JobDetail from '@/components/jobs/JobDetail.vue'
import { PAGE_CONTEXT } from '@/lib/toolbar'
import { useJobsStore } from '@/stores/jobs'
import { useSchemaStore } from '@/stores/schema'

const store = useJobsStore()
const schema = useSchemaStore()
const { status, error, highlightedModels, stats } = storeToRefs(store)

/**
 * Keeps the canvas in step with what is selected here.
 *
 * Done from the component rather than inside the store, for the reason the
 * routes panel gives: the schema store already reaches for these to poll their
 * change signals, and having them import each other would put a cycle between
 * module-level `defineStore` calls.
 *
 * Only one panel is ever mounted, so the two surfaces never fight over the
 * highlight — arriving here replaces whatever the endpoint list had ringed.
 */
watch(highlightedModels, (models) => schema.highlight(models), { immediate: true })

// Fetched here rather than at boot: this is the moment somebody asked for it,
// and load() is a no-op once the list is in hand.
onMounted(() => store.load())
</script>

<template>
  <div class="flex h-full w-full">
    <!-- No guard needed: this panel only exists while its own page is open. -->
    <Teleport defer :to="PAGE_CONTEXT">
      <span class="font-mono text-xs text-muted-foreground">
        <template v-if="status === 'ready'">
          {{ stats.visible }} of {{ stats.total }} jobs
          <!-- The finding nothing else reports, said where it will be seen
               without opening the surface's filters. -->
          <template v-if="stats.undispatched"> · {{ stats.undispatched }} undispatched </template>
        </template>
        <template v-else>Queue surface</template>
      </span>
    </Teleport>

    <div
      v-if="status === 'loading'"
      class="grid flex-1 place-items-center font-mono text-xs text-muted-foreground"
    >
      Reading jobs…
    </div>

    <div v-else-if="status === 'error'" class="grid flex-1 place-items-center px-6">
      <div class="max-w-md rounded-md border border-destructive/40 bg-card px-4 py-3">
        <p class="font-mono text-xs font-semibold text-destructive">Could not load jobs.json</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>
    </div>

    <template v-else>
      <!-- Fixed-width list, flexible detail: the same split the endpoint list
           uses, and for the same reason — a column of names beside the thing
           that benefits from the room. -->
      <div class="flex w-[380px] shrink-0 flex-col border-r">
        <JobFilters />
        <JobList />
      </div>

      <div class="flex min-w-0 flex-1 flex-col">
        <JobDetail />
      </div>
    </template>
  </div>
</template>
