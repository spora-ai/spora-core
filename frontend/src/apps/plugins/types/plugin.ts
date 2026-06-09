/**
 * Resource shape for GET /api/v1/plugins.
 *
 * One PluginResource per loaded plugin. Mirrors the backend PluginsService
 * exactly — keep these in sync when adding fields.
 */

export type MigrationStatus = 'no_migrations' | 'up_to_date' | 'pending_migrations'

export interface BundledTool {
  /** snake_case tool name (unprefixed; the orchestrator adds the slug prefix) */
  name: string
  description: string
}

export interface BundledDriver {
  /** Provider key, e.g. "anthropic", "openai" */
  provider: string
  /** Fully-qualified class name of the LLM driver implementation */
  class: string
}

export interface MigrationInfo {
  /** Plugin-declared schema version (the integer the plugin author hard-codes) */
  declared: number
  /** Count of slug-prefixed migration files already recorded in the `migrations` table */
  applied: number
  /** Count of slug-prefixed migration files present on disk */
  filesOnDisk: number
  /** filesOnDisk − applied, clamped to 0 */
  pending: number
  /** ISO 8601 timestamp from `schema_versions.updated_at`, or null if never applied */
  lastAppliedAt: string | null
  status: MigrationStatus
}

export interface PluginResource {
  slug: string
  name: string
  description: string
  version: number
  /** Absolute filesystem path to the plugin directory. May be null when the plugin was loaded from a sidecar entry without a recorded directory. */
  path: string | null
  bundledTools: BundledTool[]
  bundledDrivers: BundledDriver[]
  recipePaths: string[]
  migrations: MigrationInfo
}
