import { setActivePinia, createPinia } from 'pinia'
import { useThemeStore } from '@/stores/theme'
import { describe, it, expect, beforeEach, vi } from 'vitest'

describe('useThemeStore', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
    setActivePinia(createPinia())
  })

  it('defaults to light mode (isDark = false)', () => {
    const store = useThemeStore()
    expect(store.isDark).toBe(false)
  })

  it('init applies dark class to documentElement', () => {
    const store = useThemeStore()
    store.init()
    // After init, dark class should match isDark state
    const hasDark = document.documentElement.classList.contains('dark')
    expect(hasDark).toBe(store.isDark)
  })

  it('toggle flips isDark', () => {
    const store = useThemeStore()
    const before = store.isDark
    store.toggle()
    expect(store.isDark).toBe(!before)
  })

  it('toggle persists to localStorage', () => {
    const store = useThemeStore()
    store.init()
    store.toggle()
    const stored = localStorage.getItem('theme')
    expect(stored).toBe(store.isDark ? 'dark' : 'light')
  })

  it('init reads stored theme from localStorage', () => {
    localStorage.setItem('theme', 'dark')
    const store = useThemeStore()
    store.init()
    expect(store.isDark).toBe(true)

    localStorage.setItem('theme', 'light')
    const store2 = useThemeStore()
    store2.init()
    expect(store2.isDark).toBe(false)
  })

  it('init applies light mode when no stored value', () => {
    localStorage.removeItem('theme')
    const store = useThemeStore()
    store.init()
    // Default is light, not system preference
    expect(store.isDark).toBe(false)
  })

  it('apply adds/removes dark class', () => {
    const store = useThemeStore()
    store.isDark = true
    store.apply()
    expect(document.documentElement.classList.contains('dark')).toBe(true)

    store.isDark = false
    store.apply()
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })
})
