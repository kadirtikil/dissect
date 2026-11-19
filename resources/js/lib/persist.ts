import { bootstrap } from '@/lib/bootstrap'

/**
 * Which version of a state file the page last read or wrote.
 *
 * A string or null (no file yet) comes from the package. `undefined` means the
 * host does not track revisions — the standalone dev server — and is simply not
 * sent, which the package treats as "do not check".
 */
export type Revision = string | null | undefined

/**
 * Format of layout.json and views.json this page writes. 2 keys models by
 * full class name; the package migrates a version 1 save from an older page.
 */
export const STATE_VERSION = 2

/** Reads the revision out of a payload, tolerating hosts that send none. */
export function revisionOf(payload: unknown): Revision {
  if (typeof payload !== 'object' || payload === null || !('revision' in payload)) return undefined
  const { revision } = payload as { revision: unknown }
  return typeof revision === 'string' || revision === null ? revision : undefined
}

/**
 * Writes one of the two state files and returns its new revision.
 *
 * The revision goes along so the package can refuse a save based on contents
 * that are no longer on disk — a git pull, another tab — rather than letting
 * this page's copy replace them. When it does, its message says so, and that
 * message is what gets thrown: "409 Conflict" would leave somebody guessing.
 */
export async function persist(url: string, payload: object, revision: Revision): Promise<Revision> {
  const { csrfToken } = bootstrap()

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      // Laravel rejects the POST without this; harmless when standalone.
      ...(csrfToken ? { 'x-csrf-token': csrfToken } : {}),
    },
    body: JSON.stringify(revision === undefined ? payload : { ...payload, revision }),
  })

  const answer: unknown = await res.json().catch(() => null)

  if (!res.ok) {
    const message = (answer as { message?: unknown } | null)?.message
    throw new Error(typeof message === 'string' ? message : `${res.status} ${res.statusText}`)
  }

  return revisionOf(answer)
}
