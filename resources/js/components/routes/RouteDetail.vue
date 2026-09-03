<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import MethodBadge from '@/components/routes/MethodBadge.vue'
import FieldTree from '@/components/routes/FieldTree.vue'
import { useRoutesStore } from '@/stores/routes'

const store = useRoutesStore()
const { selected } = storeToRefs(store)

const bodyless = computed(
  () =>
    selected.value?.response?.source === 'view' || selected.value?.response?.source === 'redirect',
)

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
    <div class="flex items-center gap-2">
      <MethodBadge :methods="selected.methods" />
      <span class="min-w-0 font-mono text-sm break-all">{{ selected.uri }}</span>
    </div>

    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 font-mono text-[11px]">
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

    <section v-if="selected.parameters.length" class="mt-4">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
        Parameters
      </h3>
      <ul class="mt-1 flex flex-col gap-1">
        <li
          v-for="parameter in selected.parameters"
          :key="parameter.name"
          class="flex flex-wrap items-baseline gap-x-2 font-mono text-[11px]"
        >
          <span>{{ parameter.name }}</span>
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
    <section class="mt-4">
      <div class="flex items-baseline gap-2">
        <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">Request</h3>
        <span v-if="requestSummary" class="font-mono text-[10px] text-muted-foreground/70">
          {{ requestSummary }}
        </span>
      </div>

      <p
        v-if="!selected.request"
        class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic"
      >
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
        />
      </template>
    </section>

    <section class="mt-4">
      <div class="flex items-baseline gap-2">
        <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
          Response
        </h3>
        <!-- Whether one comes back or many is the first thing worth knowing,
             and a class name alone does not say it. -->
        <span v-if="selected.response" class="font-mono text-[10px] text-muted-foreground/70">
          {{ selected.response.source }}
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

      <!-- A view or a redirect has no payload to describe. "No fields found"
           would read as a failure to look, rather than as the answer. -->
      <p v-else-if="bodyless" class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic">
        {{ selected.response.source === 'view' ? 'Renders a view' : 'Redirects' }} — no JSON body.
      </p>

      <template v-else>
        <p v-if="selected.response.class" class="mt-1 font-mono text-[10px] break-all">
          {{ selected.response.class }}
        </p>
        <FieldTree
          :fields="selected.response.fields"
          :confidence="selected.response.confidence"
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
