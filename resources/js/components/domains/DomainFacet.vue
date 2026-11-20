<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { UNASSIGNED, domainColor, domainPalette } from '@/lib/domains'
import { useDomainsStore } from '@/stores/domains'

/**
 * One chip per domain the list has rows in, plus one for the rows in none.
 *
 * Shared by every list so a domain reads the same everywhere: the same colour,
 * the same place, the same counts. Nothing is drawn for an app with no
 * domains, which is most of them.
 */
const props = defineProps<{
  /** Rows per domain key, with the facet's own selection left out. */
  counts: Record<string, number>
  selected: readonly string[]
}>()

const emit = defineEmits<{ toggle: [key: string] }>()

const { palette: known } = storeToRefs(useDomainsStore())

const palette = computed(() => domainPalette(known.value, Object.keys(props.counts)))

/** Domains in colour order, so the chips line up with the domain list. */
const options = computed(() => palette.value.filter((name) => name in props.counts))

/**
 * Offered only beside at least one domain: on its own it would be every row,
 * a chip that cannot narrow anything.
 */
const unassigned = computed(() => options.value.length > 0 && UNASSIGNED in props.counts)

function chipStyle(name: string) {
  if (!props.selected.includes(name)) return {}

  const color = domainColor(name, palette.value)

  return {
    color,
    borderColor: `color-mix(in oklch, ${color} 45%, transparent)`,
    backgroundColor: `color-mix(in oklch, ${color} 10%, transparent)`,
  }
}
</script>

<template>
  <div
    v-if="options.length"
    class="mt-2 flex flex-wrap items-center gap-1"
    role="group"
    aria-label="Filter by domain"
    data-testid="domain-facet"
  >
    <button
      v-for="name in options"
      :key="name"
      class="flex items-center gap-1 rounded-sm border px-1.5 py-0.5 font-mono text-[10px]"
      :class="selected.includes(name) ? '' : 'border-transparent text-muted-foreground'"
      :style="chipStyle(name)"
      :aria-pressed="selected.includes(name)"
      @click="emit('toggle', name)"
    >
      <span
        class="size-1.5 rounded-full"
        :style="{ background: domainColor(name, palette) }"
        aria-hidden="true"
      />
      {{ name }}
      <span class="opacity-60 tabular-nums">{{ counts[name] }}</span>
    </button>

    <button
      v-if="unassigned"
      class="rounded-sm border px-1.5 py-0.5 font-mono text-[10px] italic"
      :class="
        selected.includes(UNASSIGNED)
          ? 'border-ring/40 bg-accent/10 text-foreground'
          : 'border-transparent text-muted-foreground'
      "
      :aria-pressed="selected.includes(UNASSIGNED)"
      title="In no domain: no folder, domains.json entry or attribute places it"
      @click="emit('toggle', UNASSIGNED)"
    >
      unassigned
      <span class="ml-1 not-italic opacity-60 tabular-nums">{{ counts[UNASSIGNED] }}</span>
    </button>
  </div>
</template>
