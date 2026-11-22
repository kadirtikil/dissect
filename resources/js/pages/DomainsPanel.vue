<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { ChevronRight, TriangleAlert } from '@lucide/vue'
import DomainCard from '@/components/domains/DomainCard.vue'
import { PAGE_CONTEXT } from '@/lib/toolbar'
import { baseName } from '@/lib/classNames'
import { useDomainsStore } from '@/stores/domains'
import { useClassesStore } from '@/stores/classes'
import { dependenciesOf, domainDependencies } from '@/lib/dependencies'
import { useBoundariesStore } from '@/stores/boundaries'
import { describeRule, ruleFor, violationsByDomain } from '@/lib/boundaries'

const store = useDomainsStore()
const { status, error, file, palette, stats } = storeToRefs(store)

const classes = useClassesStore()
const {
  status: classesStatus,
  error: classesError,
  file: index,
  stats: classStats,
} = storeToRefs(classes)

const boundaries = useBoundariesStore()
const {
  status: boundariesStatus,
  error: boundariesError,
  file: checked,
  stats: boundaryStats,
} = storeToRefs(boundaries)

const showUnassigned = ref(false)

/** What is wrong with domains.json, its rules included, in one list. */
const problems = computed(() => [...file.value.problems, ...checked.value.problems])

const broken = computed(() => violationsByDomain(checked.value.violations))

const dependencies = computed(() =>
  classesStatus.value === 'ready' ? domainDependencies(index.value.classes) : null,
)

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

// The class index only once there are domains for it to connect: it parses
// every source file, and an app with no domains has no boundary to show.
watch(
  () => status.value === 'ready' && file.value.domains.length > 0,
  (wanted) => {
    if (!wanted) return
    void classes.load()
    void boundaries.load()
  },
  { immediate: true },
)
</script>

<template>
  <div class="h-full overflow-y-auto">
    <Teleport defer :to="PAGE_CONTEXT">
      <span class="font-mono text-xs text-muted-foreground">
        <template v-if="status === 'ready' && stats.domains">
          {{ stats.domains }} {{ stats.domains === 1 ? 'domain' : 'domains' }} ·
          {{ stats.unassigned }} unassigned
          <template v-if="stats.findings"> · {{ stats.findings }} to check</template>
          <template v-if="classesStatus === 'ready'">
            · {{ classStats.classes }} {{ classStats.classes === 1 ? 'class' : 'classes' }} read
          </template>
          <template v-if="boundariesStatus === 'ready' && boundaryStats.rules">
            ·
            <span v-if="boundaryStats.violations" class="text-destructive">
              {{ boundaryStats.violations }}
              {{ boundaryStats.violations === 1 ? 'violation' : 'violations' }}
            </span>
            <template v-else>
              {{ boundaryStats.rules }} {{ boundaryStats.rules === 1 ? 'rule' : 'rules' }} kept
            </template>
          </template>
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
        v-if="problems.length"
        class="rounded-md border border-destructive/40 bg-card px-4 py-3"
        data-testid="domain-problems"
      >
        <p class="flex items-center gap-2 font-mono text-xs font-semibold text-destructive">
          <TriangleAlert class="size-3.5" /> .dissect/domains.json
        </p>
        <ul class="mt-1 list-disc pl-5 font-mono text-[11px] text-muted-foreground">
          <li v-for="problem in problems" :key="problem">{{ problem }}</li>
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
        <p
          v-if="classesStatus === 'loading'"
          class="font-mono text-[11px] text-muted-foreground"
          data-testid="classes-status"
        >
          Reading which class uses which…
        </p>
        <p
          v-else-if="classesStatus === 'error'"
          class="font-mono text-[11px] text-destructive"
          data-testid="classes-status"
        >
          Could not load classes.json: {{ classesError }}. Domains are listed without their
          dependencies.
        </p>
        <p
          v-else-if="classStats.unreadable"
          class="font-mono text-[11px] text-muted-foreground"
          data-testid="classes-status"
          :title="index.unreadable.join('\n')"
        >
          {{ classStats.unreadable }}
          {{ classStats.unreadable === 1 ? 'file' : 'files' }} could not be parsed, so what they use
          is not counted.
        </p>

        <div class="space-y-3">
          <DomainCard
            v-for="domain in file.domains"
            :key="domain.name"
            :domain="domain"
            :names="palette"
            :dependencies="dependencies && dependenciesOf(dependencies, domain.name)"
            :rules="checked.rules"
            :violations="broken.get(domain.name)?.length ?? 0"
          />
        </div>

        <p
          v-if="boundariesStatus === 'error'"
          class="font-mono text-[11px] text-destructive"
          data-testid="boundaries-status"
        >
          Could not load boundaries.json: {{ boundariesError }}. The rules are not shown.
        </p>

        <section v-if="broken.size" data-testid="domain-broken-rules">
          <h2 class="font-mono text-xs font-semibold text-destructive">Broken rules</h2>
          <p class="mt-1 font-mono text-[11px] text-muted-foreground">
            What <code>php artisan dissect:check</code> fails on.
            <template v-if="checked.unchecked.length">
              {{ checked.unchecked.length }}
              {{ checked.unchecked.length === 1 ? 'file does' : 'files do' }} not parse and
              {{ checked.unchecked.length === 1 ? 'was' : 'were' }} not checked.
            </template>
          </p>
          <div
            v-for="[domain, found] in broken"
            :key="domain"
            class="mt-2 rounded-md border border-destructive/40 bg-card"
            :data-broken="domain"
          >
            <p class="border-b px-4 py-2 font-mono text-[11px]">
              <span class="font-semibold">{{ domain }}</span>
              <span v-if="ruleFor(checked.rules, domain)" class="text-muted-foreground">
                · {{ describeRule(ruleFor(checked.rules, domain)!) }}
              </span>
            </p>
            <ul class="divide-y">
              <li
                v-for="violation in found"
                :key="`${violation.rule}|${violation.from}|${violation.to}|${violation.kind}`"
                class="px-4 py-1.5"
              >
                <p
                  class="flex flex-wrap items-baseline gap-x-2 font-mono text-xs"
                  :title="`${violation.from} → ${violation.to}`"
                >
                  {{ baseName(violation.from) }} → {{ baseName(violation.to) }}
                  <span class="rounded border px-1 text-[10px] text-muted-foreground">
                    {{ violation.kind }}
                  </span>
                  <span class="text-[11px] text-destructive">
                    <template v-if="violation.rule === 'must_not_use'">
                      must not use {{ violation.namespace }}
                    </template>
                    <template v-else>may not use {{ violation.to_domain }}</template>
                  </span>
                </p>
                <p class="font-mono text-[10px] text-muted-foreground/70">
                  {{ violation.file }}:{{ violation.lines.join(', ') }}
                </p>
              </li>
            </ul>
          </div>
        </section>

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
