/**
 * Where a page hangs its own header controls.
 *
 * The shell owns the header; a page owns what goes in it. Without this the
 * shell has to know every page's controls by name — which is what turned the
 * header into a wall of `v-if="page === 'models'"`, and what would have made
 * adding a third surface an edit to a fourth file. A page teleports its
 * controls into one of these regions instead, and the shell never learns they
 * exist.
 *
 * Exported as constants rather than written as selectors at each end, so the
 * two halves of the contract are findable from each other.
 *
 * Teleport into these with `defer`. Both regions are rendered by the same app
 * as the pages that fill them, and on first mount Vue builds the whole tree
 * detached before inserting it — so a page mounting inside `<main>` looks for a
 * header that is not in the document yet, and silently teleports nothing.
 * `defer` resolves the target after the render pass instead.
 */

/**
 * Beside the page's own name: what you are looking at. Counts, the active view,
 * anything that describes the surface rather than acting on it.
 */
export const PAGE_CONTEXT = '#dissect-page-context'

/**
 * Beside the global controls, at the far end: what you can do to the surface.
 * A page uses either region, both, or neither.
 */
export const PAGE_ACTIONS = '#dissect-page-actions'
