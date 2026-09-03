<script setup lang="ts">
import { computed } from 'vue'
import { ArrowUpRight } from '@lucide/vue'
import type { QueueRow, QueueSection } from '@/types/queue'
import { useJobsStore } from '@/stores/jobs'
import { useNavigationStore } from '@/stores/navigation'
import { absolute, relative } from '@/lib/elapsed'
import { kindColor } from '@/lib/jobKinds'

const props = defineProps<{
  title: string
  hint?: string
  section: QueueSection
  /** Which moment this tense is read by — queued, available, reserved, failed. */
  timeLabel: string
  timeField: 'queued_at' | 'available_at' | 'reserved_at' | 'reserved_until' | 'failed_at'
  empty: string
  /** Failed rows carry an exception line; nothing else does. */
  showException?: boolean
}>()

const jobs = useJobsStore()
const nav = useNavigationStore()

/**
 * The kind of each job on screen, when the job list happens to be in hand.
 *
 * Not fetched for this — the queue surface must stand up on its own — but if
 * somebody has already opened Jobs, the rows here are the same classes and may
 * as well say so.
 */
const kinds = computed(() => {
  const byClass: Record<string, string> = {}

  for (const job of jobs.jobs) byClass[job.id] = job.kind

  return byClass
})

/** Takes a live row to the class behind it. */
function openJob(row: QueueRow) {
  if (!row.job) return

  jobs.focusJob(row.job)
  nav.go('jobs')
}

function timeOn(row: QueueRow): string | null {
  return relative(row[props.timeField] ?? null)
}
</script>

<template>
  <section class="mt-5 first:mt-0">
    <div class="flex items-baseline gap-2">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
        {{ title }}
      </h3>
      <span class="font-mono text-[10px] text-muted-foreground/70 tabular-nums">
        {{ section.total }}
      </span>
      <span v-if="hint" class="font-mono text-[10px] text-muted-foreground/70">{{ hint }}</span>
    </div>

    <p
      v-if="!section.rows.length"
      class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic"
    >
      {{ empty }}
    </p>

    <table
      v-if="section.rows.length"
      class="mt-1 w-full border-separate border-spacing-0 font-mono text-[11px]"
    >
      <thead>
        <tr class="text-left text-[10px] text-muted-foreground/70">
          <th class="py-1 pr-3 font-normal">job</th>
          <th class="py-1 pr-3 font-normal">queue</th>
          <th class="py-1 pr-3 font-normal">tries</th>
          <th class="py-1 font-normal">{{ timeLabel }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, index) in section.rows"
          :key="row.id ?? row.uuid ?? index"
          class="border-t"
        >
          <td class="py-1 pr-3">
            <button
              class="flex items-center gap-1.5 text-left hover:text-foreground disabled:cursor-default"
              :disabled="!row.job"
              :title="row.job ?? 'The payload did not name a class'"
              @click="openJob(row)"
            >
              <!-- A dot rather than the full badge: this is a dense table, and
                   the kind is secondary to which job it is. -->
              <span
                v-if="row.job && kinds[row.job]"
                class="size-1.5 shrink-0 rounded-full"
                :style="{ backgroundColor: kindColor(kinds[row.job]!) }"
              />
              {{ row.name }}
              <ArrowUpRight v-if="row.job" class="size-3 opacity-40" />
            </button>

            <p
              v-if="showException && row.exception"
              class="mt-0.5 text-[10px] break-words text-destructive/80"
            >
              {{ row.exception }}
            </p>
          </td>

          <td class="py-1 pr-3 align-top text-muted-foreground">{{ row.queue ?? '—' }}</td>

          <td class="py-1 pr-3 align-top text-muted-foreground tabular-nums">
            {{ row.attempts
            }}<span v-if="row.maxTries" class="opacity-60">/{{ row.maxTries }}</span>
          </td>

          <td
            class="py-1 align-top text-muted-foreground tabular-nums"
            :title="absolute(row[timeField] ?? null) ?? ''"
          >
            {{ timeOn(row) ?? '—' }}
          </td>
        </tr>
      </tbody>
    </table>

    <!-- The count above is the real depth; this is the page of it that was
         fetched. Saying which is which matters most exactly when a queue is
         deep enough for the difference to be alarming. -->
    <p v-if="section.truncated" class="mt-1 font-mono text-[10px] text-muted-foreground/70">
      Showing the first {{ section.rows.length }} of {{ section.total }}.
    </p>
  </section>
</template>
