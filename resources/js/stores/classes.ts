import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { ClassesFile } from '@/types/classes'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

const EMPTY: ClassesFile = { classes: [], unreadable: [] }

/**
 * Holds the class index: which app class names which.
 *
 * Fetched only by the page that shows how domains depend on each other, and
 * only once it has domains to show: building it parses every PHP file under
 * the source folders, the widest read dissect does.
 */
export const useClassesStore = defineStore('classes', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const file = shallowRef<ClassesFile>(EMPTY)

  /** Server change signal this index was built from. */
  const fingerprint = ref<string | null>(null)

  const loaded = computed(() => status.value === 'ready')

  const stats = computed(() => ({
    classes: file.value.classes.length,
    references: file.value.classes.reduce((sum, c) => sum + c.uses.length, 0),
    unreadable: file.value.unreadable.length,
  }))

  function endpoint(): string {
    return bootstrap().classesUrl ?? `${import.meta.env.BASE_URL}classes.json`
  }

  async function fetchFile(): Promise<{ file: ClassesFile; fingerprint: string | null }> {
    const res = await fetch(endpoint(), { cache: 'no-store' })
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

    const payload = (await res.json()) as Partial<ClassesFile> & { fingerprint?: string }
    if (!Array.isArray(payload?.classes)) {
      throw new Error('Expected an object with a "classes" array')
    }

    return {
      file: {
        classes: payload.classes,
        unreadable: payload.unreadable ?? [],
        generated_at: payload.generated_at,
      },
      fingerprint: payload.fingerprint ?? null,
    }
  }

  /** Fetches the index once; later calls are a no-op. */
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

  /** Pulls a fresh index, keeping the current one if it cannot. */
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
