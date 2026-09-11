<script setup lang="ts">
import { computed } from 'vue'
import { kindSpec } from '@/lib/jobKinds'

const props = defineProps<{ kind: string }>()

const spec = computed(() => kindSpec(props.kind))
</script>

<template>
  <!-- The kind is written out, so the colour is redundant encoding: it speeds
       up scanning a long list without ever being the only thing carrying the
       meaning. -->
  <span
    class="shrink-0 rounded-sm border px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wide"
    :style="{
      color: spec.color,
      borderColor: `color-mix(in oklch, ${spec.color} 40%, transparent)`,
      backgroundColor: `color-mix(in oklch, ${spec.color} 8%, transparent)`,
    }"
  >
    {{ spec.label }}
  </span>
</template>
