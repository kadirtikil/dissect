import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSchemaStore } from '@/stores/schema'

const json = (body: unknown, status = 200) =>
  Promise.resolve(new Response(JSON.stringify(body), { status }))

/** Lets the poller's check run to the end. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 30))

describe('the change poller', () => {
  let answers: Record<string, () => Promise<Response>>

  beforeEach(() => {
    setActivePinia(createPinia())
    window.__DISSECT__ = {
      fingerprintUrl: '/dissect/fingerprint',
      schemaUrl: '/dissect/schema.json',
      fingerprint: 'old',
    }
    answers = {
      '/dissect/fingerprint': () => json({ fingerprint: 'new' }),
      '/dissect/schema.json': () => json({ message: 'Server Error' }, 500),
    }
    vi.stubGlobal(
      'fetch',
      vi.fn((url: string) => answers[url.split('?')[0]!]!()),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    delete window.__DISSECT__
  })

  it('says which page could not be read again, until it can', async () => {
    const schema = useSchemaStore()
    const stop = schema.watchForChanges(10)

    // The models moved, and schema.json keeps failing: still asked, and
    // said, where it used to be asked every few seconds with nothing shown.
    await settle()
    expect(schema.stale).toEqual(['Models'])

    answers['/dissect/schema.json'] = () => json({ nodes: [], edges: [] })
    await settle()
    expect(schema.stale).toEqual([])

    stop()
  })

  it('says when the server cannot be asked at all', async () => {
    answers['/dissect/fingerprint'] = () => Promise.reject(new TypeError('Failed to fetch'))
    const schema = useSchemaStore()
    const stop = schema.watchForChanges(10)

    await settle()
    expect(schema.unreachable).toBe('the server did not answer')

    answers['/dissect/fingerprint'] = () => json({ fingerprint: 'old' }, 503)
    await settle()
    expect(schema.unreachable).toBe('the server answered 503')

    answers['/dissect/fingerprint'] = () => json({ fingerprint: 'old' })
    await settle()
    expect(schema.unreachable).toBeNull()

    stop()
  })
})
