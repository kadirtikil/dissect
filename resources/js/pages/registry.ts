import type { Component } from 'vue'
import { Boxes, Route } from '@lucide/vue'

/**
 * Every surface dissect has, in the order the sidebar lists them.
 *
 * This is the one place a new one is added: write the page, add an entry, and
 * the sidebar, the navigation store and the shell all pick it up. None of them
 * knows any page by name.
 */
export type PageId = 'models' | 'routes'

export interface PageEntry {
  id: PageId
  label: string
  icon: Component
  /**
   * True for a page the shell keeps alive for the whole session rather than
   * mounting through this registry.
   *
   * Only the graph, and not for its viewport's sake alone: it starts the change
   * poller, which also refreshes the routes surface. Mount it lazily like
   * everything else and live updates on another page quietly stop working until
   * somebody visits the graph. The shell imports these statically — see App.vue.
   */
  persistent?: boolean
  /**
   * Lazily imported, so a page nobody opens costs nothing but an unfetched
   * chunk. Absent on a `persistent` entry, which the shell holds directly.
   */
  component?: () => Promise<{ default: Component }>
}

export const pages: readonly PageEntry[] = [
  { id: 'models', label: 'Models', icon: Boxes, persistent: true },
  {
    id: 'routes',
    label: 'Routes',
    icon: Route,
    component: () => import('@/components/RoutesPanel.vue'),
  },
]

export function isPageId(value: unknown): value is PageId {
  return pages.some((page) => page.id === value)
}
