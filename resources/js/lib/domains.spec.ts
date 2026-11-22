import { describe, expect, it } from 'vitest'
import {
  UNASSIGNED,
  contextKey,
  domainColor,
  domainPalette,
  matchesContexts,
  namespaceOf,
  sourceLabel,
} from '@/lib/domains'

describe('domainColor', () => {
  const names = ['Billing', 'Catalog', 'Identity', 'Sales', 'Shipping', 'Support', 'Tax']

  it('takes the categorical ramp in list order', () => {
    expect(domainColor('Billing', names)).toBe('var(--chart-1)')
    expect(domainColor('Shipping', names)).toBe('var(--chart-5)')
  })

  it('spreads further domains around the wheel, all different', () => {
    const extra = [domainColor('Support', names), domainColor('Tax', names)]

    expect(extra[0]).toMatch(/^oklch\(/)
    expect(extra[0]).not.toBe(extra[1])
  })

  it('is muted for a name that is not a domain', () => {
    expect(domainColor('Nope', names)).toBe('var(--muted-foreground)')
  })
})

describe('words', () => {
  it('says how a domain was found', () => {
    expect(sourceLabel('config')).toBe('domains.json')
    expect(sourceLabel('something-new')).toBe('something-new')
  })

  it("finds a class name's namespace", () => {
    expect(namespaceOf('App\\Domain\\Billing\\Invoice')).toBe('App\\Domain\\Billing')
    expect(namespaceOf('Invoice')).toBe('')
  })
})

describe('domainPalette', () => {
  it('keeps the names the page was given first, in their order', () => {
    expect(domainPalette(['Billing', 'Sales'], ['Sales'])).toEqual(['Billing', 'Sales'])
  })

  it('adds names seen since after them, sorted, once each', () => {
    expect(domainPalette(['Sales'], ['Tax', 'Accounting', 'Tax', 'Sales'])).toEqual([
      'Sales',
      'Accounting',
      'Tax',
    ])
  })

  it('never gives the unassigned bucket a colour', () => {
    expect(domainPalette([], [UNASSIGNED, 'Billing'])).toEqual(['Billing'])
  })
})

describe('matchesContexts', () => {
  it('matches everything when nothing is selected', () => {
    expect(matchesContexts(null, [])).toBe(true)
    expect(matchesContexts('Billing', [])).toBe(true)
  })

  it('matches a row in any selected domain', () => {
    expect(matchesContexts('Billing', ['Sales', 'Billing'])).toBe(true)
    expect(matchesContexts('Billing', ['Sales'])).toBe(false)
  })

  it('treats a missing context as unassigned', () => {
    expect(contextKey(undefined)).toBe(UNASSIGNED)
    expect(matchesContexts(undefined, [UNASSIGNED])).toBe(true)
    expect(matchesContexts('Billing', [UNASSIGNED])).toBe(false)
  })
})
