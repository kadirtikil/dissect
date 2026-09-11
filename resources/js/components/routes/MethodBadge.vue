<script setup lang="ts">
import { computed } from 'vue'
import { methodColor } from '@/lib/httpMethods'

const props = defineProps<{ methods: string[] }>()

const colour = computed(() => methodColor(props.methods))

/** `PUT|PATCH` — one route, two verbs, and both belong on the badge. */
const label = computed(() => props.methods.join('|') || '—')
</script>

<template>
  <!-- The verb is written out, so the colour is redundant encoding: it speeds
       up scanning a long list without ever being the only thing carrying the
       meaning. -->
  <span
    class="shrink-0 rounded-sm border px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wide tabular-nums"
    :style="{
      color: colour,
      borderColor: `color-mix(in oklch, ${colour} 40%, transparent)`,
      backgroundColor: `color-mix(in oklch, ${colour} 8%, transparent)`,
    }"
  >
    {{ label }}
  </span>
</template>
