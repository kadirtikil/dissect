<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { storeToRefs } from 'pinia'
import QueueSection from '@/components/QueueSection.vue'
import { PAGE_ACTIONS, PAGE_CONTEXT } from '@/lib/toolbar'
import { useQueueStore } from '@/stores/queue'
import { absolute, relative } from '@/lib/elapsed'

const store = useQueueStore()
// `connection` is deliberately not read here: the select shows the connection
// the snapshot came back with, which is the one actually on screen — the
// store's requested value can briefly name one the server fell back away from.
const { status, error, snapshot, reading, loaded, readable, depths, totals } = storeToRefs(store)

/**
 * Polls only while this page is on screen.
 *
 * Every other surface is fetched once and refreshed on a change signal. This
 * one does real work per request — a table scan, or three Redis reads per queue
 * — so it runs when somebody is looking and stops the moment they leave.
 */
let stop: (() => void) | undefined

onMounted(async () => {
  await store.read()
  stop = store.watchQueue()
})

onUnmounted(() => stop?.())

/** Totals across every queue, which is the headline the page opens with. */
const depth = computed(() => totals.value.waiting + totals.value.reserved)

const readAt = computed(() => relative(snapshot.value?.read_at ?? null))
</script>

<template>
  <div class="h-full overflow-y-auto">
    <Teleport defer :to="PAGE_CONTEXT">
      <span class="font-mono text-xs text-muted-foreground">
        <template v-if="loaded && readable">
          {{ depth }} on the queue · {{ totals.delayed }} delayed
        </template>
        <template v-else-if="loaded">{{ snapshot?.driver }} — nothing to read</template>
        <template v-else>Live queue</template>
      </span>
    </Teleport>

    <Teleport defer :to="PAGE_ACTIONS">
      <!-- A connection switcher rather than a fixed view of the default: an
           application with a database queue and a Redis queue is ordinary, and
           only one of them being visible would be the wrong default. -->
      <select
        v-if="(snapshot?.connections.length ?? 0) > 1"
        class="rounded-sm border bg-background px-1.5 py-0.5 font-mono text-[10px]"
        aria-label="Queue connection"
        :value="snapshot?.connection"
        @change="store.use(($event.target as HTMLSelectElement).value)"
      >
        <option v-for="name in snapshot?.connections ?? []" :key="name" :value="name">
          {{ name }}
        </option>
      </select>

      <!-- Says the page is live without ever being a spinner that never stops:
           it is the age of the reading on screen, which is the thing somebody
           actually wants to trust. -->
      <span
        v-if="loaded"
        class="font-mono text-[10px] text-muted-foreground/70"
        :title="absolute(snapshot?.read_at ?? null) ?? ''"
      >
        {{ reading ? 'reading…' : `read ${readAt}` }}
      </span>
    </Teleport>

    <div class="mx-auto max-w-4xl px-6 py-5">
      <div v-if="status === 'loading'" class="font-mono text-xs text-muted-foreground">
        Reading the queue…
      </div>

      <div
        v-else-if="status === 'error'"
        class="rounded-md border border-destructive/40 bg-card px-4 py-3"
      >
        <p class="font-mono text-xs font-semibold text-destructive">Could not read the queue</p>
        <p class="mt-1 font-mono text-[11px] break-words text-muted-foreground">{{ error }}</p>
      </div>

      <template v-else-if="snapshot">
        <div class="flex items-baseline gap-2">
          <h1 class="font-mono text-sm font-semibold">{{ snapshot.connection }}</h1>
          <span class="font-mono text-[10px] text-muted-foreground/70">{{ snapshot.driver }}</span>
        </div>

        <!-- Not an error: two of Laravel's drivers can be enumerated and the
             rest cannot, and that is a fact about the driver. Showing an empty
             queue instead would be a lie about an empty queue. -->
        <div
          v-if="!readable"
          class="mt-3 rounded-md border bg-card px-4 py-3 font-mono text-[11px] text-muted-foreground"
        >
          {{ snapshot.refusal }}
        </div>

        <template v-else>
          <!-- Depth first: counting is cheap where listing is not, so the shape
               of the backlog comes before any of it is read. -->
          <dl
            v-if="depths.length"
            class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-md border bg-border sm:grid-cols-4"
          >
            <div v-for="queue in depths" :key="queue.name" class="bg-card px-3 py-2">
              <dt class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
                {{ queue.name }}
              </dt>
              <dd class="mt-0.5 font-mono text-lg tabular-nums">{{ queue.waiting }}</dd>
              <dd class="font-mono text-[10px] text-muted-foreground/70 tabular-nums">
                {{ queue.reserved }} running · {{ queue.delayed }} delayed
              </dd>
            </div>
          </dl>

          <div class="mt-6">
            <h2 class="font-mono text-xs font-semibold">Now</h2>

            <QueueSection
              title="Waiting"
              hint="oldest first — the order a worker will take them"
              :section="snapshot.now.waiting"
              time-label="queued"
              time-field="queued_at"
              empty="Nothing is waiting."
            />

            <QueueSection
              title="Reserved"
              hint="taken by a worker and not yet finished"
              :section="snapshot.now.reserved"
              time-label="taken"
              time-field="reserved_at"
              empty="No worker is holding anything."
            />
          </div>

          <div class="mt-6">
            <h2 class="font-mono text-xs font-semibold">Next</h2>

            <QueueSection
              title="Delayed"
              hint="held until their time arrives"
              :section="snapshot.next.delayed"
              time-label="runs"
              time-field="available_at"
              empty="Nothing is scheduled to arrive."
            />
          </div>
        </template>

        <!-- Readable even when the queue is not: failures are stored by the
             application rather than the driver, so an SQS app still knows what
             went wrong. -->
        <div class="mt-6">
          <h2 class="font-mono text-xs font-semibold">Past</h2>

          <!-- The honest limit of the whole surface, said once and plainly. -->
          <p
            v-if="!snapshot.records_completions"
            class="mt-1 font-mono text-[10px] text-muted-foreground/70"
          >
            Laravel keeps no record of a job that succeeded, so this is what failed and what was
            batched. An empty history means nothing failed — not that nothing ran.
          </p>

          <QueueSection
            title="Failed"
            :section="snapshot.past.failed"
            time-label="failed"
            time-field="failed_at"
            empty="Nothing has failed."
            show-exception
          />

          <section class="mt-5">
            <div class="flex items-baseline gap-2">
              <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
                Batches
              </h3>
              <span class="font-mono text-[10px] text-muted-foreground/70 tabular-nums">
                {{ snapshot.past.batches.length }}
              </span>
            </div>

            <p
              v-if="!snapshot.past.batches.length"
              class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic"
            >
              No batches recorded.
            </p>

            <ul v-else class="mt-1 flex flex-col gap-1">
              <li
                v-for="batch in snapshot.past.batches"
                :key="batch.id"
                class="flex flex-wrap items-baseline gap-x-3 border-t py-1 font-mono text-[11px]"
              >
                <span class="min-w-0 flex-1 truncate">{{ batch.name || batch.id }}</span>
                <span class="text-muted-foreground tabular-nums">
                  {{ batch.total - batch.pending }}/{{ batch.total }}
                </span>
                <span v-if="batch.failed" class="text-destructive tabular-nums">
                  {{ batch.failed }} failed
                </span>
                <!-- A batch is the one place a *finished* job leaves a mark,
                     because the batch counts what it completed after each job
                     is gone. -->
                <span class="text-muted-foreground/70">
                  {{
                    batch.cancelled_at
                      ? 'cancelled'
                      : batch.finished_at
                        ? `finished ${relative(batch.finished_at)}`
                        : 'running'
                  }}
                </span>
              </li>
            </ul>
          </section>
        </div>
      </template>
    </div>
  </div>
</template>
