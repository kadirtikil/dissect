<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { ArrowUpRight } from '@lucide/vue'
import JobKindBadge from '@/components/JobKindBadge.vue'
import { useJobsStore } from '@/stores/jobs'
import { useRoutesStore } from '@/stores/routes'
import { useSchemaStore } from '@/stores/schema'
import { useNavigationStore } from '@/stores/navigation'
import { queueSourceLabel } from '@/lib/jobKinds'
import { DEFAULT_QUEUE, queueKey } from '@/lib/jobFilters'

const store = useJobsStore()
const routes = useRoutesStore()
const schema = useSchemaStore()
const nav = useNavigationStore()
const { selected } = storeToRefs(store)

/** Node ids the graph actually has — a chip for a model outside the scan would
 *  jump to nothing, so it is shown but not offered as a link. */
const known = computed(() => new Set(schema.nodes.map((n) => n.id)))

function openModel(id: string) {
  schema.focusModels([id])
  nav.go('models')
}

/**
 * Takes somebody from a job to the endpoint that queues it.
 *
 * The other direction of the same link the routes surface offers: what an
 * endpoint does after it has answered is half of what the endpoint does.
 */
function openRoute(id: string) {
  routes.focusEndpoint(id)
  nav.go('routes')
}

const queueLabel = computed(() => (selected.value ? queueKey(selected.value) : DEFAULT_QUEUE))

const queueNote = computed(() =>
  selected.value ? queueSourceLabel(selected.value.queue_source) : null,
)

/** The traits that are set. An unset one is not worth a chip saying "false". */
const traits = computed(() => {
  const t = selected.value?.traits
  if (!t) return []

  return [
    t.batchable && {
      key: 'batchable',
      hint: 'Part of a batch — reports progress and can be cancelled',
    },
    t.unique && { key: 'unique', hint: 'Only one instance may be queued at a time' },
    t.encrypted && { key: 'encrypted', hint: 'Payload is encrypted on the queue' },
    t.afterCommit && {
      key: 'after commit',
      hint: 'Held until the surrounding transaction commits',
    },
  ].filter((x) => x !== false)
})

const retry = computed(() => {
  const r = selected.value?.retry
  if (!r) return []

  return [
    r.tries !== null && { key: 'tries', value: String(r.tries) },
    r.backoff?.length && { key: 'backoff', value: `${r.backoff.join(', ')}s` },
    r.timeout !== null && { key: 'timeout', value: `${r.timeout}s` },
    r.maxExceptions !== null && { key: 'max exceptions', value: String(r.maxExceptions) },
    // Not when: retryUntil() returns a time computed at queue time, so the
    // honest report is that a deadline exists, not what it is.
    r.retryUntil && { key: 'retry until', value: 'set at queue time' },
  ].filter((x) => x !== false && x !== 0 && x !== undefined)
})
</script>

<template>
  <div v-if="selected" class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
    <div class="flex items-center gap-2">
      <JobKindBadge :kind="selected.kind" />
      <span class="min-w-0 font-mono text-sm break-all">{{ selected.name }}</span>
    </div>

    <p class="mt-1 font-mono text-[10px] break-all text-muted-foreground/70">
      {{ selected.class }}
    </p>

    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 font-mono text-[11px]">
      <dt class="text-muted-foreground">queue</dt>
      <dd class="break-all">
        {{ queueLabel }}
        <!-- Where the name came from is load-bearing: a job using Queueable
             cannot declare a $queue property, so most of these were read from
             somewhere other than the class's own field. -->
        <span v-if="queueNote" class="block text-[10px] text-muted-foreground/70">
          {{ queueNote }}
        </span>
      </dd>

      <template v-if="selected.connection">
        <dt class="text-muted-foreground">connection</dt>
        <dd class="break-all">{{ selected.connection }}</dd>
      </template>
    </dl>

    <section v-if="retry.length" class="mt-4">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">Retries</h3>
      <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 font-mono text-[11px]">
        <template v-for="row in retry" :key="row.key">
          <dt class="text-muted-foreground">{{ row.key }}</dt>
          <dd class="tabular-nums">{{ row.value }}</dd>
        </template>
      </dl>
    </section>

    <section v-if="traits.length" class="mt-4">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">Handling</h3>
      <div class="mt-1 flex flex-wrap gap-1">
        <span
          v-for="trait in traits"
          :key="trait.key"
          class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
          :title="trait.hint"
        >
          {{ trait.key }}
        </span>
      </div>
    </section>

    <!-- The constructor signature is literally what gets serialised onto the
         queue, so this is the payload rather than a description of one. -->
    <section v-if="selected.payload.length" class="mt-4">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">Payload</h3>
      <ul class="mt-1 flex flex-col gap-1">
        <li
          v-for="field in selected.payload"
          :key="field.name"
          class="flex flex-wrap items-baseline gap-x-2 font-mono text-[11px]"
        >
          <span>{{ field.variadic ? `…${field.name}` : field.name }}</span>
          <span v-if="field.type" class="text-[10px] text-muted-foreground/70">
            {{ field.type }}
          </span>
          <button
            v-if="field.model"
            class="text-[10px] text-muted-foreground underline decoration-dotted hover:text-foreground"
            :disabled="!known.has(field.model)"
            @click="openModel(field.model)"
          >
            carries {{ field.model }}
          </button>
          <span v-if="field.optional" class="text-[10px] text-muted-foreground">optional</span>
        </li>
      </ul>
    </section>

    <section v-if="selected.middleware.length" class="mt-4">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
        Middleware
      </h3>
      <div class="mt-1 flex flex-wrap gap-1">
        <span
          v-for="item in selected.middleware"
          :key="item"
          class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
        >
          {{ item }}
        </span>
      </div>
    </section>

    <!-- What makes a listener a listener: nothing about the class says so, only
         what it was registered against. -->
    <section v-if="selected.events.length" class="mt-4">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
        Listens for
      </h3>
      <div class="mt-1 flex flex-wrap gap-1">
        <span
          v-for="event in selected.events"
          :key="event"
          class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-[10px] break-all text-muted-foreground"
        >
          {{ event }}
        </span>
      </div>
    </section>

    <section class="mt-4">
      <div class="flex items-baseline gap-2">
        <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
          Dispatched by
        </h3>
        <span class="font-mono text-[10px] text-muted-foreground/70 tabular-nums">
          {{ selected.dispatched_by.length }}
        </span>
      </div>

      <!-- "None found" rather than "never dispatched": a dispatch behind a
           variable names a class only the running application knows, and
           claiming the job is dead would be a confident guess. -->
      <p
        v-if="!selected.dispatched_by.length"
        class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic"
      >
        No dispatch site found — dead code, or dispatched dynamically.
      </p>

      <ul v-else class="mt-1 flex flex-col gap-1.5">
        <li v-for="site in selected.dispatched_by" :key="`${site.file}:${site.line}`">
          <div class="flex flex-wrap items-baseline gap-x-2 font-mono text-[11px]">
            <span>{{ site.label }}</span>
            <span class="text-[10px] text-muted-foreground/70">{{ site.method }}()</span>
            <span v-if="site.queue" class="text-[10px] text-muted-foreground">
              → {{ site.queue }}
            </span>
            <span v-if="site.delayed" class="text-[10px] text-muted-foreground">delayed</span>
          </div>

          <div class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-mono text-[10px] break-all text-muted-foreground/70">
              {{ site.file }}:{{ site.line }}
            </span>

            <!-- The join to the endpoint list, and the reason this is a surface
                 in dissect rather than a second listing of job classes. -->
            <button
              v-if="site.route"
              class="flex items-center gap-1 font-mono text-[10px] text-muted-foreground underline decoration-dotted hover:text-foreground"
              :title="`Show ${site.route} on the routes surface`"
              @click="openRoute(site.route)"
            >
              {{ site.route }}
              <ArrowUpRight class="size-3" />
            </button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="selected.models.length" class="mt-4 border-t pt-3">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">Carries</h3>
      <div class="mt-1 flex flex-wrap gap-1">
        <button
          v-for="model in selected.models"
          :key="model"
          class="flex items-center gap-1 rounded-sm border px-1.5 py-0.5 font-mono text-[10px] hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!known.has(model)"
          :title="known.has(model) ? `Show ${model} on the graph` : `${model} is not in the graph`"
          @click="openModel(model)"
        >
          {{ model }}
          <ArrowUpRight v-if="known.has(model)" class="size-3" />
        </button>
      </div>
    </section>
  </div>

  <div v-else class="grid flex-1 place-items-center px-6">
    <p class="font-mono text-[11px] text-muted-foreground">
      Pick a job to see where it runs, what it carries, and what puts it there.
    </p>
  </div>
</template>
