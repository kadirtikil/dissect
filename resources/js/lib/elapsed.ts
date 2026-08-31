/**
 * How long ago, or how long until.
 *
 * A queue is read in relative time — "waiting 3m", "runs in 2h" — because the
 * question is always about the gap rather than the clock. The absolute time
 * stays available as a title attribute, since the moment something was queued
 * is what you need once you go looking in a log.
 *
 * Pure, and takes `now` as an argument, so it can be tested without freezing a
 * clock.
 */

const MINUTE = 60
const HOUR = 60 * MINUTE
const DAY = 24 * HOUR

/**
 * `2m ago`, `in 4h`, `just now` — or null when there is no time to describe.
 *
 * Null rather than a placeholder: a job queued by a Laravel older than the one
 * that started stamping payloads genuinely has no queued-at, and inventing
 * "unknown" in the middle of a column of times reads as a value.
 */
export function relative(iso: string | null | undefined, now: number = Date.now()): string | null {
  const at = parse(iso)
  if (at === null) return null

  const seconds = Math.round((at - now) / 1000)
  const magnitude = Math.abs(seconds)

  // Under half a minute either way, the direction is noise.
  if (magnitude < 30) return 'just now'

  const amount = format(magnitude)

  return seconds < 0 ? `${amount} ago` : `in ${amount}`
}

/** The absolute time, for a tooltip. Null when it cannot be read. */
export function absolute(iso: string | null | undefined): string | null {
  const at = parse(iso)

  return at === null ? null : new Date(at).toLocaleString()
}

/**
 * Whether a moment has passed — which is what separates a delayed job that is
 * merely waiting from one whose worker is behind.
 */
export function isPast(iso: string | null | undefined, now: number = Date.now()): boolean {
  const at = parse(iso)

  return at !== null && at <= now
}

/** One unit, never two: `2h`, not `2h 14m`. A queue is scanned, not studied. */
function format(seconds: number): string {
  if (seconds < MINUTE) return `${seconds}s`
  if (seconds < HOUR) return `${Math.round(seconds / MINUTE)}m`
  if (seconds < DAY) return `${Math.round(seconds / HOUR)}h`

  return `${Math.round(seconds / DAY)}d`
}

function parse(iso: string | null | undefined): number | null {
  if (!iso) return null

  const at = new Date(iso).getTime()

  return Number.isNaN(at) ? null : at
}
