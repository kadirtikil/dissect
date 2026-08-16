<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { ArrowUpRight } from '@lucide/vue'
import MethodBadge from '@/components/MethodBadge.vue'
import FieldTree from '@/components/FieldTree.vue'
import { useRoutesStore } from '@/stores/routes'
import { useSchemaStore } from '@/stores/schema'
import { useNavigationStore } from '@/stores/navigation'

const store = useRoutesStore()
const schema = useSchemaStore()
const nav = useNavigationStore()
const { selected } = storeToRefs(store)

/** Node ids the graph actually has — a chip for a model outside the scan would
 *  jump to nothing, so it is shown but not offered as a link. */
const known = computed(() => new Set(schema.nodes.map((n) => n.id)))

/**
 * Takes somebody from an endpoint to the model it touches.
 *
 * The graph is the other half of the answer to "what does this endpoint do",
 * so the jump switches surface as well as centring the node — landing on the
 * canvas with the model ringed is the point of the link.
 */
function openModel(id: string) {
  schema.focusModels([id])
  nav.go('models')
}

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
          <!-- Route model binding: the parameter is not a string, it is a row. -->
          <button
            v-if="parameter.model"
            class="text-[10px] text-muted-foreground underline decoration-dotted hover:text-foreground"
            :disabled="!known.has(parameter.model)"
            @click="openModel(parameter.model)"
          >
            bound to {{ parameter.model }}
          </button>
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
          @open-model="openModel"
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
          @open-model="openModel"
        />
      </template>
    </section>

    <section v-if="selected.models.length" class="mt-4 border-t pt-3">
      <h3 class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">Touches</h3>
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
      Pick an endpoint to see how it is addressed and what it touches.
    </p>
  </div>
</template>
