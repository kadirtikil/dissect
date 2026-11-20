<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { X } from '@lucide/vue'
import DomainFacet from '@/components/domains/DomainFacet.vue'
import { useProviderStore } from '@/stores/provider'

const store = useProviderStore()
const { search, contexts, facets, stats } = storeToRefs(store)
</script>

<template>
  <div class="shrink-0 border-r border-b px-3 py-2">
    <div class="flex items-center gap-2">
      <input
        v-model="search"
        class="min-w-0 flex-1 rounded-sm border bg-background px-2 py-1 font-mono text-xs outline-none focus:border-ring"
        placeholder="Filter by provider or class…"
        aria-label="Filter providers"
      />

      <span class="shrink-0 font-mono text-[10px] text-muted-foreground tabular-nums">
        {{ stats.visible }} / {{ stats.total }}
      </span>

      <button
        v-if="search || contexts.length"
        class="shrink-0 cursor-pointer rounded-sm p-1 text-muted-foreground hover:text-foreground"
        aria-label="Clear all filters"
        @click="store.clearFilters()"
      >
        <X class="size-3.5" />
      </button>
    </div>

    <DomainFacet :counts="facets.contexts" :selected="contexts" @toggle="store.toggleContext" />
  </div>
</template>
