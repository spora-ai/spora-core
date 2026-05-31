import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Manages admin settings UI state, particularly the active admin section for sidebar highlighting.
 */
export const useAdminSettingsStore = defineStore('adminSettings', () => {
  // Active admin section for sidebar highlighting
  // Values: 'users' | 'drivers' | 'tools' | 'mail' | 'mail-templates' | null
  const activeSection = ref<string | null>(null)

  function setActiveSection(section: string | null) {
    activeSection.value = section
  }

  return { activeSection, setActiveSection }
})