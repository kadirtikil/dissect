import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useChangesStore } from '@/stores/changes'
import type { ChangesFile } from '@/types/changes'

function answer(overrides: Partial<ChangesFile> & { fingerprint?: string } = {}) {
  return {
    against: 'snapshot',
    baselines: {
      watch: { exists: false, path: 'storage/dissect/watch.json', taken_at: null },
      snapshot: {
        exists: true,
        path: '.dissect/snapshot.json',
        taken_at: '2026-11-22T10:00:00+00:00',
      },
    },
    problem: null,
    summary: { breaking: 1, risky: 0, additive: 0 },
    changes: [
      {
        part: 'routes',
        id: 'GET:api/posts',
        name: 'GET api/posts',
        change: 'removed',
        severity: 'breaking',
        text: '- route GET api/posts',
        facts: [],
      },
    ],
    fingerprint: 'aaaa',
    ...overrides,
  }
}

/** The URL of the last request. */
const lastUrl = (fetch: { mock: { calls: [string, RequestInit?][] } }) =>
  fetch.mock.calls[fetch.mock.calls.length - 1]?.[0]

const json = (body: unknown, status = 200) =>
  Promise.resolve(new Response(JSON.stringify(body), { status }))

describe('changes store', () => {
  let fetch: ReturnType<typeof vi.fn<(url: string, init?: RequestInit) => Promise<Response>>>

  beforeEach(() => {
    setActivePinia(createPinia())
    fetch = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>(() => json(answer()))
    vi.stubGlobal('fetch', fetch)
    window.__DISSECT__ = {
      changesUrl: '/dissect/changes.json',
      watchUrl: '/dissect/changes/watch',
      csrfToken: 'token',
    }
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    delete window.__DISSECT__
  })

  it('loads once, and fills in what an older server leaves out', async () => {
    fetch.mockImplementationOnce(() =>
      json({ against: null, changes: [], summary: { breaking: 0 } }),
    )
    const changes = useChangesStore()

    await changes.load()
    await changes.load()

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch.mock.calls[0]?.[0]).toBe('/dissect/changes.json')
    expect(changes.loaded).toBe(true)
    expect(changes.file.summary).toEqual({ breaking: 0, risky: 0, additive: 0 })
    expect(changes.file.baselines.watch.exists).toBe(false)
    expect(changes.fingerprint).toBeNull()
  })

  it('says why it could not load', async () => {
    fetch.mockImplementationOnce(() => json({ message: 'nope' }, 500))
    const changes = useChangesStore()

    await changes.load()

    expect(changes.status).toBe('error')
    expect(changes.error).toMatch(/^500/)
  })

  it('keeps the last answer when a refresh fails', async () => {
    const changes = useChangesStore()
    await changes.load()

    fetch.mockImplementationOnce(() => Promise.reject(new Error('offline')))

    expect(await changes.refresh()).toBe(false)
    expect(changes.file.changes).toHaveLength(1)
    expect(changes.status).toBe('ready')
  })

  it('asks for the baseline somebody picked', async () => {
    const changes = useChangesStore()
    await changes.load()

    await changes.compareWith('snapshot')

    expect(lastUrl(fetch)).toBe('/dissect/changes.json?against=snapshot')
  })

  it('starts watching with the CSRF token, then compares against it', async () => {
    const changes = useChangesStore()
    await changes.load()

    fetch.mockImplementationOnce(() => json({ ok: true }))
    fetch.mockImplementationOnce(() => json(answer({ against: 'watch', changes: [] })))

    await changes.startWatching()

    const [url, init] = fetch.mock.calls[1] ?? []
    expect(url).toBe('/dissect/changes/watch')
    expect(init?.method).toBe('POST')
    expect(init?.headers).toMatchObject({ 'x-csrf-token': 'token' })
    expect(fetch.mock.calls[2]?.[0]).toBe('/dissect/changes.json?against=watch')
    expect(changes.file.against).toBe('watch')
    expect(changes.busy).toBe(false)
    expect(changes.actionError).toBeNull()
  })

  it('stops watching and goes back to what the server prefers', async () => {
    const changes = useChangesStore()
    await changes.startWatching()

    await changes.stopWatching()

    const stop = fetch.mock.calls.find(([, init]) => init?.method === 'DELETE')
    expect(stop?.[0]).toBe('/dissect/changes/watch')
    expect(changes.chosen).toBeNull()
    expect(lastUrl(fetch)).toBe('/dissect/changes.json')
  })

  it("says the server's reason when watching cannot start", async () => {
    const changes = useChangesStore()
    fetch.mockImplementationOnce(() =>
      json({ ok: false, message: 'Cannot write storage/dissect/watch.json.' }, 500),
    )

    await changes.startWatching()

    expect(changes.actionError).toBe('Cannot write storage/dissect/watch.json.')
    expect(changes.busy).toBe(false)
    // Nothing is being watched, so nothing is compared against it.
    expect(changes.chosen).toBeNull()
    expect(fetch).toHaveBeenCalledTimes(1)
  })
})
