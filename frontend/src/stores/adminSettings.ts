import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAdminSettingsStore = defineStore('adminSettings', () => {
  // Active admin section for sidebar highlighting
  // Values: 'users' | 'drivers' | 'tools' | 'mail' | 'mail-templates' | null
  const activeSection = ref<string | null>(null)

  function setActiveSection(section: string | null) {
    activeSection.value = section
  }

  return { activeSection, setActiveSection }
})