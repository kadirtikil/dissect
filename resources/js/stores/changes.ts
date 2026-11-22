import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { BaselineName, ChangesFile } from '@/types/changes'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

const EMPTY: ChangesFile = {
  against: null,
  baselines: {
    watch: { exists: false, path: 'storage/dissect/watch.json', taken_at: null },
    snapshot: { exists: false, path: '.dissect/snapshot.json', taken_at: null },
  },
  problem: null,
  summary: { breaking: 0, risky: 0, additive: 0 },
  changes: [],
}

/**
 * What changed since a baseline, and starting and stopping watching.
 *
 * Fetched when the Changes page opens, then refreshed by the change poller
 * whenever a surface a snapshot is made of moves, or a baseline does.
 */
export const useChangesStore = defineStore('changes', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const file = shallowRef<ChangesFile>(EMPTY)

  /** Server change signal this answer was built from. */
  const fingerprint = ref<string | null>(null)

  /** The baseline somebody picked; null follows the server's preference. */
  const chosen = ref<BaselineName | null>(null)

  /** Starting or stopping watching, while the request is out. */
  const busy = ref(false)

  /** Why the last start or stop did not happen. */
  const actionError = ref<string | null>(null)

  const loaded = computed(() => status.value === 'ready')

  const stats = computed(() => ({
    changes: file.value.changes.length,
    ...file.value.summary,
  }))

  function endpoint(): string {
    const base = bootstrap().changesUrl ?? `${import.meta.env.BASE_URL}changes.json`

    return chosen.value ? `${base}?against=${chosen.value}` : base
  }

  async function fetchFile(): Promise<{ file: ChangesFile; fingerprint: string | null }> {
    const res = await fetch(endpoint(), { cache: 'no-store' })
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

    const payload = (await res.json()) as Partial<ChangesFile> & { fingerprint?: string }
    if (!Array.isArray(payload?.changes)) {
      throw new Error('Expected an object with a "changes" array')
    }

    return {
      file: {
        against: payload.against ?? null,
        baselines: { ...EMPTY.baselines, ...payload.baselines },
        problem: payload.problem ?? null,
        summary: { ...EMPTY.summary, ...payload.summary },
        changes: payload.changes,
      },
      fingerprint: payload.fingerprint ?? null,
    }
  }

  /** Fetches once; later calls are a no-op. */
  async function load(): Promise<void> {
    if (status.value === 'loading' || status.value === 'ready') return

    status.value = 'loading'
    error.value = null

    try {
      const next = await fetchFile()
      file.value = next.file
      fingerprint.value = next.fingerprint
      status.value = 'ready'
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      status.value = 'error'
    }
  }

  /** Pulls a fresh answer, keeping the current one if it cannot. */
  async function refresh(): Promise<boolean> {
    try {
      const next = await fetchFile()
      file.value = next.file
      fingerprint.value = next.fingerprint ?? fingerprint.value
      status.value = 'ready'

      return true
    } catch {
      return false
    }
  }

  /** Compares against another baseline from now on. */
  async function compareWith(name: BaselineName): Promise<void> {
    chosen.value = name
    await refresh()
  }

  /**
   * Starts watching, or restarts the clock. The answer right after is
   * "nothing changed", by definition, and is fetched rather than assumed.
   */
  async function startWatching(): Promise<void> {
    if (!(await send('POST'))) return

    chosen.value = 'watch'
    await refresh()
  }

  async function stopWatching(): Promise<void> {
    if (!(await send('DELETE'))) return

    if (chosen.value === 'watch') chosen.value = null
    await refresh()
  }

  /** True when the server did it; otherwise actionError says why. */
  async function send(method: 'POST' | 'DELETE'): Promise<boolean> {
    const { watchUrl, csrfToken } = bootstrap()

    busy.value = true
    actionError.value = null

    try {
      const res = await fetch(watchUrl ?? `${import.meta.env.BASE_URL}changes/watch`, {
        method,
        headers: {
          accept: 'application/json',
          // Laravel rejects the request without this; harmless when standalone.
          ...(csrfToken ? { 'x-csrf-token': csrfToken } : {}),
        },
      })

      if (!res.ok) {
        const answer = (await res.json().catch(() => null)) as { message?: unknown } | null
        throw new Error(
          typeof answer?.message === 'string' ? answer.message : `${res.status} ${res.statusText}`,
        )
      }

      return true
    } catch (e) {
      actionError.value = e instanceof Error ? e.message : String(e)

      return false
    } finally {
      busy.value = false
    }
  }

  return {
    status,
    error,
    file,
    fingerprint,
    chosen,
    busy,
    actionError,
    loaded,
    stats,
    load,
    refresh,
    compareWith,
    startWatching,
    stopWatching,
  }
})
