import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { GraphView, ViewsFile } from '@/types/schema'
import { bootstrap } from '@/lib/bootstrap'

/** Standalone dev-server fallback; the package injects its own route. */
const DEV_ENDPOINT = '/__views'

/**
 * Which view was open last. Deliberately *not* in views.json: the file is
 * committed and shared, and which one a person happens to be reading is theirs
 * alone — a teammate pulling the repo should not inherit it.
 */
const ACTIVE_KEY = 'dissect:active-view'

/**
 * Saved views — named subsets of the graph, so a schema too big to read at once
 * can be looked at one bounded context at a time.
 *
 * A view carries membership and nothing else. Positions stay in the layout
 * store, which means a model sits in the same place whichever view is open, and
 * there is still exactly one writer for layout.json.
 */
export const useViewsStore = defineStore('views', () => {
  // shallowRef: the array is replaced wholesale on every edit, never mutated.
  const views = shallowRef<GraphView[]>([])
  const loaded = ref(false)
  const saving = ref(false)
  const saveError = ref<string | null>(null)

  /** Active view id; null means the whole graph. */
  const activeId = ref<string | null>(null)

  /** Ids currently selected on the canvas — what a new view is built from. */
  const selection = shallowRef<string[]>([])

  const active = computed(() => views.value.find((v) => v.id === activeId.value) ?? null)

  /**
   * Membership of the active view as a set, or null for "show everything".
   * Null rather than a set of every id so the graph can skip filtering
   * entirely in the common case.
   */
  const activeModels = computed<Set<string> | null>(() =>
    active.value ? new Set(active.value.models) : null,
  )

  async function load(url = `${import.meta.env.BASE_URL}views.json`) {
    // Inlined by the package alongside the schema — no round trip needed.
    const injected = bootstrap().views
    if (injected) {
      views.value = sanitise(injected.views)
    } else {
      try {
        const res = await fetch(url)
        // A missing views.json is the normal first-run state, not an error.
        if (res.ok) {
          const payload = (await res.json()) as Partial<ViewsFile>
          views.value = sanitise(payload?.views)
        }
      } catch {
        // A malformed file must never stop the graph rendering; the viewer is
        // perfectly usable with no views at all.
        views.value = []
      }
    }

    restoreActive()
    loaded.value = true
  }

  /**
   * Membership being assembled for a view that does not exist yet.
   *
   * A new view is not always a canvas selection: the models you want may be
   * off screen, or you may simply prefer picking them from a list. The draft is
   * seeded from the selection when there is one, and edited from either place.
   */
  const draft = ref<Set<string>>(new Set())

  function seedDraft() {
    draft.value = new Set(selection.value)
  }

  function toggleDraft(id: string) {
    if (draft.value.has(id)) draft.value.delete(id)
    else draft.value.add(id)
  }

  function clearDraft() {
    draft.value = new Set()
  }

  /**
   * Creates a view from the draft and opens it.
   *
   * Returns the new view, or null when there is nothing to save — the caller
   * uses that to keep its input open rather than silently doing nothing.
   */
  async function create(name: string): Promise<GraphView | null> {
    const models = [...draft.value]
    const label = name.trim()
    if (!label || models.length === 0) return null

    const view: GraphView = { id: uniqueId(label), name: label, models }
    views.value = [...views.value, view]
    setActive(view.id)
    clearDraft()
    await save()

    return view
  }

  /**
   * Adds or removes one model, whichever the view does not already say.
   *
   * The last member is deliberately not removable: an empty view is dropped on
   * write (see ViewRepository), so unchecking it would delete the view as a
   * side effect of editing it.
   */
  async function toggleMember(id: string): Promise<boolean> {
    const current = active.value
    if (!current) return false

    const isMember = current.models.includes(id)
    if (isMember && current.models.length === 1) return false

    const next = isMember ? current.models.filter((m) => m !== id) : [...current.models, id]

    views.value = views.value.map((v) => (v.id === current.id ? { ...v, models: next } : v))
    await save()

    return true
  }

  async function remove(id: string) {
    views.value = views.value.filter((v) => v.id !== id)
    if (activeId.value === id) setActive(null)
    await save()
  }

  function setActive(id: string | null) {
    // A view that no longer exists must not leave the graph filtered to
    // nothing with no way of telling why.
    activeId.value = id && views.value.some((v) => v.id === id) ? id : null

    try {
      if (activeId.value) localStorage.setItem(ACTIVE_KEY, activeId.value)
      else localStorage.removeItem(ACTIVE_KEY)
    } catch {
      // Private mode, or storage disabled. The selection simply will not
      // survive a reload, which is not worth failing over.
    }
  }

  function restoreActive() {
    try {
      setActive(localStorage.getItem(ACTIVE_KEY))
    } catch {
      setActive(null)
    }
  }

  /**
   * Writes the whole file. Not debounced like the layout: views change on a
   * deliberate click rather than continuously during a drag, so every edit is
   * worth persisting immediately.
   *
   * Ticking through a list produces one write per click, so they are queued
   * rather than raced — two POSTs landing out of order would leave the file
   * showing the earlier of the two edits.
   */
  let queue: Promise<void> = Promise.resolve()

  function save(): Promise<void> {
    queue = queue.then(() => write())
    return queue
  }

  async function write() {
    saving.value = true
    saveError.value = null

    const { saveViewsUrl, csrfToken } = bootstrap()

    try {
      const res = await fetch(saveViewsUrl ?? DEV_ENDPOINT, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          // Laravel rejects the POST without this; harmless when standalone.
          ...(csrfToken ? { 'x-csrf-token': csrfToken } : {}),
        },
        body: JSON.stringify({ version: 1, views: views.value }),
      })
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)
    } catch (e) {
      // The edit stays on screen: it is applied in memory either way, and
      // saying so is more useful than reverting what somebody just did.
      saveError.value = e instanceof Error ? e.message : String(e)
    } finally {
      saving.value = false
    }
  }

  /** Slug of the name, suffixed until it addresses only this view. */
  function uniqueId(name: string): string {
    const base =
      name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 64) || 'view'

    let id = base
    let n = 2
    while (views.value.some((v) => v.id === id)) id = `${base}-${n++}`

    return id
  }

  return {
    views,
    loaded,
    saving,
    saveError,
    activeId,
    active,
    activeModels,
    selection,
    draft,
    load,
    create,
    toggleMember,
    seedDraft,
    toggleDraft,
    clearDraft,
    remove,
    setActive,
  }
})

/** Same rules as ViewRepository::sanitise() — a hand-edited file gets no trust. */
function sanitise(input: unknown): GraphView[] {
  if (!Array.isArray(input)) return []

  const out: GraphView[] = []
  const seen = new Set<string>()

  for (const value of input) {
    if (typeof value !== 'object' || value === null) continue
    const { id, name, models } = value as Partial<GraphView>

    if (typeof id !== 'string' || typeof name !== 'string' || !Array.isArray(models)) continue
    if (!id || !name.trim() || models.length === 0 || seen.has(id)) continue

    seen.add(id)
    out.push({
      id,
      name,
      models: models.filter((m): m is string => typeof m === 'string'),
    })
  }

  return out
}
