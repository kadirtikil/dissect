<script lang="ts" setup>
import ProviderFilters from '@/components/provider-tree/ProviderFilters.vue';
import ProviderList from '@/components/provider-tree/ProviderList.vue';
import ProviderTree from '@/components/provider-tree/ProviderTree.vue';
</script>



<template>
<div class="flex grid-cols-2">
  <div class="w-1/5 flex flex-col">
    <ProviderFilters />
    <ProviderList />
  </div>
  <div class="w-full">
    <ProviderTree />
  </div>
</div>
</template>
