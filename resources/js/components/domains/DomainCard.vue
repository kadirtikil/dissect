<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronRight } from '@lucide/vue'
import type { Domain } from '@/types/domains'
import type { DomainDependencies } from '@/lib/dependencies'
import type { BoundaryRule } from '@/types/boundaries'
import { describeRule, ruleFor } from '@/lib/boundaries'
import DomainLinks from '@/components/domains/DomainLinks.vue'
import { baseName } from '@/lib/classNames'
import { domainColor, namespaceOf, sourceLabel } from '@/lib/domains'

const props = defineProps<{
  domain: Domain
  /** Every domain name, so colours stay put however the list is filtered. */
  names: readonly string[]
  /** What it depends on and what depends on it; null until the class index is in. */
  dependencies?: DomainDependencies | null
  /** Every domain's boundary rule, so a link into another domain can be judged by either end. */
  rules?: readonly BoundaryRule[]
  /** How many references break this domain's rule. */
  violations?: number
}>()

const rule = computed(() => ruleFor(props.rules ?? [], props.domain.name))

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

      <span
        v-if="violations"
        class="ml-auto font-mono text-xs text-destructive tabular-nums"
        data-testid="domain-violations"
      >
        {{ violations }} {{ violations === 1 ? 'violation' : 'violations' }}
      </span>

      <span
        class="font-mono text-xs text-muted-foreground tabular-nums"
        :class="{ 'ml-auto': !violations }"
      >
        {{ domain.classes.length }} {{ domain.classes.length === 1 ? 'class' : 'classes' }}
      </span>
    </header>

    <ul
      v-if="domain.roots.length"
      class="border-t px-4 py-2 font-mono text-[11px] text-muted-foreground"
    >
      <li v-for="root in domain.roots" :key="root" class="truncate" :title="root">{{ root }}</li>
    </ul>

    <p v-if="rule" class="border-t px-4 py-2 font-mono text-[11px]" data-testid="domain-rule">
      <span class="text-muted-foreground">Rule</span>
      {{ describeRule(rule) }}
    </p>

    <DomainLinks
      v-if="dependencies"
      :domain="domain.name"
      :dependencies="dependencies"
      :names="names"
      :rules="rules ?? []"
    />

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
