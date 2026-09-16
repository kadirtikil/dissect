<script lang="ts" setup>
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useProviderStore } from '@/stores/provider'

const store = useProviderStore()
const { visible, selectedId, loaded } = storeToRefs(store)

onMounted(() => store.load())
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto border-r p-1">
    <ul>
      <li v-for="provider in visible" :key="provider.id">
        <button
          class="flex w-full cursor-pointer items-center gap-2 px-3 py-1 text-left hover:bg-accent/40"
          :class="selectedId === provider.id ? 'bg-accent/60' : ''"
          :aria-current="selectedId === provider.id ? 'true' : undefined"
          :title="provider.class"
          @click="store.select(provider.id)"
        >
          <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ provider.name }}</span>

          <!-- Worth spotting without opening the tree: part of it could not
               be read, so what is drawn is not the whole story. -->
          <span
            v-if="provider.partial"
            class="shrink-0 font-mono text-[10px] text-muted-foreground/70"
            title="Partly read — some bindings could not be named statically"
          >
            ?
          </span>

          <span
            v-if="provider.deferred"
            class="shrink-0 font-mono text-[10px] text-muted-foreground"
            title="Deferred — loaded only when something it provides is resolved"
          >
            ⏱
          </span>
        </button>
      </li>
    </ul>

    <p v-if="loaded && !visible.length" class="px-3 py-4 font-mono text-[11px] text-muted-foreground">
      No provider matches the current filter.
    </p>
  </div>
</template>
