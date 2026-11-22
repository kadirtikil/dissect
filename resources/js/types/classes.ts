/**
 * Shape of classes.json, as exported from the Laravel side.
 *
 * Every class, interface, trait and enum in the application, and every other
 * one of them it names. Only references between the app's own classes are
 * listed: what it uses from the framework and packages is most of every file,
 * and not what a boundary between two of its domains is about.
 */

/**
 * How one class names another. The middle four are what the syntax means in
 * a Laravel app; the rest are what it says. Mirrors References::KINDS.
 */
export type ReferenceKind =
  | 'extends'
  | 'implements'
  | 'trait'
  | 'relation'
  | 'dispatch'
  | 'event'
  | 'binding'
  | 'new'
  | 'static'
  | 'type'
  | 'instanceof'
  | 'catch'
  | 'attribute'

export interface ClassUse {
  /** Full name of the class referred to. */
  to: string
  kind: ReferenceKind | string
  /** Every line in the referring file where it happens, sorted. */
  lines: number[]
}

export interface ClassEntry {
  /** Full class name. */
  id: string
  kind: 'class' | 'interface' | 'trait' | 'enum' | string
  abstract: boolean
  /** Relative to the app root. */
  file: string
  line: number
  /** Its domain, or null when it is in none. */
  context: string | null
  uses: ClassUse[]
}

export interface ClassesFile {
  classes: ClassEntry[]
  /** Files that could not be parsed, so a count can say what it skipped. */
  unreadable: string[]
  generated_at?: string
}
