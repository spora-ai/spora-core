import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Manages theme state (light/dark) with localStorage persistence.
 */
export const useThemeStore = defineStore('theme', () => {
  const isDark = ref(false)

  function init(): void {
    const stored = localStorage.getItem('theme')
    if (stored === 'dark') {
      isDark.value = true
    } else if (stored === 'light') {
      isDark.value = false
    } else {
      // Default: light mode (not system preference)
      isDark.value = false
    }
    apply()
  }

  function toggle(): void {
    isDark.value = !isDark.value
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light')
    apply()
  }

  function apply(): void {
    document.documentElement.classList.toggle('dark', isDark.value)
  }

  return { isDark, init, toggle, apply }
})
