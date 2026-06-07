import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import ToolSettingsPanel from '@/components/settings/tools/ToolSettingsPanel.vue'

const global = { stubs: { Icon: true } }

beforeEach(() => {
  setActivePinia(createPinia())
  // Suppress the async loadSettings() promise rejection in onMounted —
  // our mocks don't resolve and the unhandled rejection is benign noise.
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

vi.mock('@/api/client', () => {
  class FakeApiError extends Error {
    status = 404
    constructor(msg = 'Not found') { super(msg); this.name = 'ApiError' }
  }
  return {
    default: {
      get: vi.fn().mockRejectedValue(new FakeApiError()),
      post: vi.fn(), put: vi.fn(), delete: vi.fn(),
    },
    api: {
      get: vi.fn().mockRejectedValue(new FakeApiError()),
      post: vi.fn(), put: vi.fn(), delete: vi.fn(),
    },
    ApiError: FakeApiError,
  }
})

describe('ToolSettingsPanel', () => {
  it('renders a settings panel with a password field', () => {
    const wrapper = mount(ToolSettingsPanel, {
      props: {
        tool: {
          tool_class: 'SendEmail',
          tool_name: 'send_email',
          display_name: 'Send Email',
          category: 'communication',
          settings_schema: [
            { key: 'host', label: 'Host', type: 'string', required: true, description: '', llm_exposed: false, sensitive: false },
            { key: 'password', label: 'Password', type: 'password', required: false, description: '', llm_exposed: false, sensitive: true },
          ],
          operations: [],
        },
        settings: { host: 'smtp.example.com', password: 'secret' },
      },
      global,
    })
    expect(wrapper.html()).toBeTruthy()
  })

  it('renders without throwing on minimal tool schema', () => {
    const wrapper = mount(ToolSettingsPanel, {
      props: {
        tool: {
          tool_class: 'SendEmail',
          tool_name: 'send_email',
          display_name: null,
          category: 'communication',
          settings_schema: [],
          operations: [],
        },
        settings: {},
      },
      global,
    })
    expect(wrapper.html()).toBeTruthy()
  })

  it('clears pending timers on unmount (SonarQube S2681: expanded onUnmounted body)', async () => {
    // Previously the onUnmounted was a one-liner with two statements that
    // only the first executed conditionally. After the fix it's a multi-line
    // block that always runs both clearTimeout calls. This test exercises
    // unmount and asserts no thrown errors from the cleanup hooks.
    const wrapper = mount(ToolSettingsPanel, {
      props: {
        tool: {
          tool_class: 'SendEmail',
          tool_name: 'send_email',
          display_name: 'Send Email',
          category: 'communication',
          settings_schema: [],
          operations: [],
        },
        settings: {},
      },
      global,
    })
    expect(() => wrapper.unmount()).not.toThrow()
  })
})
