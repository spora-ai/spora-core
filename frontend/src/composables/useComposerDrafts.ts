/**
 * useComposerDrafts — sessionStorage persistence for in-progress composer prompts.
 *
 * The agent store owns the reactive draft map (so it can wire a deep watcher that
 * auto-persists), but the load/save round-trip is a pure utility that doesn't
 * depend on Vue or Pinia — useful for testing the edge cases in isolation
 * (corrupt JSON, sessionStorage disabled in private browsing, etc.).
 */
const COMPOSER_DRAFTS_KEY = 'spora:composer-drafts'

export interface ComposerDraft {
  promptText: string
}

export type ComposerDraftsMap = Record<number, ComposerDraft>

/** Read the persisted drafts from sessionStorage. Tolerates missing/disabled storage. */
export function loadComposerDrafts(): ComposerDraftsMap {
  try {
    const stored = sessionStorage.getItem(COMPOSER_DRAFTS_KEY)
    return stored ? JSON.parse(stored) : {}
  } catch {
    return {}
  }
}

/** Write the drafts to sessionStorage. Silently no-ops when storage is unavailable. */
export function saveComposerDrafts(drafts: ComposerDraftsMap): void {
  try {
    sessionStorage.setItem(COMPOSER_DRAFTS_KEY, JSON.stringify(drafts))
  } catch {
    // sessionStorage may be unavailable (e.g., private browsing) — drafts remain in memory.
  }
}
