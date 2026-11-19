import { describe, expect, it } from 'vitest'
import { domainColor, namespaceOf, sourceLabel } from '@/lib/domains'

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
