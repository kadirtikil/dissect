<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useNow } from '@vueuse/core'
import { Eye, EyeOff, RotateCcw, TriangleAlert } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import ChangeItem from '@/components/changes/ChangeItem.vue'
import { PAGE_CONTEXT } from '@/lib/toolbar'
import { absolute, relative } from '@/lib/elapsed'
import { groupByPart, headline, PARTS, SEVERITIES, severityColor, since } from '@/lib/changes'
import type { BaselineName, Change, Severity } from '@/types/changes'
import { useChangesStore } from '@/stores/changes'
import { useSchemaStore } from '@/stores/schema'
import { useRoutesStore } from '@/stores/routes'
import { useJobsStore } from '@/stores/jobs'
import { useProviderStore } from '@/stores/provider'
import { useNavigationStore } from '@/stores/navigation'

const store = useChangesStore()
const { status, error, file, busy, actionError } = storeToRefs(store)

const nav = useNavigationStore()
const schema = useSchemaStore()
const routes = useRoutesStore()
const jobs = useJobsStore()
const providers = useProviderStore()

// Fetched here rather than at boot: building it takes a snapshot of the app.
onMounted(() => store.load())

/** Severities somebody chose to hide. All shown is the only sane default. */
const hidden = ref<Set<Severity>>(new Set())

function toggle(severity: Severity) {
  const next = new Set(hidden.value)
  if (next.has(severity)) next.delete(severity)
  else next.add(severity)
  hidden.value = next
}

const shown = computed(() => new Set(SEVERITIES.filter((s) => !hidden.value.has(s))))

const groups = computed(() => groupByPart(file.value.changes, shown.value))

const watching = computed(() => file.value.baselines.watch.exists)

// Ages are read every half minute: "12m ago" is all the precision it needs.
const now = useNow({ interval: 30_000 })

function age(name: BaselineName): string | null {
  return relative(file.value.baselines[name].taken_at, now.value.getTime())
}

const BASELINES: { name: BaselineName; label: string }[] = [
  { name: 'watch', label: 'Since watching started' },
  { name: 'snapshot', label: 'Since the snapshot' },
]

/** Takes somebody from a change to the item on its own page. */
function open(change: Change) {
  if (change.part === 'models') schema.focusModels([change.id])
  else if (change.part === 'routes') routes.focusEndpoint(change.id)
  else if (change.part === 'jobs') jobs.focusJob(change.id)
  else {
    providers.clearFilters()
    providers.select(change.id)
  }

  nav.go(PARTS[change.part].page)
}
</script>

<template>
  <div class="h-full overflow-y-auto">
    <Teleport defer :to="PAGE_CONTEXT">
      <span class="font-mono text-xs text-muted-foreground" data-testid="changes-headline">
        {{ status === 'ready' ? headline(file) : 'What changed' }}
      </span>
    </Teleport>

    <div
      v-if="status === 'loading' || status === 'idle'"
      class="grid h-full place-items-center font-mono text-xs text-muted-foreground"
    >
      Comparing…
    </div>

    <div v-else-if="status === 'error'" class="grid h-full place-items-center px-6">
      <div class="max-w-md rounded-md border border-destructive/40 bg-card px-4 py-3">
        <p class="font-mono text-xs font-semibold text-destructive">Could not load changes.json</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>
    </div>

    <div v-else class="mx-auto max-w-3xl space-y-5 px-8 py-10">
      <!-- What the page compares against, and the one thing it can change
           about that: watching. The committed snapshot is the team's, and is
           only ever taken in the terminal. -->
      <section class="space-y-2" data-testid="changes-baselines">
        <div class="flex flex-wrap items-center gap-2">
          <div class="flex rounded-md border bg-card p-0.5" role="group" aria-label="Compare with">
            <button
              v-for="baseline in BASELINES"
              :key="baseline.name"
              class="rounded-sm px-2.5 py-1 font-mono text-[11px] transition-colors disabled:cursor-not-allowed disabled:opacity-40"
              :class="
                file.against === baseline.name
                  ? 'bg-muted font-semibold text-foreground'
                  : 'text-muted-foreground hover:text-foreground'
              "
              :aria-pressed="file.against === baseline.name"
              :disabled="!file.baselines[baseline.name].exists || busy"
              :title="
                file.baselines[baseline.name].exists
                  ? `${file.baselines[baseline.name].path}, ${absolute(file.baselines[baseline.name].taken_at)}`
                  : `No ${file.baselines[baseline.name].path} yet`
              "
              @click="store.compareWith(baseline.name)"
            >
              {{ baseline.label }}
              <span v-if="age(baseline.name)" class="font-normal text-muted-foreground">
                · {{ age(baseline.name) }}
              </span>
            </button>
          </div>

          <div class="ml-auto flex items-center gap-1.5">
            <Button
              v-if="!watching"
              size="sm"
              :disabled="busy"
              data-testid="start-watching"
              @click="store.startWatching()"
            >
              <Eye /> Start watching
            </Button>
            <template v-else>
              <Button
                size="sm"
                variant="outline"
                :disabled="busy"
                title="Forget what changed so far, and watch from now"
                data-testid="restart-watching"
                @click="store.startWatching()"
              >
                <RotateCcw /> Restart
              </Button>
              <Button
                size="sm"
                variant="ghost"
                :disabled="busy"
                data-testid="stop-watching"
                @click="store.stopWatching()"
              >
                <EyeOff /> Stop
              </Button>
            </template>
          </div>
        </div>

        <p v-if="actionError" class="font-mono text-[11px] text-destructive" role="alert">
          {{ actionError }}
        </p>
        <p v-else-if="!watching" class="font-mono text-[11px] text-muted-foreground">
          Watching remembers how the app is now, so what you or an agent change from here shows up
          on this page, and in <code>dissect_changes</code>. It's kept in
          <code>{{ file.baselines.watch.path }}</code> and never committed.
        </p>
      </section>

      <div
        v-if="file.problem"
        class="rounded-md border border-destructive/40 bg-card px-4 py-3"
        data-testid="changes-problem"
      >
        <p class="flex items-center gap-2 font-mono text-xs font-semibold text-destructive">
          <TriangleAlert class="size-3.5" /> Could not read the baseline
        </p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">
          {{ file.problem }}
        </p>
      </div>

      <!-- The ordinary state on a first visit, so it explains rather than
           apologises. -->
      <div v-else-if="!file.against" data-testid="changes-empty">
        <h1 class="font-mono text-sm font-semibold">Nothing to compare with yet</h1>
        <p class="mt-1 font-mono text-xs text-muted-foreground">
          This page lists what changed in the models, routes, jobs and providers since a baseline,
          and how risky each change is for whoever depends on it.
        </p>
        <dl class="mt-4 divide-y rounded-md border bg-card font-mono text-[11px]">
          <div class="flex gap-4 px-3 py-1.5">
            <dt class="w-56 shrink-0">Start watching</dt>
            <dd class="text-muted-foreground">
              what changed since you sat down, or let an agent go
            </dd>
          </div>
          <div class="flex gap-4 px-3 py-1.5">
            <dt class="w-56 shrink-0">php artisan dissect:snapshot</dt>
            <dd class="text-muted-foreground">
              commit it; then CI's <code>dissect:diff</code> and this page compare with it
            </dd>
          </div>
        </dl>
      </div>

      <p
        v-else-if="!file.changes.length"
        class="font-mono text-xs text-muted-foreground"
        data-testid="changes-none"
      >
        Nothing changed {{ since(file.against) }}.
      </p>

      <template v-else>
        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Show">
          <button
            v-for="severity in SEVERITIES"
            :key="severity"
            class="flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-mono text-[11px] transition-opacity"
            :class="{ 'opacity-40': hidden.has(severity) }"
            :aria-pressed="!hidden.has(severity)"
            :disabled="!file.summary[severity]"
            :data-severity="severity"
            @click="toggle(severity)"
          >
            <span class="size-2 rounded-full" :style="{ background: severityColor(severity) }" />
            {{ severity }}
            <span class="text-muted-foreground tabular-nums">{{ file.summary[severity] }}</span>
          </button>
        </div>

        <section v-for="group in groups" :key="group.part" :data-part="group.part">
          <h2 class="font-mono text-xs font-semibold">
            {{ group.label }}
            <span class="font-normal text-muted-foreground tabular-nums">
              {{ group.changes.length }}
            </span>
          </h2>
          <ul class="mt-2 divide-y rounded-md border bg-card">
            <ChangeItem
              v-for="change in group.changes"
              :key="change.id"
              :change="change"
              @open="open"
            />
          </ul>
        </section>

        <p v-if="!groups.length" class="font-mono text-xs text-muted-foreground">
          Every change is hidden by the filter above.
        </p>
      </template>
    </div>
  </div>
</template>
