/**
 * LLMConfigsPage — legacy page that redirects to /settings/llm on mount.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const replaceMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: replaceMock }),
}))

import LLMConfigsPage from '@/pages/LLMConfigsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  replaceMock.mockReset()
})

describe('LLMConfigsPage', () => {
  it('renders a "Redirecting…" placeholder', () => {
    const wrapper = mount(LLMConfigsPage)
    expect(wrapper.text()).toContain('Redirecting')
  })

  it('replaces the route with settings-llm on mount', async () => {
    mount(LLMConfigsPage)
    await flushPromises()
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-llm' })
  })
})
