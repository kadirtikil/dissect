import type { GraphView, Schema } from '@/types/schema'
import type { NodePosition } from '@/stores/layout'

/**
 * Data the Laravel package inlines into the Blade view.
 *
 * The app has two hosts: the package (which injects everything up front, so the
 * page needs no round trips) and the standalone Vite dev server (where nothing
 * is injected and the JSON files are fetched instead). Everything here is
 * optional so the same bundle serves both.
 */
export interface Bootstrap {
  schema?: Schema
  layout?: { version: number; positions: Record<string, NodePosition> }
  views?: { version: number; views: GraphView[] }
  /** Endpoint that persists positions. */
  saveUrl?: string
  /** Endpoint that persists saved views. */
  saveViewsUrl?: string
  /** Endpoint the schema can be re-fetched from once it goes stale. */
  schemaUrl?: string
  /** Endpoint returning the current change signal — see stores/schema.ts. */
  fingerprintUrl?: string
  /** The signal as of page render; polling compares against this. */
  fingerprint?: string
  /** Laravel CSRF token, required for the POST above. */
  csrfToken?: string
}

declare global {
  interface Window {
    __DISSECT__?: Bootstrap
  }
}

export function bootstrap(): Bootstrap {
  return window.__DISSECT__ ?? {}
}

/** True when running under the package rather than the standalone dev server. */
export function isEmbedded(): boolean {
  return bootstrap().schema !== undefined
}
