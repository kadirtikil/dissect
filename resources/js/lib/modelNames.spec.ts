import { describe, expect, it } from 'vitest'
import { baseName, shortNames } from '@/lib/modelNames'

describe('baseName', () => {
  it('drops the namespace', () => {
    expect(baseName('App\\Models\\Billing\\Invoice')).toBe('Invoice')
  })

  it('leaves a bare name alone', () => {
    expect(baseName('Invoice')).toBe('Invoice')
  })
})

describe('shortNames', () => {
  it('uses the class name when nothing else shares it', () => {
    const names = shortNames(['App\\Models\\Post', 'App\\Models\\Comment'])

    expect(names.get('App\\Models\\Post')).toBe('Post')
    expect(names.get('App\\Models\\Comment')).toBe('Comment')
  })

  it('adds just enough namespace to tell two models apart', () => {
    const names = shortNames([
      'App\\Domain\\Billing\\Models\\Invoice',
      'App\\Domain\\Sales\\Models\\Invoice',
      'App\\Domain\\Sales\\Models\\Order',
    ])

    expect(names.get('App\\Domain\\Billing\\Models\\Invoice')).toBe('Billing\\Models\\Invoice')
    expect(names.get('App\\Domain\\Sales\\Models\\Invoice')).toBe('Sales\\Models\\Invoice')
    // Only the collision grows.
    expect(names.get('App\\Domain\\Sales\\Models\\Order')).toBe('Order')
  })

  it('stops when a name has no namespace left to add', () => {
    const names = shortNames(['Invoice', 'Billing\\Invoice'])

    expect(names.get('Invoice')).toBe('Invoice')
    expect(names.get('Billing\\Invoice')).toBe('Billing\\Invoice')
  })

  it('works on ids that are already short, as they were before full names', () => {
    const names = shortNames(['Post', 'Comment'])

    expect([...names.values()]).toEqual(['Post', 'Comment'])
  })
})
