<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import MethodBadge from '@/components/routes/MethodBadge.vue'
import FieldTree from '@/components/routes/FieldTree.vue'
import { useRoutesStore } from '@/stores/routes'
import { sectionColor, sectionSurface } from '@/lib/routeSections'
import { bodylessNote, sourceLabel } from '@/lib/payloadSources'

const store = useRoutesStore()
const { selected } = storeToRefs(store)

/**
 * The sentence for an endpoint with no JSON body, or null when it has one.
 *
 * A view, a redirect and a JSON:API `204` all arrive with an empty field list,
 * and "No fields found" would read as a failure to look rather than as the
 * answer it is.
 */
const bodyless = computed(() => bodylessNote(selected.value?.response?.source))

const requestSummary = computed(() => {
  const request = selected.value?.request
  if (!request) return null

  return request.source === 'none'
    ? 'No validation found on this endpoint.'
    : `${request.fields.length} field${request.fields.length === 1 ? '' : 's'}`
})
</script>

<template>
  <div v-if="selected" class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
    <!-- The title of the pane, deliberately outside the tinted blocks: the verb
         badge carries its own colour, and a wash behind it would read as one
         more section rather than as the thing they all describe. -->
    <div class="flex items-center gap-2">
      <MethodBadge :methods="selected.methods" />
      <span class="min-w-0 font-mono text-sm break-all">{{ selected.uri }}</span>
    </div>

    <section :style="sectionSurface('endpoint')" class="mt-3 rounded-md border border-l-2 px-3 py-2">
      <h3
        :style="{ color: sectionColor('endpoint') }"
        class="font-mono text-[10px] font-semibold tracking-wide uppercase"
      >
        Endpoint
      </h3>
      <dl class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 font-mono text-[11px]">
        <dt class="text-muted-foreground">name</dt>
        <dd class="break-all">{{ selected.name ?? '—' }}</dd>

        <dt class="text-muted-foreground">action</dt>
        <dd class="break-all">
          {{ selected.action.label }}
          <span v-if="selected.action.class" class="block text-[10px] text-muted-foreground/70">
            {{ selected.action.class }}
          </span>
        </dd>

        <template v-if="selected.domain">
          <dt class="text-muted-foreground">domain</dt>
          <dd class="break-all">{{ selected.domain }}</dd>
        </template>

        <dt class="text-muted-foreground">group</dt>
        <dd>{{ selected.group }}</dd>
      </dl>
    </section>

    <section
      v-if="selected.middleware.length"
      :style="sectionSurface('middleware')"
      class="mt-3 rounded-md border border-l-2 px-3 py-2"
    >
      <h3
        :style="{ color: sectionColor('middleware') }"
        class="font-mono text-[10px] font-semibold tracking-wide uppercase"
      >
        Middleware
      </h3>
      <div class="mt-1.5 flex flex-wrap gap-1">
        <span
          v-for="item in selected.middleware"
          :key="item"
          :style="{
            borderColor: `color-mix(in oklab, ${sectionColor('middleware')} 35%, transparent)`,
          }"
          class="rounded-sm border bg-background/40 px-1.5 py-0.5 font-mono text-[10px]"
        >
          {{ item }}
        </span>
      </div>
    </section>

    <section
      v-if="selected.parameters.length"
      :style="sectionSurface('parameters')"
      class="mt-3 rounded-md border border-l-2 px-3 py-2"
    >
      <h3
        :style="{ color: sectionColor('parameters') }"
        class="font-mono text-[10px] font-semibold tracking-wide uppercase"
      >
        Parameters
      </h3>
      <ul class="mt-1.5 flex flex-col gap-1">
        <li
          v-for="parameter in selected.parameters"
          :key="parameter.name"
          class="flex flex-wrap items-baseline gap-x-2 font-mono text-[11px]"
        >
          <span :style="{ color: sectionColor('parameters') }">{{ parameter.name }}</span>
          <span v-if="parameter.optional" class="text-[10px] text-muted-foreground">optional</span>
          <span v-if="parameter.field" class="text-[10px] text-muted-foreground">
            by {{ parameter.field }}
          </span>
          <span v-if="parameter.pattern" class="text-[10px] text-muted-foreground/70">
            {{ parameter.pattern }}
          </span>
        </li>
      </ul>
    </section>

    <!-- Request and response are absent until the exporter reads method bodies.
         Saying so beats an empty heading that looks like "this endpoint takes
         nothing". -->
    <section :style="sectionSurface('request')" class="mt-3 rounded-md border border-l-2 px-3 py-2">
      <div class="flex items-baseline gap-2">
        <h3
          :style="{ color: sectionColor('request') }"
          class="font-mono text-[10px] font-semibold tracking-wide uppercase"
        >
          Request
        </h3>
        <span v-if="selected.request" class="font-mono text-[10px] text-muted-foreground/70">
          {{ sourceLabel(selected.request.source) }}
        </span>
        <span v-if="requestSummary" class="font-mono text-[10px] text-muted-foreground/70">
          {{ requestSummary }}
        </span>
      </div>

      <p v-if="!selected.request" class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic">
        Not analysed.
      </p>

      <!-- "This endpoint validates nothing" is already said by the summary
           above; a field tree underneath it would only repeat itself. -->
      <template v-else-if="selected.request.source !== 'none'">
        <p v-if="selected.request.class" class="mt-1 font-mono text-[10px] break-all">
          {{ selected.request.class }}
        </p>
        <FieldTree
          :fields="selected.request.fields"
          :confidence="selected.request.confidence"
          :accent="sectionColor('request')"
        />
      </template>
    </section>

    <section :style="sectionSurface('response')" class="mt-3 rounded-md border border-l-2 px-3 py-2">
      <div class="flex items-baseline gap-2">
        <h3
          :style="{ color: sectionColor('response') }"
          class="font-mono text-[10px] font-semibold tracking-wide uppercase"
        >
          Response
        </h3>
        <!-- Whether one comes back or many is the first thing worth knowing,
             and a class name alone does not say it. -->
        <span v-if="selected.response" class="font-mono text-[10px] text-muted-foreground/70">
          {{ sourceLabel(selected.response.source) }}
        </span>
        <span
          v-if="selected.response?.status"
          class="font-mono text-[10px] text-muted-foreground/70 tabular-nums"
        >
          {{ selected.response.status }}
        </span>
      </div>

      <p
        v-if="!selected.response"
        class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic"
      >
        Not analysed.
      </p>

      <!-- No payload to describe, which is a finding rather than a gap. -->
      <p v-else-if="bodyless" class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic">
        {{ bodyless }} — no JSON body.
      </p>

      <template v-else>
        <p v-if="selected.response.class" class="mt-1 font-mono text-[10px] break-all">
          {{ selected.response.class }}
        </p>
        <FieldTree
          :fields="selected.response.fields"
          :confidence="selected.response.confidence"
          :accent="sectionColor('response')"
        />
      </template>
    </section>
  </div>

  <div v-else class="grid flex-1 place-items-center px-6">
    <p class="font-mono text-[11px] text-muted-foreground">
      Pick an endpoint to see how it is addressed and what goes over the wire.
    </p>
  </div>
</template>
