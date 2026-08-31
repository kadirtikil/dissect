import { describe, expect, it } from 'vitest'
import { absolute, isPast, relative } from '@/lib/elapsed'

// A fixed "now", so nothing here depends on when it runs.
const NOW = new Date('2026-08-31T12:00:00Z').getTime()

function at(offsetSeconds: number): string {
  return new Date(NOW + offsetSeconds * 1000).toISOString()
}

describe('relative', () => {
  it('reads backwards for the past and forwards for the future', () => {
    expect(relative(at(-120), NOW)).toBe('2m ago')
    expect(relative(at(600), NOW)).toBe('in 10m')
  })

  it('drops the direction when it is noise', () => {
    expect(relative(at(-5), NOW)).toBe('just now')
    expect(relative(at(5), NOW)).toBe('just now')
  })

  it('steps up through the units, one at a time', () => {
    // A queue is scanned, not studied: `2h`, never `2h 14m`.
    expect(relative(at(-45), NOW)).toBe('45s ago')
    expect(relative(at(-90 * 60), NOW)).toBe('2h ago')
    expect(relative(at(-3 * 86400), NOW)).toBe('3d ago')
  })

  it('is null when there is no time to describe', () => {
    // A payload queued before Laravel stamped them has no queued-at, and a
    // placeholder in a column of times reads as a value.
    expect(relative(null, NOW)).toBeNull()
    expect(relative(undefined, NOW)).toBeNull()
    expect(relative('not a date', NOW)).toBeNull()
  })
})

describe('isPast', () => {
  it('separates a moment that has arrived from one that has not', () => {
    expect(isPast(at(-1), NOW)).toBe(true)
    expect(isPast(at(60), NOW)).toBe(false)
  })

  it('is false for a time it cannot read', () => {
    expect(isPast(null, NOW)).toBe(false)
  })
})

describe('absolute', () => {
  it('is null rather than Invalid Date', () => {
    expect(absolute('nonsense')).toBeNull()
    expect(absolute(at(0))).not.toBeNull()
  })
})
