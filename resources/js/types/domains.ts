/**
 * Shape of domains.json, as exported from the Laravel side.
 *
 * Fetched only when the domain list is opened: building it reads every PHP
 * file under the application's source folders. Every other surface carries
 * its own rows' domain as `context`, so nothing else needs this payload.
 */

/** How dissect learned a domain exists. Mirrors the ContextSource enum. */
export type DomainSource =
  | 'config'
  | 'attribute'
  | 'modules'
  | 'app-modules'
  | 'domain-folder'
  | 'hexagonal'
  | 'layer-folders'
  | 'packages'

export interface Domain {
  name: string
  /** Every way it was found; one domain can be a folder and a domains.json entry. */
  sources: (DomainSource | string)[]
  /** Folders (or single files) that belong to it, relative to the app root. */
  roots: string[]
  /** Full class names, sorted. */
  classes: string[]
}

/**
 * Something about a class's domain worth a second look: an attribute that
 * disagrees with its folder, or one naming a domain nothing else defines.
 */
export interface DomainFinding {
  kind: 'override-disagrees' | 'unknown-domain' | string
  class: string
  file: string
  /** The domain the attribute puts the class in. */
  context: string
  /** The domain its folder alone would give it, if any. */
  folder: string | null
  message: string
}

export interface DomainsFile {
  /** False when `domains.enabled` is off in config. */
  enabled: boolean
  domains: Domain[]
  /** Classes no domain claims, never guessed into one. */
  unassigned: string[]
  findings: DomainFinding[]
  /** What is wrong with `.dissect/domains.json`, in words. */
  problems: string[]
  generated_at?: string
}
