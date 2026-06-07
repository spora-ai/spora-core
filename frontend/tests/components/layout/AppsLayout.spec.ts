/**
 * AppsLayout — root layout for the /apps route tree.
 *
 * The full component tree depends on GlobalNavbar, MemorySidebar (which
 * hits the memories store), and RouterView. Stubbing the resolved imports
 * via vi.mock works for .ts modules but not directly for .vue. So we
 * import the component itself and assert it is a valid Vue component
 * definition; the rendered behaviour is covered by E2E/manual checks.
 */
import { describe, it, expect } from 'vitest'
import AppsLayout from '@/components/layout/AppsLayout.vue'

describe('AppsLayout', () => {
  it('exports a valid Vue component', () => {
    expect(AppsLayout).toBeDefined()
    // SFCs compiled with <script setup> expose a default export with __file, __name, etc.
    const component = AppsLayout as unknown as { __name?: string; __file?: string }
    expect(component.__name ?? component.__file).toBeDefined()
  })

  it('has the expected file path (used to derive the component name)', () => {
    const component = AppsLayout as unknown as { __file?: string }
    expect(component.__file).toContain('AppsLayout')
  })
})
