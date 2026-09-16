<script lang="ts" setup>
import { useProviderStore } from '@/stores/provider'
import { storeToRefs } from 'pinia'
import { onMounted } from 'vue'

const store = useProviderStore()
const { providers } = storeToRefs(store)

onMounted(() => store.load())
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto border-t border-r p-1">
    <ul>
      <li v-for="provider in providers" :key="provider.id">
        <button
          class="flex w-full cursor-pointer items-center gap-2 px-3 py-1 text-left hover:bg-accent/40"
          :title="provider.class"
          @click="store.select(provider.id)"
        >
          <span class="min-w-0 flex-1 truncate font-mono text-xs">
            {{ provider.name }}
          </span>
        </button>
      </li>
    </ul>
  </div>
</template>
