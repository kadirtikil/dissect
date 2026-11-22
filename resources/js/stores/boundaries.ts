import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { BoundariesFile } from '@/types/boundaries'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

const EMPTY: BoundariesFile = {
  enabled: true,
  rules: [],
  violations: [],
  problems: [],
  unchecked: [],
}

/**
 * Holds the boundary rules and every place the code breaks one.
 *
 * Fetched by the Domains page once it has domains to show, beside the class
 * index it is read from. An app with no rules gets an empty answer without
 * the server building that index.
 */
export const useBoundariesStore = defineStore('boundaries', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const file = shallowRef<BoundariesFile>(EMPTY)

  /** Server change signal this answer was built from: the code and the rules. */
  const fingerprint = ref<string | null>(null)

  const loaded = computed(() => status.value === 'ready')

  const stats = computed(() => ({
    rules: file.value.rules.length,
    violations: file.value.violations.length,
    problems: file.value.problems.length,
  }))

  function endpoint(): string {
    return bootstrap().boundariesUrl ?? `${import.meta.env.BASE_URL}boundaries.json`
  }

  async function fetchFile(): Promise<{ file: BoundariesFile; fingerprint: string | null }> {
    const res = await fetch(endpoint(), { cache: 'no-store' })
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

    const payload = (await res.json()) as Partial<BoundariesFile> & { fingerprint?: string }
    if (!Array.isArray(payload?.violations)) {
      throw new Error('Expected an object with a "violations" array')
    }

    return {
      file: {
        enabled: payload.enabled ?? true,
        rules: payload.rules ?? [],
        violations: payload.violations,
        problems: payload.problems ?? [],
        unchecked: payload.unchecked ?? [],
        generated_at: payload.generated_at,
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

  return { status, error, file, fingerprint, loaded, stats, load, refresh }
})
