<script setup lang="ts">
import { computed } from 'vue'
import { ArrowUpRight } from '@lucide/vue'
import type { Change } from '@/types/changes'
import { canOpen, severityColor, splitMark } from '@/lib/changes'

const props = defineProps<{ change: Change }>()

const emit = defineEmits<{ open: [change: Change] }>()

const WORDS: Record<Change['change'], string> = {
  added: 'new',
  removed: 'gone',
  changed: 'changed',
}

const facts = computed(() =>
  props.change.facts.map((fact) => ({ ...fact, ...splitMark(fact.text) })),
)
</script>

<template>
  <li class="px-4 py-2" :data-change="change.id">
    <div class="flex items-baseline gap-2">
      <span
        class="w-16 shrink-0 font-mono text-[10px] font-semibold uppercase"
        :style="{ color: severityColor(change.severity) }"
      >
        {{ change.severity }}
      </span>
      <span class="min-w-0 truncate font-mono text-xs font-semibold" :title="change.id">
        {{ change.name }}
      </span>
      <span class="rounded border px-1 font-mono text-[10px] text-muted-foreground">
        {{ WORDS[change.change] }}
      </span>
      <button
        v-if="canOpen(change)"
        class="ml-auto flex shrink-0 items-center gap-0.5 font-mono text-[10px] text-muted-foreground hover:text-foreground"
        :aria-label="`Open ${change.name}`"
        @click="emit('open', change)"
      >
        open <ArrowUpRight class="size-3" />
      </button>
    </div>

    <ul v-if="facts.length" class="mt-1 space-y-0.5 pl-18">
      <li
        v-for="fact in facts"
        :key="fact.path.join('.')"
        class="flex items-baseline gap-2 font-mono text-[11px]"
      >
        <span
          class="w-2 shrink-0 text-center font-semibold"
          :style="{ color: severityColor(fact.severity) }"
          :title="fact.severity"
        >
          {{ fact.mark }}
        </span>
        <span class="min-w-0 break-words text-muted-foreground">{{ fact.rest }}</span>
        <span
          v-if="fact.severity !== change.severity || facts.length > 1"
          class="ml-auto shrink-0 text-[10px]"
          :style="{ color: severityColor(fact.severity) }"
        >
          {{ fact.severity }}
        </span>
      </li>
    </ul>
  </li>
</template>
