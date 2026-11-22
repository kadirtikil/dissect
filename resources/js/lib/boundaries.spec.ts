import { describe, expect, it } from 'vitest'
import type { BoundaryRule, Violation } from '@/types/boundaries'
import { allows, describeRule, ruleFor, violationsByDomain } from '@/lib/boundaries'

const RULES: BoundaryRule[] = [
  { domain: 'Billing', may_use: ['Shared'], must_not_use: ['Illuminate\\Http'] },
  { domain: 'Sales', may_use: [], must_not_use: [] },
  { domain: 'Shipping', may_use: null, must_not_use: ['App\\Legacy'] },
]

describe('allows', () => {
  it('follows may_use, and always allows a domain itself', () => {
    const billing = ruleFor(RULES, 'Billing')

    expect(allows(billing, 'Billing', 'Shared')).toBe(true)
    expect(allows(billing, 'Billing', 'Billing')).toBe(true)
    expect(allows(billing, 'Billing', 'Sales')).toBe(false)
  })

  it('forbids every other domain with an empty list', () => {
    expect(allows(ruleFor(RULES, 'Sales'), 'Sales', 'Billing')).toBe(false)
  })

  it('allows anything without may_use, or without a rule', () => {
    expect(allows(ruleFor(RULES, 'Shipping'), 'Shipping', 'Billing')).toBe(true)
    expect(ruleFor(RULES, 'Identity')).toBeNull()
    expect(allows(null, 'Identity', 'Billing')).toBe(true)
  })
})

describe('describeRule', () => {
  it('reads like the digest', () => {
    expect(describeRule(RULES[0]!)).toBe('may use Shared; must not use Illuminate\\Http')
    expect(describeRule(RULES[1]!)).toBe('may use no other domain')
    expect(describeRule(RULES[2]!)).toBe('must not use App\\Legacy')
    expect(describeRule({ domain: 'X', may_use: null, must_not_use: [] })).toBe('an empty rule')
  })
})

describe('violationsByDomain', () => {
  it('groups by the domain whose rule is broken, keeping order', () => {
    const v = (domain: string, file: string): Violation => ({
      rule: 'may_use',
      domain,
      from: 'A',
      file,
      to: 'B',
      to_domain: 'X',
      namespace: null,
      kind: 'type',
      lines: [1],
      message: '',
    })

    const grouped = violationsByDomain([
      v('Sales', 'a.php'),
      v('Billing', 'b.php'),
      v('Sales', 'c.php'),
    ])

    expect([...grouped.keys()]).toEqual(['Sales', 'Billing'])
    expect(grouped.get('Sales')!.map((x) => x.file)).toEqual(['a.php', 'c.php'])
  })
})
