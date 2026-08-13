import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Which surface is on screen.
 *
 * There is still no router — the package mounts at an arbitrary prefix, so a
 * path-matching router finds no route and renders nothing (see ARCHITECTURE).
 * A mode is all two surfaces need, and it belongs in a store rather than in
 * App.vue so the routes panel can send somebody to the graph without an event
 * having to climb back up through the header.
 */
export type Mode = 'models' | 'routes'

/**
 * Persisted per browser, deliberately not in a committed file — the same call
 * already made for which saved view is open. What somebody happens to be
 * looking at is theirs, not the team's.
 */
const MODE_KEY = 'dissect:mode'

export const useUiStore = defineStore('ui', () => {
  const mode = ref<Mode>(restore())

  function setMode(next: Mode) {
    mode.value = next

    try {
      localStorage.setItem(MODE_KEY, next)
    } catch {
      // Private mode, or storage disabled. Losing this across a reload is not
      // worth failing over.
    }
  }

  return { mode, setMode }
})

function restore(): Mode {
  try {
    // Anything unrecognised falls back to the graph, which is what the page is
    // for and what every other piece of state assumes is on screen.
    return localStorage.getItem(MODE_KEY) === 'routes' ? 'routes' : 'models'
  } catch {
    return 'models'
  }
}
