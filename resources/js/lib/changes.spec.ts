import { describe, expect, it } from 'vitest'
import {
  canOpen,
  factKey,
  groupByPart,
  headline,
  severityColor,
  since,
  splitMark,
  SEVERITIES,
} from '@/lib/changes'
import type { Change, ChangesFile, Severity } from '@/types/changes'

function change(
  part: Change['part'],
  id: string,
  severity: Severity,
  kind: Change['change'] = 'changed',
): Change {
  return { part, id, name: id, change: kind, severity, text: `~ ${id}`, facts: [] }
}

function file(changes: Change[], against: ChangesFile['against'] = 'snapshot'): ChangesFile {
  const summary = { breaking: 0, risky: 0, additive: 0 }
  for (const c of changes) summary[c.severity]++

  return {
    against,
    baselines: {
      watch: { exists: against === 'watch', path: 'storage/dissect/watch.json', taken_at: null },
      snapshot: { exists: true, path: '.dissect/snapshot.json', taken_at: null },
    },
    problem: null,
    summary,
    changes,
  }
}

const all = new Set<Severity>(SEVERITIES)

describe('groupByPart', () => {
  it('groups in sidebar order and keeps the server order within a part', () => {
    const groups = groupByPart(
      [
        change('providers', 'P', 'risky'),
        change('routes', 'GET:b', 'breaking'),
        change('models', 'M', 'additive'),
        change('routes', 'GET:a', 'risky'),
      ],
      all,
    )

    expect(groups.map((g) => g.part)).toEqual(['models', 'routes', 'providers'])
    expect(groups[1]!.changes.map((c) => c.id)).toEqual(['GET:b', 'GET:a'])
    expect(groups[2]!.label).toBe('Service providers')
  })

  it('leaves out the severities not shown, and a part left empty by that', () => {
    const groups = groupByPart(
      [change('models', 'M', 'additive'), change('routes', 'GET:a', 'breaking')],
      new Set<Severity>(['breaking']),
    )

    expect(groups.map((g) => g.part)).toEqual(['routes'])
  })
})

describe('headline', () => {
  it('names the count, the baseline and the worst kind present', () => {
    expect(
      headline(file([change('models', 'M', 'risky'), change('jobs', 'J', 'breaking')], 'watch')),
    ).toBe('2 changes since watching started · 1 breaking')
    expect(headline(file([change('models', 'M', 'additive')]))).toBe(
      '1 change since the snapshot · 1 additive',
    )
  })

  it('says when nothing changed, and when there is nothing to compare with', () => {
    expect(headline(file([]))).toBe('Nothing changed since the snapshot')
    expect(headline(file([], null))).toBe('What changed')
  })

  it('does not say nothing changed when the baseline could not be read', () => {
    const problem = 'The snapshot at .dissect/snapshot.json is in snapshot format 1.'

    expect(headline({ ...file([]), problem })).toBe('Could not read the snapshot')
    expect(headline({ ...file([], 'watch'), problem })).toBe(
      'Could not read where watching started',
    )
  })
})

describe('the rest', () => {
  it('words a baseline', () => {
    expect(since('watch')).toBe('since watching started')
    expect(since(null)).toBe('')
  })

  it('does not offer to open what was removed', () => {
    expect(canOpen(change('models', 'M', 'breaking', 'removed'))).toBe(false)
    expect(canOpen(change('models', 'M', 'additive', 'added'))).toBe(true)
  })

  it('gives every severity its own colour', () => {
    expect(new Set(SEVERITIES.map(severityColor)).size).toBe(3)
  })

  it('splits the mark off the server text', () => {
    expect(splitMark('+ column refunded_at (datetime)')).toEqual({
      mark: '+',
      rest: 'column refunded_at (datetime)',
    })
    expect(splitMark('~ status 200 → 201')).toEqual({ mark: '~', rest: 'status 200 → 201' })
    expect(splitMark('plain')).toEqual({ mark: '', rest: 'plain' })
  })
})

describe('factKey', () => {
  it('tells apart two values added to one list', () => {
    const fact = (after: string) => ({
      path: ['middleware'],
      change: 'added' as const,
      before: null,
      after,
      severity: 'risky' as const,
      text: `+ middleware ${after}`,
    })

    expect(factKey(fact('auth'))).not.toBe(factKey(fact('throttle')))
    expect(factKey(fact('auth'))).toBe(factKey(fact('auth')))
  })
})
