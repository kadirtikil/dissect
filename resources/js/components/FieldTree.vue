<script setup lang="ts">
import { computed } from 'vue'
import type { Confidence, RequestField, ResponseField } from '@/types/routes'

/**
 * Renders a payload shape — request or response, one component for both.
 *
 * The exporter emits a flat list of dotted paths (`tags[].name`) rather than a
 * nested structure, because a flat list is what both a form request's rules and
 * a resource's `toArray()` naturally are. The nesting is put back here, where
 * it costs one split per row and keeps the wire format simple.
 */
type Field = RequestField | ResponseField

const props = defineProps<{
  fields: Field[]
  confidence: Confidence
}>()

defineEmits<{ (e: 'open-model', model: string): void }>()

interface Row {
  field: Field
  /** How far in to indent — one step per parent segment. */
  depth: number
  /** Just the last segment; the parents are already on screen above it. */
  leaf: string
}

const rows = computed<Row[]>(() => {
  const paths = new Set(props.fields.map((f) => f.path))

  return props.fields.map((field) => {
    const cut = field.path.lastIndexOf('.')
    const parent = cut === -1 ? null : field.path.slice(0, cut)

    /**
     * Shortening `comments[].body` to `body` only reads as a tree when
     * `comments[]` is on screen above it. Where the parent is missing — a rule
     * for `tags.*.name` written without one for `tags` — the leaf alone would
     * be a field nobody can address, so the whole path is shown instead.
     *
     * The `[]` is stripped when looking for the parent because the request side
     * writes the container as `tags` and its children as `tags[].name`.
     */
    const hasParent =
      parent !== null && (paths.has(parent) || paths.has(parent.replace(/\[\]$/, '')))

    return {
      field,
      depth: hasParent ? field.path.split('.').length - 1 : 0,
      leaf: hasParent ? field.path.slice(cut + 1) : field.path,
    }
  })
})

/**
 * How the shape was arrived at.
 *
 * Shown rather than hidden: an endpoint description that is confidently wrong
 * costs more than one that admits what it could not work out, and "inferred"
 * is the honest answer whenever the rules were read from source instead of run.
 */
const CONFIDENCE_NOTE: Record<Confidence, string> = {
  certain: 'Read from the framework itself.',
  inferred: 'Read from the source, since it could not be evaluated safely.',
  unknown: 'Could not be determined — treat this as incomplete.',
}

function isRequestField(field: Field): field is RequestField {
  return 'rules' in field
}

/** The rule that says what a value is, as opposed to whether it is allowed. */
function typeOf(field: Field): string | null {
  if (isRequestField(field)) return field.type ?? null
  return field.kind === 'scalar' ? null : field.kind
}

/**
 * The link back to the graph: `Author.email` where a column is known, the model
 * alone where the field is a whole nested object.
 */
function modelLabel(field: Field): string | null {
  if (!field.model) return null

  return field.column ? `${field.model}.${field.column}` : field.model
}

/**
 * The constraints, for a request field only — a response field has no rules,
 * only a shape.
 *
 * `required` and the type already have their own markers on the row, so
 * repeating them here would say everything twice.
 */
function constraintsOf(field: Field): string | null {
  if (!isRequestField(field)) return null

  const rest = field.rules.filter((rule) => rule !== 'required' && rule !== field.type)

  return rest.length ? rest.join(' · ') : null
}
</script>

<template>
  <div v-if="rows.length" class="mt-1">
    <ul class="flex flex-col">
      <li
        v-for="row in rows"
        :key="row.field.path"
        class="flex flex-wrap items-baseline gap-x-2 py-0.5 font-mono text-[11px]"
        :style="{ paddingLeft: `${row.depth * 0.75}rem` }"
      >
        <span :class="row.depth ? 'text-muted-foreground' : ''">{{ row.leaf }}</span>

        <span v-if="typeOf(row.field)" class="text-[10px] text-muted-foreground">
          {{ typeOf(row.field) }}
        </span>

        <span
          v-if="isRequestField(row.field) && row.field.required"
          class="rounded-sm bg-primary/15 px-1 text-[9px] text-foreground/80"
          title="Required"
        >
          req
        </span>

        <!-- Wrapped in when() / whenLoaded(): a consumer reading this as a
             contract would otherwise assume the key is always there. -->
        <span
          v-if="!isRequestField(row.field) && row.field.conditional"
          class="rounded-sm bg-muted px-1 text-[9px] text-muted-foreground"
          title="Only present under some conditions"
        >
          sometimes
        </span>

        <button
          v-if="row.field.model"
          class="text-[10px] text-muted-foreground underline decoration-dotted hover:text-foreground"
          :title="`Show ${row.field.model} on the graph`"
          @click="$emit('open-model', row.field.model)"
        >
          {{ modelLabel(row.field) }}
        </button>

        <!-- The constraints come last: they are the longest part of a row and
             the least often looked at. -->
        <span v-if="constraintsOf(row.field)" class="text-[10px] text-muted-foreground/70">
          {{ constraintsOf(row.field) }}
        </span>
      </li>
    </ul>

    <p
      v-if="confidence !== 'certain'"
      class="mt-1 font-mono text-[10px] text-muted-foreground/70 italic"
      :title="CONFIDENCE_NOTE[confidence]"
    >
      {{ confidence }} — {{ CONFIDENCE_NOTE[confidence] }}
    </p>
  </div>

  <p v-else class="mt-1 font-mono text-[11px] text-muted-foreground/70 italic">
    No fields found.
  </p>
</template>
