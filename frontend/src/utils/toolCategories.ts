/**
 * Tool category helpers — shared by useAgentSettingsForm and AgentSettingsPage.
 *
 * Categories are arbitrary strings declared on the backend (e.g. "communication",
 * "search", "productivity"). They render as section headers in the agent-settings
 * tool list, alphabetised by their human label.
 */
import type { ToolSchema } from '@/composables/useToolSettings'

/** Capitalize a category key (e.g. "communication" → "Communication"). */
export function categoryLabel(cat: string): string {
  return cat.charAt(0).toUpperCase() + cat.slice(1)
}

/** Group tools by their `category` field, defaulting to "general". */
export function groupToolsByCategory(
  tools: ToolSchema[],
): Record<string, ToolSchema[]> {
  const groups: Record<string, ToolSchema[]> = {}
  for (const tool of tools) {
    const cat = (tool as unknown as { category?: string }).category ?? 'general'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(tool)
  }
  return groups
}

/** Return category keys sorted alphabetically by their human label. */
export function sortCategoryKeys(categories: Record<string, unknown>): string[] {
  return Object.keys(categories).sort((a, b) =>
    categoryLabel(a).localeCompare(categoryLabel(b)),
  )
}
