import { describe, expect, it } from 'vitest'
import type { ClassEntry } from '@/types/classes'
import { dependenciesOf, domainDependencies, isMutual } from '@/lib/dependencies'

function cls(id: string, context: string | null, uses: ClassEntry['uses'] = []): ClassEntry {
  return { id, kind: 'class', abstract: false, file: `${id}.php`, line: 1, context, uses }
}

const CLASSES: ClassEntry[] = [
  cls('Invoice', 'Billing', [
    { to: 'Customer', kind: 'relation', lines: [15] },
    { to: 'Payment', kind: 'relation', lines: [20] },
  ]),
  cls('Payment', 'Billing', [{ to: 'Invoice', kind: 'relation', lines: [12] }]),
  cls('SendInvoice', 'Billing', [
    { to: 'Customer', kind: 'type', lines: [9, 14] },
    { to: 'Quote', kind: 'new', lines: [22] },
    { to: 'Clock', kind: 'static', lines: [30] },
  ]),
  cls('Customer', 'Sales', [{ to: 'Invoice', kind: 'relation', lines: [19] }]),
  cls('Quote', 'Sales'),
  cls('User', 'Identity'),
  cls('Clock', null, [{ to: 'Quote', kind: 'type', lines: [3] }]),
]

describe('domainDependencies', () => {
  const all = domainDependencies(CLASSES)

  it('counts one reference per class pair and kind, by kind', () => {
    const billing = dependenciesOf(all, 'Billing')

    expect(billing.dependsOn).toHaveLength(1)
    expect(billing.dependsOn[0]).toMatchObject({
      domain: 'Sales',
      count: 3,
      kinds: [
        ['new', 1],
        ['relation', 1],
        ['type', 1],
      ],
    })
  })

  it('lists every reference behind a link, with where it is', () => {
    const [link] = dependenciesOf(all, 'Billing').dependsOn

    expect(link!.references.map((r) => `${r.from} → ${r.to} ${r.kind}`)).toEqual([
      'Invoice → Customer relation',
      'SendInvoice → Customer type',
      'SendInvoice → Quote new',
    ])
    expect(link!.references[1]).toMatchObject({ file: 'SendInvoice.php', lines: [9, 14] })
  })

  it('shows the same link from the other side', () => {
    const sales = dependenciesOf(all, 'Sales')

    expect(sales.usedBy.map((l) => [l.domain, l.count])).toEqual([['Billing', 3]])
    expect(sales.dependsOn.map((l) => [l.domain, l.count])).toEqual([['Billing', 1]])
  })

  it('ignores references inside a domain and to or from a class in none', () => {
    // Invoice ↔ Payment stays in Billing; SendInvoice → Clock and Clock → Quote
    // touch a class in no domain.
    const references = [...all.values()].flatMap((d) => d.dependsOn.flatMap((l) => l.references))

    expect(references.some((r) => r.to === 'Payment' || r.to === 'Clock')).toBe(false)
    expect(references.some((r) => r.from === 'Clock')).toBe(false)
  })

  it('gives a domain with no links none', () => {
    expect(dependenciesOf(all, 'Identity')).toEqual({ dependsOn: [], usedBy: [] })
  })

  it('sorts links most first, then by name', () => {
    const many = domainDependencies([
      cls('A', 'Hub', [
        { to: 'B', kind: 'type', lines: [1] },
        { to: 'C', kind: 'type', lines: [1] },
        { to: 'C', kind: 'new', lines: [2] },
        { to: 'D', kind: 'type', lines: [1] },
      ]),
      cls('B', 'Zeta'),
      cls('C', 'Yankee'),
      cls('D', 'Alpha'),
    ])

    expect(dependenciesOf(many, 'Hub').dependsOn.map((l) => l.domain)).toEqual([
      'Yankee',
      'Alpha',
      'Zeta',
    ])
  })

  it('knows when two domains name each other', () => {
    expect(isMutual(dependenciesOf(all, 'Billing'), 'Sales')).toBe(true)
    expect(isMutual(dependenciesOf(all, 'Billing'), 'Identity')).toBe(false)
  })
})
