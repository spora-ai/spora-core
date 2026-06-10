import { api } from '@/api/client'
import type { PluginResource } from '../types/plugin'

/**
 * Plugin API client. v1 is read-only — a single GET returning the full
 * inventory. Operators rely on `bin/spora spora:install` to apply migrations.
 */
export async function getPlugins(): Promise<PluginResource[]> {
  const result = await api.get<{ plugins: PluginResource[] }>('/plugins')
  return result.plugins
}
