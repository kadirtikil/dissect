import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Which page is on screen.
 *
 * Called a page rather than a mode because these are becoming surfaces of their
 * own behind a sidebar, and a third is already planned. `PageId` is the id a
 * page is addressed by everywhere — in the store, in the URL, and (shortly) in
 * the registry a new surface is added to.
 */
export type PageId = 'models' | 'routes'

const PAGES: readonly PageId[] = ['models', 'routes']

const DEFAULT_PAGE: PageId = 'models'

/**
 * Persisted per browser, deliberately not in a committed file — the same call
 * already made for which saved view is open. What somebody happens to be
 * looking at is theirs, not the team's.
 */
const STORAGE_KEY = 'dissect:mode'

export const useNavigationStore = defineStore('navigation', () => {
  const current = ref<PageId>(restore())

  /**
   * The hash is written on boot too, so the address bar always names the page
   * on screen and is always worth copying. Replacing rather than pushing:
   * arriving somewhere is not a navigation, and a back button that first has to
   * undo the page you started on is a back button that appears broken.
   */
  syncHash(current.value, true)

  function go(next: PageId) {
    current.value = next
    // Pushes a history entry, which is what makes back and forward step
    // between pages.
    syncHash(next, false)

    try {
      localStorage.setItem(STORAGE_KEY, next)
    } catch {
      // Private mode, or storage disabled. Losing this across a reload is not
      // worth failing over.
    }
  }

  // Back, forward, or somebody editing the fragment by hand. An unrecognised
  // hash is ignored rather than corrected: rewriting what someone just typed is
  // more surprising than leaving it alone and staying put.
  window.addEventListener('hashchange', () => {
    const id = fromHash()

    if (id !== null) current.value = id
  })

  return { current, go }
})

/** `#/routes` — the page id, or null if the fragment names no page we have. */
function fromHash(): PageId | null {
  const id = window.location.hash.replace(/^#\/?/, '')

  return PAGES.includes(id as PageId) ? (id as PageId) : null
}

function syncHash(page: PageId, replace: boolean) {
  const hash = `#/${page}`

  if (window.location.hash === hash) return

  if (replace) {
    window.history.replaceState(null, '', hash)

    return
  }

  window.location.hash = hash
}

/**
 * A link somebody was sent beats what they had open last time — otherwise a
 * shared address would land them on their own previous page, which is the one
 * thing a shared address must not do.
 */
function restore(): PageId {
  return fromHash() ?? stored() ?? DEFAULT_PAGE
}

function stored(): PageId | null {
  try {
    const id = localStorage.getItem(STORAGE_KEY)

    return PAGES.includes(id as PageId) ? (id as PageId) : null
  } catch {
    return null
  }
}
