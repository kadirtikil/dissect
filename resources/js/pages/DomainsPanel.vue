<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { ChevronRight, TriangleAlert } from '@lucide/vue'
import DomainCard from '@/components/domains/DomainCard.vue'
import { PAGE_CONTEXT } from '@/lib/toolbar'
import { baseName } from '@/lib/classNames'
import { useDomainsStore } from '@/stores/domains'

const store = useDomainsStore()
const { status, error, file, palette, stats } = storeToRefs(store)

const showUnassigned = ref(false)

/** The layouts dissect reads domains from, for the empty state. */
const conventions = [
  ['Modules/{Name}', 'nwidart/laravel-modules'],
  ['app-modules/{name}', 'InterNACHI/modular'],
  ['app/Domain/{Name}', 'Laravel Beyond CRUD, also src/ and Domains'],
  ['packages/{name}', 'a local Composer package'],
  ['src/{Name}/Domain, …/Application', 'hexagonal'],
  ['app/Models/{Name} + app/Http/Controllers/{Name}', 'layer-first sub-folders'],
]

// Fetched here rather than at boot: this is the moment somebody asked for it.
onMounted(() => store.load())
</script>

<template>
  <div class="h-full overflow-y-auto">
    <Teleport defer :to="PAGE_CONTEXT">
      <span class="font-mono text-xs text-muted-foreground">
        <template v-if="status === 'ready' && stats.domains">
          {{ stats.domains }} {{ stats.domains === 1 ? 'domain' : 'domains' }} ·
          {{ stats.unassigned }} unassigned
          <template v-if="stats.findings"> · {{ stats.findings }} to check</template>
        </template>
        <template v-else>Bounded contexts</template>
      </span>
    </Teleport>

    <div
      v-if="status === 'loading' || status === 'idle'"
      class="grid h-full place-items-center font-mono text-xs text-muted-foreground"
    >
      Reading domains…
    </div>

    <div v-else-if="status === 'error'" class="grid h-full place-items-center px-6">
      <div class="max-w-md rounded-md border border-destructive/40 bg-card px-4 py-3">
        <p class="font-mono text-xs font-semibold text-destructive">Could not load domains.json</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>
    </div>

    <div v-else class="mx-auto max-w-3xl space-y-6 px-8 py-10">
      <!-- What is wrong with the hand-written file comes first: until it is
           fixed, some of what follows is not what was meant. -->
      <div
        v-if="file.problems.length"
        class="rounded-md border border-destructive/40 bg-card px-4 py-3"
        data-testid="domain-problems"
      >
        <p class="flex items-center gap-2 font-mono text-xs font-semibold text-destructive">
          <TriangleAlert class="size-3.5" /> .dissect/domains.json
        </p>
        <ul class="mt-1 list-disc pl-5 font-mono text-[11px] text-muted-foreground">
          <li v-for="problem in file.problems" :key="problem">{{ problem }}</li>
        </ul>
      </div>

      <div v-if="!file.enabled" class="font-mono text-xs text-muted-foreground">
        Domain detection is switched off (<code>dissect.domains.enabled</code>).
      </div>

      <!-- The ordinary state for most apps, so it explains rather than apologises. -->
      <div v-else-if="!file.domains.length" data-testid="domains-empty">
        <h1 class="font-mono text-sm font-semibold">No domains found</h1>
        <p class="mt-1 font-mono text-xs text-muted-foreground">
          This app follows none of the folder layouts dissect recognises, and has no
          <code>.dissect/domains.json</code> or <code>#[Domain]</code> attribute. Nothing else on
          the page changes.
        </p>
        <dl class="mt-4 divide-y rounded-md border bg-card font-mono text-[11px]">
          <div v-for="[path, what] in conventions" :key="path" class="flex gap-4 px-3 py-1.5">
            <dt class="w-72 shrink-0">{{ path }}</dt>
            <dd class="text-muted-foreground">{{ what }}</dd>
          </div>
        </dl>
      </div>

      <template v-else>
        <div class="space-y-3">
          <DomainCard
            v-for="domain in file.domains"
            :key="domain.name"
            :domain="domain"
            :names="palette"
          />
        </div>

        <section v-if="file.findings.length" data-testid="domain-findings">
          <h2 class="font-mono text-xs font-semibold">To check</h2>
          <ul class="mt-2 divide-y rounded-md border bg-card">
            <li v-for="finding in file.findings" :key="finding.class" class="px-4 py-2">
              <p class="font-mono text-xs" :title="finding.class">{{ baseName(finding.class) }}</p>
              <p class="font-mono text-[11px] text-muted-foreground">{{ finding.message }}</p>
              <p class="font-mono text-[10px] text-muted-foreground/70">{{ finding.file }}</p>
            </li>
          </ul>
        </section>

        <section v-if="file.unassigned.length" data-testid="domain-unassigned">
          <button
            class="flex items-center gap-1.5 font-mono text-xs font-semibold"
            :aria-expanded="showUnassigned"
            @click="showUnassigned = !showUnassigned"
          >
            <ChevronRight
              class="size-3 transition-transform"
              :class="{ 'rotate-90': showUnassigned }"
            />
            Unassigned
            <span class="font-normal text-muted-foreground tabular-nums">
              {{ file.unassigned.length }}
            </span>
          </button>
          <p class="mt-1 font-mono text-[11px] text-muted-foreground">
            In no domain's folder, domains.json entry or attribute. Listed, never guessed.
          </p>
          <ul
            v-if="showUnassigned"
            class="mt-2 rounded-md border bg-card px-4 py-2 font-mono text-xs"
          >
            <li v-for="name in file.unassigned" :key="name" :title="name">{{ name }}</li>
          </ul>
        </section>
      </template>
    </div>
  </div>
</template>
