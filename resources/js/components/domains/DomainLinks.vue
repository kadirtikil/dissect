<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowLeftRight } from '@lucide/vue'
import type { DomainDependencies, DomainLink } from '@/lib/dependencies'
import { isMutual } from '@/lib/dependencies'
import { baseName } from '@/lib/classNames'
import { domainColor } from '@/lib/domains'

const props = defineProps<{
  dependencies: DomainDependencies
  /** Every domain name, so a linked domain is drawn in its own colour. */
  names: readonly string[]
}>()

type Direction = 'depends-on' | 'used-by'

/** The one link whose references are open, if any. */
const open = ref<{ direction: Direction; domain: string } | null>(null)

const rows = computed(() => [
  { direction: 'depends-on' as const, label: 'Depends on', links: props.dependencies.dependsOn },
  { direction: 'used-by' as const, label: 'Used by', links: props.dependencies.usedBy },
])

const isolated = computed(
  () => !props.dependencies.dependsOn.length && !props.dependencies.usedBy.length,
)

const selected = computed<DomainLink | null>(() => {
  const at = open.value
  if (!at) return null

  const row = rows.value.find((r) => r.direction === at.direction)
  return row?.links.find((l) => l.domain === at.domain) ?? null
})

function isOpen(direction: Direction, domain: string): boolean {
  return open.value?.direction === direction && open.value.domain === domain
}

function toggle(direction: Direction, domain: string): void {
  open.value = isOpen(direction, domain) ? null : { direction, domain }
}

function lines(numbers: readonly number[]): string {
  return numbers.join(', ')
}
</script>

<template>
  <div class="border-t px-4 py-2 font-mono text-[11px]" data-testid="domain-links">
    <p v-if="isolated" class="text-muted-foreground">
      No class here names another domain's, and none of theirs names one here.
    </p>

    <template v-else>
      <div
        v-for="row in rows"
        :key="row.direction"
        class="flex flex-wrap items-center gap-1.5 py-0.5"
        :data-direction="row.direction"
      >
        <span class="w-20 shrink-0 text-muted-foreground">{{ row.label }}</span>

        <button
          v-for="link in row.links"
          :key="link.domain"
          class="flex items-center gap-1.5 rounded-full border px-2 py-px hover:bg-muted/60 aria-expanded:border-foreground/40 aria-expanded:bg-muted"
          :aria-expanded="isOpen(row.direction, link.domain)"
          :data-link="link.domain"
          @click="toggle(row.direction, link.domain)"
        >
          <span
            class="size-2 rounded-full"
            :style="{ background: domainColor(link.domain, names) }"
            aria-hidden="true"
          />
          {{ link.domain }}
          <span class="text-muted-foreground tabular-nums">{{ link.count }}</span>
          <ArrowLeftRight
            v-if="isMutual(dependencies, link.domain)"
            class="size-3 text-muted-foreground"
            aria-label="names this domain back"
          />
        </button>

        <span v-if="!row.links.length" class="text-muted-foreground/70">nothing</span>
      </div>

      <div v-if="selected" class="mt-2 rounded border bg-background" data-testid="link-references">
        <p class="border-b px-3 py-1.5 text-muted-foreground">
          <template v-for="([kind, count], i) in selected.kinds" :key="kind">
            <template v-if="i"> · </template>{{ kind }} {{ count }}
          </template>
        </p>
        <ul class="divide-y">
          <li
            v-for="reference in selected.references"
            :key="`${reference.from}|${reference.to}|${reference.kind}`"
            class="flex flex-wrap items-baseline gap-x-2 px-3 py-1"
          >
            <span :title="reference.from">{{ baseName(reference.from) }}</span>
            <span class="text-muted-foreground">→</span>
            <span :title="reference.to">{{ baseName(reference.to) }}</span>
            <span class="rounded border px-1 text-[10px] text-muted-foreground">
              {{ reference.kind }}
            </span>
            <span class="ml-auto truncate text-[10px] text-muted-foreground/70">
              {{ reference.file }}:{{ lines(reference.lines) }}
            </span>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
