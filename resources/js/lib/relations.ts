/**
 * Eloquent has ~11 relation types, which is more than a categorical palette can
 * carry (hues are assigned in fixed order and never cycled). So types are folded
 * into four semantic families and colour is assigned per family.
 *
 * Colour is never the only cue: polymorphic edges are also dashed, every edge
 * carries its relation name as a label, and the legend is always on screen.
 */

export type RelationFamily = 'has' | 'belongsTo' | 'manyToMany' | 'polymorphic'

interface FamilySpec {
  id: RelationFamily
  label: string
  /** Design token; resolved against the active theme at render time. */
  color: string
  dashed: boolean
}

// Fixed order — index maps to --chart-N and must not be reshuffled.
export const RELATION_FAMILIES: FamilySpec[] = [
  { id: 'has', label: 'Has one / many', color: 'var(--chart-1)', dashed: false },
  { id: 'belongsTo', label: 'Belongs to', color: 'var(--chart-3)', dashed: false },
  { id: 'manyToMany', label: 'Many to many', color: 'var(--chart-4)', dashed: false },
  { id: 'polymorphic', label: 'Polymorphic', color: 'var(--chart-2)', dashed: true },
]

const TYPE_TO_FAMILY: Record<string, RelationFamily> = {
  HasOne: 'has',
  HasMany: 'has',
  HasOneThrough: 'has',
  HasManyThrough: 'has',
  BelongsTo: 'belongsTo',
  BelongsToMany: 'manyToMany',
  MorphToMany: 'manyToMany',
  MorphedByMany: 'manyToMany',
  MorphOne: 'polymorphic',
  MorphMany: 'polymorphic',
  MorphTo: 'polymorphic',
}

const FALLBACK: FamilySpec = {
  id: 'has',
  label: 'Other',
  color: 'var(--muted-foreground)',
  dashed: false,
}

/** Unknown relation types degrade to a neutral stroke rather than throwing. */
export function familyFor(type: string): FamilySpec {
  const id = TYPE_TO_FAMILY[type]
  return RELATION_FAMILIES.find((f) => f.id === id) ?? FALLBACK
}
