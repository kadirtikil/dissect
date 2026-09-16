<script lang="ts" setup>
import { CardHeader, CardTitle } from '@/components/ui/card';
import Card from '@/components/ui/card/Card.vue';

const open = ref(false)

import type { NodeProps } from '@vue-flow/core';
import { ref } from 'vue';

const props = defineProps<{
  node: NodeProps,
}>()

</script>


<template>
<Card class="w-50 rounded-2xl bg-red-400 cursor-pointer hover:border-2 hover:border-blue-500" @click="open = !open">
  <CardHeader>
    <CardTitle>
      <h1>{{ props.node.data.label }}</h1>
    </CardTitle>
    <CardDescription v-if="open">
      A short fine description of this component, maybe just slap the docblock in here, such that it can be toggled on click.
    </CardDescription>
  </CardHeader>
</Card>
</template>
