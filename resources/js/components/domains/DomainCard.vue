<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronRight } from '@lucide/vue'
import type { Domain } from '@/types/domains'
import { baseName } from '@/lib/classNames'
import { domainColor, namespaceOf, sourceLabel } from '@/lib/domains'

const props = defineProps<{
  domain: Domain
  /** Every domain name, so colours stay put however the list is filtered. */
  names: readonly string[]
}>()

const open = ref(false)

const color = computed(() => domainColor(props.domain.name, props.names))

/**
 * The classes by namespace, so a domain reads as its folders do: models
 * together, jobs together.
 */
const groups = computed(() => {
  const byNamespace = new Map<string, string[]>()

  for (const name of props.domain.classes) {
    const ns = namespaceOf(name)
    byNamespace.set(ns, [...(byNamespace.get(ns) ?? []), name])
  }

  return [...byNamespace].map(([namespace, classes]) => ({ namespace, classes }))
})
</script>

<template>
  <section class="rounded-md border bg-card" :data-domain="domain.name">
    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
      <span class="flex items-center gap-2 font-mono text-sm font-semibold">
        <span class="size-2.5 rounded-full" :style="{ background: color }" aria-hidden="true" />
        {{ domain.name }}
      </span>

      <span
        v-for="source in domain.sources"
        :key="source"
        class="rounded-full border px-2 py-px font-mono text-[10px] text-muted-foreground"
      >
        {{ sourceLabel(source) }}
      </span>

      <span class="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
        {{ domain.classes.length }} {{ domain.classes.length === 1 ? 'class' : 'classes' }}
      </span>
    </header>

    <ul
      v-if="domain.roots.length"
      class="border-t px-4 py-2 font-mono text-[11px] text-muted-foreground"
    >
      <li v-for="root in domain.roots" :key="root" class="truncate" :title="root">{{ root }}</li>
    </ul>

    <div v-if="domain.classes.length" class="border-t">
      <button
        class="flex w-full items-center gap-1.5 px-4 py-2 text-left font-mono text-[11px] text-muted-foreground hover:text-foreground"
        :aria-expanded="open"
        @click="open = !open"
      >
        <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': open }" />
        {{ open ? 'Hide classes' : 'Show classes' }}
      </button>

      <div v-if="open" class="space-y-2 px-4 pb-3">
        <div v-for="group in groups" :key="group.namespace">
          <p
            class="truncate font-mono text-[10px] text-muted-foreground/70"
            :title="group.namespace"
          >
            {{ group.namespace || '(global namespace)' }}
          </p>
          <ul class="font-mono text-xs">
            <li v-for="name in group.classes" :key="name" :title="name">{{ baseName(name) }}</li>
          </ul>
        </div>
      </div>
    </div>
  </section>
</template>
