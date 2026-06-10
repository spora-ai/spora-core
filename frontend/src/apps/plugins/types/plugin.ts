/**
 * Resource shape for GET /api/v1/plugins. Mirrors PluginsService on the
 * backend — keep both in sync when adding fields.
 */

export type MigrationStatus = 'no_migrations' | 'up_to_date' | 'pending_migrations'

export interface BundledTool {
  /** snake_case tool name. The orchestrator prefixes the plugin slug at runtime. */
  name: string
  description: string
}

export interface BundledDriver {
  /** Provider key, e.g. "anthropic", "openai" */
  provider: string
  class: string
}

export interface MigrationInfo {
  /** Plugin-declared schema version (the integer hard-coded in PluginInterface::schemaVersion()) */
  declared: number
  /** Count of slug-prefixed migration files recorded in the `migrations` table */
  applied: number
  /** Count of slug-prefixed migration files present on disk */
  filesOnDisk: number
  /** filesOnDisk − applied, clamped to 0 */
  pending: number
  /** SQL-style Y-m-d H:i:s timestamp from `schema_versions.updated_at`, or null if never applied */
  lastAppliedAt: string | null
  status: MigrationStatus
}

export interface PluginResource {
  slug: string
  name: string
  description: string
  version: number
  /** Absolute filesystem path to the plugin directory, or null when loaded from a sidecar without a recorded directory. */
  path: string | null
  bundledTools: BundledTool[]
  bundledDrivers: BundledDriver[]
  recipePaths: string[]
  migrations: MigrationInfo
}
