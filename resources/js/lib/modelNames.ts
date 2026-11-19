/**
 * What a model is called on screen.
 *
 * A model's id is its fully qualified class name — the only name two models
 * cannot share. That is right for keys and wrong for a card title, so this
 * turns ids back into something readable: the class name alone, or as much of
 * the namespace as it takes to tell two models apart.
 */

/** `App\Models\Billing\Invoice` → `Invoice`. */
export function baseName(id: string): string {
  const at = id.lastIndexOf('\\')
  return at === -1 ? id : id.slice(at + 1)
}

/**
 * The shortest trailing part of each id that no other id shares.
 *
 * `App\Models\Post` stays `Post`. `App\Billing\Invoice` beside
 * `App\Sales\Invoice` become `Billing\Invoice` and `Sales\Invoice`, so the
 * graph never shows two cards with the same title. Only colliding names grow,
 * one namespace segment at a time.
 */
export function shortNames(ids: Iterable<string>): Map<string, string> {
  const names = new Map<string, string>()
  settle([...new Set(ids)], 1, names)
  return names
}

function settle(ids: string[], depth: number, names: Map<string, string>) {
  const groups = new Map<string, string[]>()

  for (const id of ids) {
    const tail = id.split('\\').slice(-depth).join('\\')
    groups.set(tail, [...(groups.get(tail) ?? []), id])
  }

  for (const [tail, group] of groups) {
    // Alone at this depth, or out of segments to add (two ids that are equal
    // all the way up cannot be told apart by any suffix).
    const exhausted = group.every((id) => id.split('\\').length <= depth)

    if (group.length === 1 || exhausted) {
      for (const id of group) names.set(id, tail)
    } else {
      settle(group, depth + 1, names)
    }
  }
}
