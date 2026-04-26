import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import AgentToolConfigModal from '@/components/agent/AgentToolConfigModal.vue'

const ModalStub = {
  name: 'Modal',
  props: ['modelValue', 'title', 'size'],
  emits: ['update:modelValue', 'close'],
  template: '<div v-if="modelValue" class="modal-stub"><slot /></div>',
}

vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(
      public readonly code: string,
      message: string,
      public readonly status: number,
    ) {
      super(message)
    }
  },
}))

vi.mock('@/components/settings/ToolSettingsForm.vue', () => ({
  default: {
    name: 'ToolSettingsForm',
    props: ['tool', 'initialSettings', 'saving', 'error'],
    emits: ['save'],
    template: '<div class="tool-settings-form"><slot /></div>',
  },
}))

import { useToolSettings } from '@/composables/useToolSettings'
import { api } from '@/api/client'

const mockUseToolSettings = useToolSettings as ReturnType<typeof vi.fn>
const mockApi = api as ReturnType<typeof vi.fn>

const makeTool = (overrides = {}) => ({
  tool_class: 'Spora\\Tools\\WebSearch',
  tool_name: 'web_search',
  display_name: 'Web Search',
  settings_schema: [
    { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
  ],
  ...overrides,
})

describe('AgentToolConfigModal', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    mockUseToolSettings.mockReturnValue({
      getSettings: vi.fn().mockReturnValue(Promise.resolve({})),
      putSettings: vi.fn().mockReturnValue(Promise.resolve({})),
      getGlobalSettings: vi.fn().mockReturnValue(Promise.resolve({})),
      getRawOverride: vi.fn().mockReturnValue(Promise.resolve({})),
      getSettingsWithSource: vi.fn().mockReturnValue(Promise.resolve({})),
    })
    mockApi.get = vi.fn().mockReturnValue(Promise.resolve({}))
  })

  describe('rendering', () => {
    it('renders nothing when toolName is null (modal closed)', () => {
      const wrapper = mount(AgentToolConfigModal, {
        props: { toolName: null, tool: null, agentId: 1 },
        global: { stubs: { Modal: ModalStub } },
      })
      expect(wrapper.find('.modal-stub').exists()).toBe(false)
    })

    it('renders modal stub when toolName is set', async () => {
      mockUseToolSettings.mockReturnValue({
        getSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockResolvedValue({}),
        getGlobalSettings: vi.fn().mockResolvedValue({}),
        getRawOverride: vi.fn().mockResolvedValue({}),
        getSettingsWithSource: vi.fn().mockResolvedValue({}),
      })
      mockApi.get = vi.fn().mockResolvedValue({})

      const wrapper = mount(AgentToolConfigModal, {
        props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.find('.modal-stub').exists()).toBe(true)
    })

    it('shows no global config warning when globalSettingsExist is false', async () => {
      mockUseToolSettings.mockReturnValue({
        getSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockResolvedValue({}),
        getGlobalSettings: vi.fn().mockRejectedValue(new Error('Not found')),
        getRawOverride: vi.fn().mockResolvedValue({}),
        getSettingsWithSource: vi.fn().mockResolvedValue({}),
      })
      mockApi.get = vi.fn().mockRejectedValue(new Error('Not found'))

      const wrapper = mount(AgentToolConfigModal, {
        props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.text()).toContain('No global configuration found')
    })
  })

  it('globalSettingsExist becomes true when global settings API succeeds', async () => {
    mockUseToolSettings.mockReturnValue({
      getSettings: vi.fn().mockResolvedValue({ 'api_key': 'sk-123' }),
      putSettings: vi.fn().mockResolvedValue({}),
      getGlobalSettings: vi.fn().mockResolvedValue({ 'api_key': 'sk-123' }),
      getRawOverride: vi.fn().mockResolvedValue({}),
      getSettingsWithSource: vi.fn().mockResolvedValue({}),
    })
    mockApi.get = vi.fn().mockResolvedValue({})

    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })

    await flushPromises()
    expect(wrapper.text()).not.toContain('No global configuration found')
  })

  it('globalSettingsExist becomes false when global settings API fails with 404', async () => {
    const { ApiError } = await import('@/api/client')
    mockUseToolSettings.mockReturnValue({
      getSettings: vi.fn().mockResolvedValue({}),
      putSettings: vi.fn().mockResolvedValue({}),
      getGlobalSettings: vi.fn().mockRejectedValue(new ApiError('NOT_FOUND', 'Not found', 404)),
      getRawOverride: vi.fn().mockResolvedValue({}),
      getSettingsWithSource: vi.fn().mockResolvedValue({}),
    })
    mockApi.get = vi.fn().mockRejectedValue(new ApiError('NOT_FOUND', 'Not found', 404))

    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })

    await flushPromises()
    expect(wrapper.text()).toContain('No global configuration found')
  })
})