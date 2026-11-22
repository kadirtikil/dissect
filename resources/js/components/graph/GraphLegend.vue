<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { RELATION_FAMILIES } from '@/lib/relations'
import { useSchemaStore } from '@/stores/schema'

const { crossesDomains } = storeToRefs(useSchemaStore())
</script>

<template>
  <div
    class="pointer-events-none absolute top-3 left-3 z-10 flex flex-col gap-1.5 rounded-md border bg-card/90 px-3 py-2 backdrop-blur-sm"
  >
    <span class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
      Relations
    </span>
    <div v-for="family in RELATION_FAMILIES" :key="family.id" class="flex items-center gap-2">
      <svg width="18" height="6" aria-hidden="true" class="shrink-0">
        <line
          x1="0"
          y1="3"
          x2="18"
          y2="3"
          :stroke="family.color"
          stroke-width="2"
          :stroke-dasharray="family.dashed ? '4 3' : undefined"
        />
      </svg>
      <span class="font-mono text-[10px] text-foreground">{{ family.label }}</span>
    </div>

    <!-- Only in an app with a relation that leaves its domain: the line is
         thicker in its family's colour, and its label says where it goes. -->
    <div v-if="crossesDomains" class="flex items-center gap-2" data-testid="legend-crosses">
      <svg width="18" height="6" aria-hidden="true" class="shrink-0">
        <line x1="0" y1="3" x2="18" y2="3" stroke="var(--muted-foreground)" stroke-width="3" />
      </svg>
      <span class="font-mono text-[10px] text-foreground">Crosses into → Domain</span>
    </div>
  </div>
</template>
