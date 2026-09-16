<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import ProviderFilters from '@/components/provider-tree/ProviderFilters.vue'
import ProviderList from '@/components/provider-tree/ProviderList.vue'
import ProviderTree from '@/components/provider-tree/ProviderTree.vue'
import { PAGE_CONTEXT } from '@/lib/toolbar'
import { useProviderStore } from '@/stores/provider'

const store = useProviderStore()
const { status, error, providers, selected, stats } = storeToRefs(store)

const unresolvedCount = computed(
  () => selected.value?.nodes.filter((node) => node.kind === 'unresolved').length ?? 0,
)

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
          {{ stats.visible }} of {{ stats.total }} providers
          <template v-if="selected">
            · {{ selected.nodes.length }} nodes · {{ selected.edges.length }} edges
          </template>
        </template>
        <template v-else>Service providers</template>
      </span>
    </Teleport>

    <div
      v-if="status === 'loading'"
      class="grid flex-1 place-items-center font-mono text-xs text-muted-foreground"
    >
      Reading providers…
    </div>

    <div v-else-if="status === 'error'" class="grid flex-1 place-items-center px-6">
      <div class="max-w-md rounded-md border border-destructive/40 bg-card px-4 py-3">
        <p class="font-mono text-xs font-semibold text-destructive">Could not load providers.json</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>
    </div>

    <div
      v-else-if="status === 'ready' && !providers.length"
      class="grid flex-1 place-items-center px-6"
    >
      <div class="max-w-md rounded-md border bg-card px-4 py-3 text-center">
        <p class="font-mono text-xs font-semibold">No service providers found</p>
        <p class="mt-1 font-mono text-[11px] text-muted-foreground">
          Nothing under the configured <code>dissect.providers.paths</code> extends ServiceProvider.
        </p>
      </div>
    </div>

    <template v-else>
      <!-- Fixed-width list, flexible canvas: the split the job list uses. -->
      <div class="flex w-[320px] shrink-0 flex-col">
        <ProviderFilters />
        <ProviderList />
      </div>

      <div class="relative flex min-w-0 flex-1 flex-col">
        <div
          v-if="!selected"
          class="grid flex-1 place-items-center font-mono text-xs text-muted-foreground"
        >
          Select a provider to see what it binds and what that pulls in.
        </div>

        <template v-else>
          <!-- Said above the canvas, not only on the grey boxes inside it: a tree
               that is missing branches looks complete unless something says so. -->
          <div
            v-if="selected.partial || selected.truncated"
            class="flex shrink-0 flex-col gap-0.5 border-b bg-muted/40 px-4 py-1.5 font-mono text-[11px] text-muted-foreground"
            role="status"
          >
            <span v-if="selected.partial">
              Not fully analysed — {{ unresolvedCount }}
              {{ unresolvedCount === 1 ? 'dependency' : 'dependencies' }} could not be read statically
              (a loop, a helper method or a dynamic class name). Dotted boxes mark where.
            </span>
            <span v-if="selected.truncated">
              Cut short by the depth or node cap — raise
              <code>dissect.providers.max_depth</code> or <code>max_nodes</code> to see further.
            </span>
          </div>

          <div class="min-h-0 flex-1">
            <ProviderTree />
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
