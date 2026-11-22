<script setup lang="ts">
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { FileDown } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import RouteFilters from '@/components/routes/RouteFilters.vue'
import RouteList from '@/components/routes/RouteList.vue'
import RouteDetail from '@/components/routes/RouteDetail.vue'
import { PAGE_ACTIONS, PAGE_CONTEXT } from '@/lib/toolbar'
import { bootstrap } from '@/lib/bootstrap'
import { useRoutesStore } from '@/stores/routes'

const store = useRoutesStore()
const { status, error, stats } = storeToRefs(store)

// Fetched here rather than at boot: this is the moment somebody asked for it,
// and load() is a no-op once the list is in hand, so switching surfaces back
// and forth costs nothing.
onMounted(() => store.load())

// Only the package can build the document; the standalone dev server has none.
const openapiUrl = bootstrap().openapiUrl
</script>

<template>
  <div class="flex h-full w-full">
    <!-- No guard needed: this panel only exists while its own page is open. -->
    <Teleport defer :to="PAGE_CONTEXT">
      <span class="font-mono text-xs text-muted-foreground">
        <template v-if="status === 'ready'">
          {{ stats.visible }} of {{ stats.total }} endpoints
        </template>
        <template v-else>HTTP surface</template>
      </span>
    </Teleport>

    <Teleport defer :to="PAGE_ACTIONS">
      <!-- The same file `dissect:export routes --format=openapi` writes, for
           somebody who would rather click than open a terminal. -->
      <Button
        v-if="openapiUrl"
        as="a"
        :href="openapiUrl"
        download="openapi.json"
        variant="ghost"
        size="sm"
        class="font-mono text-xs"
        title="The API routes as an OpenAPI 3.1 document"
        data-testid="openapi-download"
      >
        <FileDown class="size-3.5" />
        OpenAPI
      </Button>
    </Teleport>

    <div
      v-if="status === 'loading'"
      class="grid flex-1 place-items-center font-mono text-xs text-muted-foreground"
    >
      Reading routes…
    </div>

    <div v-else-if="status === 'error'" class="grid flex-1 place-items-center px-6">
      <div class="max-w-md rounded-md border border-destructive/40 bg-card px-4 py-3">
        <p class="font-mono text-xs font-semibold text-destructive">Could not load routes.json</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>
    </div>

    <template v-else>
      <!-- Fixed-width list, flexible detail: the list is a column of paths, and
           the payload shapes beside it are what benefit from the room. -->
      <div class="flex w-[380px] shrink-0 flex-col border-r">
        <RouteFilters />
        <RouteList />
      </div>

      <div class="flex min-w-0 flex-1 flex-col">
        <RouteDetail />
      </div>
    </template>
  </div>
</template>
