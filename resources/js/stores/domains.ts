import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { DomainsFile } from '@/types/domains'
import { bootstrap } from '@/lib/bootstrap'
import { domainPalette } from '@/lib/domains'

type Status = 'idle' | 'loading' | 'ready' | 'error'

const EMPTY: DomainsFile = {
  enabled: true,
  domains: [],
  unassigned: [],
  findings: [],
  problems: [],
}

/**
 * Holds the domain list.
 *
 * Fetched the first time the list is opened, like the other derived surfaces:
 * building it reads every PHP file under the application's source folders.
 * The other pages never need it, because every row they show already carries
 * its own domain as `context`.
 */
export const useDomainsStore = defineStore('domains', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every load, never mutated in place.
  const file = shallowRef<DomainsFile>(EMPTY)

  /** Server change signal this list was built from. */
  const fingerprint = ref<string | null>(null)

  const loaded = computed(() => status.value === 'ready')

  /** Every domain on the list. */
  const names = computed(() => file.value.domains.map((d) => d.name))

  /**
   * The order colours are handed out in, for every page.
   *
   * Starts from the names the page was given rather than from this list, so
   * a page that never opens the list colours its rows the same way.
   */
  const palette = computed(() => domainPalette(bootstrap().domains ?? [], names.value))

  const stats = computed(() => ({
    domains: file.value.domains.length,
    classes: file.value.domains.reduce((sum, d) => sum + d.classes.length, 0),
    unassigned: file.value.unassigned.length,
    findings: file.value.findings.length,
  }))

  function endpoint(): string {
    return bootstrap().domainsUrl ?? `${import.meta.env.BASE_URL}domains.json`
  }

  async function fetchFile(): Promise<{ file: DomainsFile; fingerprint: string | null }> {
    const res = await fetch(endpoint(), { cache: 'no-store' })
    if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

    const payload = (await res.json()) as Partial<DomainsFile> & { fingerprint?: string }
    if (!Array.isArray(payload?.domains)) {
      throw new Error('Expected an object with a "domains" array')
    }

    return {
      file: {
        enabled: payload.enabled ?? true,
        domains: payload.domains,
        unassigned: payload.unassigned ?? [],
        findings: payload.findings ?? [],
        problems: payload.problems ?? [],
        generated_at: payload.generated_at,
      },
      fingerprint: payload.fingerprint ?? null,
    }
  }

  /** Fetches the list once; later calls are a no-op. */
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

  /**
   * Pulls a fresh list and swaps it in, keeping the current one if it cannot:
   * a failed refresh must not replace a working list with an error card.
   */
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

  return { status, error, file, fingerprint, loaded, names, palette, stats, load, refresh }
})
