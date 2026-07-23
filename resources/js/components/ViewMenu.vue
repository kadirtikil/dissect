<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { onClickOutside } from '@vueuse/core'
import { Check, ChevronDown, Layers, Plus, Trash2 } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { useViewsStore } from '@/stores/views'
import { useSchemaStore } from '@/stores/schema'

const store = useViewsStore()
const schema = useSchemaStore()
const { views, activeId, active, selection, draft, saveError } = storeToRefs(store)
const { nodes } = storeToRefs(schema)

const open = ref(false)
const name = ref('')
const search = ref('')
const nameInput = useTemplateRef<HTMLInputElement>('nameInput')

/** Which row is armed for deletion — the same two-step the reset button uses. */
const confirmingDelete = ref<string | null>(null)

const panel = useTemplateRef<HTMLElement>('panel')
onClickOutside(panel, () => close())

const label = computed(() => active.value?.name ?? 'All models')

/**
 * Membership the list is editing: the active view's, or the draft for a view
 * that does not exist yet. One list, two destinations.
 */
const members = computed(() =>
  active.value ? new Set(active.value.models) : (draft.value as Set<string>),
)

/** Every model in the schema, whether or not the active view shows it. */
const models = computed(() => {
  const term = search.value.trim().toLowerCase()

  return nodes.value
    .map((n) => ({ id: n.id, table: n.data?.table ?? '', external: n.data?.external ?? false }))
    .filter(
      (m) => !term || m.id.toLowerCase().includes(term) || m.table.toLowerCase().includes(term),
    )
    .sort((a, b) => a.id.localeCompare(b.id))
})

/**
 * Selected on the canvas but not yet ticked into the draft.
 *
 * Only meaningful while composing a new view: once a view is open the canvas
 * shows nothing but its members, so there is never a selection to add.
 */
const addable = computed(() =>
  active.value ? [] : selection.value.filter((id) => !members.value.has(id)),
)

const canCreate = computed(() => !active.value && draft.value.size > 0 && name.value.trim() !== '')

// Selecting on the canvas while composing a new view is the fastest way to
// fill it, so the draft follows the selection until something is checked by
// hand — after which the list is in charge and the selection stops overwriting.
let draftTouched = false
watch(selection, () => {
  if (!open.value || active.value || draftTouched) return
  store.seedDraft()
})

async function toggle() {
  open.value = !open.value
  confirmingDelete.value = null

  if (!open.value) return

  if (!active.value) {
    draftTouched = false
    store.seedDraft()

    if (selection.value.length) {
      // Opening with a selection in hand almost always means "save this" —
      // put the cursor where that happens.
      await nextTick()
      nameInput.value?.focus()
    }
  }
}

function close() {
  open.value = false
  confirmingDelete.value = null
  name.value = ''
  search.value = ''
  store.clearDraft()
}

function choose(id: string | null) {
  store.setActive(id)
  // Deliberately stays open: switching view and then adjusting what is in it
  // is one train of thought, and reopening the menu each time breaks it.
  confirmingDelete.value = null
  search.value = ''
  name.value = ''
  draftTouched = false
  if (!id) store.seedDraft()
}

/** One row of the model list. Edits the view directly, or the draft. */
async function toggleModel(id: string) {
  if (active.value) {
    await store.toggleMember(id)
    return
  }

  draftTouched = true
  store.toggleDraft(id)
}

async function create() {
  if (!canCreate.value) return
  if (await store.create(name.value)) {
    name.value = ''
    search.value = ''
  }
}

function addSelection() {
  draftTouched = true
  for (const id of addable.value) store.toggleDraft(id)
}

async function remove(id: string) {
  if (confirmingDelete.value !== id) {
    confirmingDelete.value = id
    return
  }
  confirmingDelete.value = null
  await store.remove(id)
}

/** True when unchecking would empty the view, which would delete it on save. */
function isLastMember(id: string): boolean {
  return !!active.value && active.value.models.length === 1 && active.value.models[0] === id
}
</script>

<template>
  <div ref="panel" class="relative">
    <Button
      variant="ghost"
      size="sm"
      class="font-mono text-xs"
      :aria-expanded="open"
      aria-haspopup="menu"
      :aria-label="`View: ${label}`"
      @click="toggle()"
    >
      <Layers class="size-3.5" />
      {{ label }}
      <span v-if="active" class="text-muted-foreground tabular-nums">
        · {{ active.models.length }}
      </span>
      <ChevronDown class="size-3" />
    </Button>

    <div
      v-if="open"
      class="absolute top-full left-0 z-50 mt-1 w-80 rounded-md border bg-popover p-1 shadow-lg"
      role="menu"
      @keydown.escape="close()"
    >
      <button
        class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left font-mono text-xs hover:bg-accent"
        role="menuitem"
        @click="choose(null)"
      >
        <Check class="size-3.5" :class="activeId ? 'opacity-0' : ''" />
        All models
      </button>

      <div
        v-for="view in views"
        :key="view.id"
        class="group flex items-center gap-1 rounded-sm hover:bg-accent"
      >
        <button
          class="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 text-left font-mono text-xs"
          role="menuitem"
          @click="choose(view.id)"
        >
          <Check class="size-3.5 shrink-0" :class="activeId === view.id ? '' : 'opacity-0'" />
          <span class="truncate">{{ view.name }}</span>
          <span class="ml-auto shrink-0 text-[10px] text-muted-foreground tabular-nums">
            {{ view.models.length }}
          </span>
        </button>

        <button
          class="mr-1 shrink-0 rounded-sm p-1 text-muted-foreground hover:text-destructive"
          :class="confirmingDelete === view.id ? 'text-destructive' : ''"
          :aria-label="
            confirmingDelete === view.id ? `Confirm deleting ${view.name}` : `Delete ${view.name}`
          "
          @click="remove(view.id)"
        >
          <Trash2 class="size-3" />
        </button>
      </div>

      <p
        v-if="confirmingDelete"
        class="px-2 py-1 font-mono text-[10px] text-destructive"
        role="status"
      >
        Click again to delete.
      </p>

      <!-- The membership editor. A view hides the models it does not contain,
           so the canvas cannot be the only way to add one — this list is what
           makes an existing view extendable at all. -->
      <div class="mt-1 border-t pt-1">
        <div class="flex items-center gap-2 px-2 py-1">
          <span class="font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
            {{ active ? `In “${active.name}”` : 'New view' }}
          </span>
          <span class="ml-auto font-mono text-[10px] text-muted-foreground tabular-nums">
            {{ members.size }} / {{ nodes.length }}
          </span>
        </div>

        <input
          v-model="search"
          class="mx-1 mb-1 w-[calc(100%-0.5rem)] rounded-sm border bg-background px-2 py-1 font-mono text-xs outline-none focus:border-ring"
          placeholder="Filter models…"
          aria-label="Filter models"
        />

        <ul class="max-h-56 overflow-y-auto">
          <li v-for="model in models" :key="model.id">
            <button
              class="flex w-full items-center gap-2 rounded-sm px-2 py-1 text-left font-mono text-xs hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
              role="menuitemcheckbox"
              :aria-checked="members.has(model.id)"
              :disabled="isLastMember(model.id)"
              :title="
                isLastMember(model.id)
                  ? 'A view needs at least one model — delete the view instead'
                  : undefined
              "
              @click="toggleModel(model.id)"
            >
              <Check class="size-3.5 shrink-0" :class="members.has(model.id) ? '' : 'opacity-0'" />
              <span class="truncate">{{ model.id }}</span>
              <span class="ml-auto shrink-0 text-[10px] text-muted-foreground">
                {{ model.external ? 'not in scan' : model.table }}
              </span>
            </button>
          </li>
          <li v-if="!models.length" class="px-2 py-1.5 font-mono text-[10px] text-muted-foreground">
            No model matches “{{ search }}”.
          </li>
        </ul>

        <!-- Shortcut for the canvas gesture; the list above can do the same job
             one model at a time. -->
        <button
          v-if="addable.length"
          class="mt-1 w-full rounded-sm px-2 py-1.5 text-left font-mono text-[11px] hover:bg-accent"
          @click="addSelection()"
        >
          + Add {{ addable.length }} selected on canvas
        </button>

        <form v-if="!active" class="mt-1 flex items-center gap-1 px-1" @submit.prevent="create()">
          <input
            ref="nameInput"
            v-model="name"
            class="min-w-0 flex-1 rounded-sm border bg-background px-2 py-1 font-mono text-xs outline-none focus:border-ring"
            :placeholder="
              draft.size ? `Name this view of ${draft.size} models` : 'Tick models, then name it'
            "
            maxlength="64"
            aria-label="Name for the new view"
          />
          <Button type="submit" variant="ghost" size="sm" :disabled="!canCreate" aria-label="Save">
            <Plus class="size-3.5" />
          </Button>
        </form>

        <p v-else class="px-2 py-1 font-mono text-[10px] text-muted-foreground">
          Changes save as you tick. Shift-drag the canvas to select several at once.
        </p>
      </div>

      <p v-if="saveError" class="px-2 py-1 font-mono text-[10px] text-destructive">
        Not saved: {{ saveError }}
      </p>
    </div>
  </div>
</template>
