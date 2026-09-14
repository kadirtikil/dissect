<script lang="ts" setup>

import { useProviderStore } from '@/stores/provider';
import { storeToRefs } from 'pinia';
import { onMounted } from 'vue';

const { providers } = storeToRefs(useProviderStore())
const {fetchProviders, setSelectedProvider} = useProviderStore()

onMounted(async () => {
  await fetchProviders()
})

</script>



<template>
  <div class="min-h-0 flex-1 overflow-y-auto">
      <ul>
        <li v-for="(provider, index) in providers" :key="index">
          <button
            class="
              flex w-full items-center gap-2 px-3 py-1 text-left
              hover:bg-accent/40 cursor-pointer"
            @click="setSelectedProvider(provider)"
          >
            <span class="min-w-0 flex-1 truncate font-mono text-xs">
              {{ provider }}
            </span>
          </button>
        </li>
      </ul>
  </div>
</template>