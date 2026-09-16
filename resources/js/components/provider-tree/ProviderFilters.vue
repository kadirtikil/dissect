<script lang="ts" setup>
import { ref } from 'vue';
import { X } from '@lucide/vue';
import { Input } from '../ui/input';


const searchWord = ref('');

</script>



<template>
<div class="flex flex-row p-2 border-r">
  <Input
    v-model="searchWord"
    class="min-w-0 flex-1 rounded-sm border bg-background px-2 py-1 font-mono text-xs outline-none focus:border-ring"
    placeholder="Filter by name"
    aria-label="Filter endpoints"
  />

  <button
    v-if="searchWord"
    class="shrink-0 rounded-sm p-1 text-muted-foreground hover:text-foreground cursor-pointer"
    aria-label="Clear all filters"
  >
    <X class="size-3.5" />
  </button>
</div>
</template>
