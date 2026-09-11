import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import type { QueueFile } from '@/types/queue'
import { bootstrap } from '@/lib/bootstrap'

type Status = 'idle' | 'loading' | 'ready' | 'error'

/** How often the queue is re-read while the surface is open. */
const DEFAULT_POLL_MS = 5000

/**
 * What is on the queue, right now.
 *
 * The odd one out among the stores, and deliberately so. The schema, routes and
 * jobs all describe code: they are fetched once, cached behind a fingerprint,
 * and refreshed when a file changes. This describes runtime state, so there is
 * nothing to cache and no signal to poll *against* — the payload itself is the
 * only answer, and it is re-read on a timer for as long as somebody is looking
 * at it.
 *
 * A failed poll leaves the last good reading on screen. The same bargain the
 * other stores make, and it matters more here: a queue surface that blanked
 * every time a request was slow would be unreadable exactly when a queue is
 * busiest.
 */
export const useQueueStore = defineStore('queue', () => {
  const status = ref<Status>('idle')
  const error = ref<string | null>(null)

  // shallowRef: replaced wholesale on every read, never mutated in place.
  const snapshot = shallowRef<QueueFile | null>(null)

  /** Which connection is being read. Null means whatever the app defaults to. */
  const connection = ref<string | null>(null)

  /** Set while a poll is in flight, so the header can say it is working. */
  const reading = ref(false)

  /** True once a first reading has landed, whatever happens afterwards. */
  const loaded = computed(() => snapshot.value !== null)

  const readable = computed(() => snapshot.value?.readable ?? false)

  const depths = computed(() => snapshot.value?.queues ?? [])

  /**
   * The three tenses, in the order somebody asks about a queue.
   *
   * Assembled here rather than in the template so the page is a list of
   * sections rather than a hard-coded arrangement of five of them.
   */
  const totals = computed(() => ({
    waiting: snapshot.value?.now.waiting.total ?? 0,
    reserved: snapshot.value?.now.reserved.total ?? 0,
    delayed: snapshot.value?.next.delayed.total ?? 0,
    failed: snapshot.value?.past.failed.total ?? 0,
  }))

  function endpoint(): string {
    const base = bootstrap().queueUrl ?? `${import.meta.env.BASE_URL}queue.json`

    if (connection.value === null) return base

    // A plain query string: the endpoint takes one parameter and the base may
    // already carry a mount prefix, which URL() would need an origin to parse.
    return `${base}${base.includes('?') ? '&' : '?'}connection=${encodeURIComponent(connection.value)}`
  }

  /**
   * Reads the queue once.
   *
   * Unlike the other stores this is not a "load it if you have not already":
   * every call is a fresh reading, because that is the only kind there is.
   */
  async function read(): Promise<boolean> {
    if (reading.value) return false

    reading.value = true

    if (status.value === 'idle') status.value = 'loading'

    try {
      const res = await fetch(endpoint(), { cache: 'no-store' })
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`)

      const payload = (await res.json()) as QueueFile
      if (typeof payload?.connection !== 'string') {
        throw new Error('Expected a queue snapshot')
      }

      snapshot.value = payload
      status.value = 'ready'
      error.value = null

      return true
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      // Only the very first failure is allowed to become an error screen. After
      // that the last good reading stays up and the poller keeps trying.
      if (!loaded.value) status.value = 'error'

      return false
    } finally {
      reading.value = false
    }
  }

  /** Switch connection and read it immediately — waiting for the next tick
   *  would make the control feel broken. */
  async function use(next: string | null): Promise<void> {
    connection.value = next
    await read()
  }

  /**
   * Polls for as long as the surface is open.
   *
   * Returns a stop function, called on unmount: a queue nobody is looking at is
   * a request nobody asked for, and this endpoint does real work on every one.
   * Paused while the tab is hidden, and read immediately when it comes back —
   * the same courtesy the schema poller extends.
   */
  function watchQueue(intervalMs = pollInterval()): () => void {
    let timer: ReturnType<typeof setInterval> | undefined

    const tick = () => {
      if (!document.hidden) void read()
    }

    const onVisible = () => {
      if (!document.hidden) void read()
    }

    timer = setInterval(tick, intervalMs)
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      clearInterval(timer)
      timer = undefined
      document.removeEventListener('visibilitychange', onVisible)
    }
  }

  function pollInterval(): number {
    const configured = bootstrap().queuePollInterval

    // Clamped: a value that polls a queue table several times a second is a
    // load-testing tool, not a viewer.
    return typeof configured === 'number' && configured >= 1000 ? configured : DEFAULT_POLL_MS
  }

  return {
    status,
    error,
    snapshot,
    connection,
    reading,
    loaded,
    readable,
    depths,
    totals,
    read,
    use,
    watchQueue,
    pollInterval,
  }
})
