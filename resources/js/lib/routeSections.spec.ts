import { describe, expect, it } from 'vitest'
import type { RouteSection } from '@/lib/routeSections'
import { sectionColor, sectionSpec, sectionSurface } from '@/lib/routeSections'

const ALL: RouteSection[] = ['endpoint', 'middleware', 'parameters', 'request', 'response']

describe('sectionColor', () => {
  it('gives every section its own colour', () => {
    // Two blocks sharing a colour would defeat the point of tinting them.
    const colors = ALL.map(sectionColor)

    expect(new Set(colors).size).toBe(ALL.length)
  })

  it('answers the same way however often it is asked', () => {
    // The guarantee the pane is built on: selecting a different endpoint
    // re-renders these sections, and Request has to come back the same blue
    // every time or the pane stops being readable by shape.
    for (const section of ALL) {
      expect(sectionColor(section)).toBe(sectionColor(section))
      expect(sectionSurface(section)).toEqual(sectionSurface(section))
    }
  })

  it('depends on the section alone, never on a route', () => {
    // sectionColor takes one argument and it is not an endpoint. Stated as a
    // test because "colour by role, not by selection" is the rule a later
    // change is most likely to break by threading a route through here.
    expect(sectionColor.length).toBe(1)
    expect(sectionSurface.length).toBe(1)
  })

  it('draws from the shared token ramp rather than hard-coded colour', () => {
    // Tokens resolve against the active theme, so the pane follows light and
    // dark without a second palette to keep in step.
    for (const section of ALL) {
      expect(sectionColor(section)).toMatch(/^var\(--chart-[1-5]\)$/)
    }
  })
})

describe('sectionSurface', () => {
  it('builds the wash and the edge from that section’s own colour', () => {
    const surface = sectionSurface('request')
    const color = sectionColor('request')

    expect(surface.borderLeftColor).toBe(color)
    // The wash is the faintest layer, the hairline sits above it, and the left
    // edge is the token at full strength — so the three read as one block.
    expect(surface.backgroundColor).toContain(color)
    expect(surface.borderColor).toContain(color)
    expect(surface.backgroundColor).toContain('color-mix')
  })

  it('mixes against transparent so one set of numbers serves both themes', () => {
    for (const section of ALL) {
      const { backgroundColor, borderColor } = sectionSurface(section)

      expect(backgroundColor).toContain('transparent')
      expect(borderColor).toContain('transparent')
    }
  })
})

describe('sectionSpec', () => {
  it('labels every section', () => {
    for (const section of ALL) {
      expect(sectionSpec(section).label).not.toBe('')
    }
  })
})
