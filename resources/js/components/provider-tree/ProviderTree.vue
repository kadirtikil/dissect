<script lang="ts" setup>
import { useProviderStore } from '@/stores/provider';
import { storeToRefs } from 'pinia';

const { selected } = storeToRefs(useProviderStore())

</script>



<template>
{{ selected != '' ? selected : 'No provider selected' }}
</template>
