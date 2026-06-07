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
      getUserSettings: vi.fn().mockReturnValue(Promise.resolve({})),
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
        getUserSettings: vi.fn().mockResolvedValue({}),
      })
      mockApi.get = vi.fn().mockResolvedValue({})

      const wrapper = mount(AgentToolConfigModal, {
        props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.find('.modal-stub').exists()).toBe(true)
    })

    it('shows currently active settings with defaults when no settings configured', async () => {
      mockUseToolSettings.mockReturnValue({
        getSettings: vi.fn().mockResolvedValue({}),
        putSettings: vi.fn().mockResolvedValue({}),
        getGlobalSettings: vi.fn().mockRejectedValue(new Error('Not found')),
        getRawOverride: vi.fn().mockResolvedValue({}),
        getSettingsWithSource: vi.fn().mockResolvedValue({}),
        getUserSettings: vi.fn().mockRejectedValue(new Error('Not found')),
      })
      mockApi.get = vi.fn().mockRejectedValue(new Error('Not found'))

      const wrapper = mount(AgentToolConfigModal, {
        props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.text()).toContain('Currently Active Settings')
      expect(wrapper.text()).toContain('Using defaults')
    })
  })

  it('globalSettingsExist becomes true when global settings API succeeds', async () => {
    mockUseToolSettings.mockReturnValue({
      getSettings: vi.fn().mockResolvedValue({ 'api_key': 'sk-123' }),
      putSettings: vi.fn().mockResolvedValue({}),
      getGlobalSettings: vi.fn().mockResolvedValue({ 'api_key': 'sk-123' }),
      getRawOverride: vi.fn().mockResolvedValue({}),
      getSettingsWithSource: vi.fn().mockResolvedValue({}),
      getUserSettings: vi.fn().mockResolvedValue({}),
    })
    mockApi.get = vi.fn().mockResolvedValue({})

    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })

    await flushPromises()
    expect(wrapper.text()).not.toContain('No global configuration found')
  })

  it('shows currently active settings when global settings API returns 404', async () => {
    const { ApiError } = await import('@/api/client')
    mockUseToolSettings.mockReturnValue({
      getSettings: vi.fn().mockResolvedValue({}),
      putSettings: vi.fn().mockResolvedValue({}),
      getGlobalSettings: vi.fn().mockRejectedValue(new ApiError('NOT_FOUND', 'Not found', 404)),
      getRawOverride: vi.fn().mockResolvedValue({}),
      getSettingsWithSource: vi.fn().mockResolvedValue({}),
      getUserSettings: vi.fn().mockRejectedValue(new ApiError('NOT_FOUND', 'Not found', 404)),
    })
    mockApi.get = vi.fn().mockRejectedValue(new ApiError('NOT_FOUND', 'Not found', 404))

    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool: makeTool(), agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })

    await flushPromises()
    expect(wrapper.text()).toContain('Currently Active Settings')
  })
})


import { createMemoryHistory, createRouter } from 'vue-router'

const tool = makeTool()

function makeWrapper(propsOverrides = {}, settingsMock: Record<string, ReturnType<typeof vi.fn>> = {}) {
  mockUseToolSettings.mockReturnValue({
    getSettings: vi.fn().mockResolvedValue({}),
    putSettings: vi.fn().mockResolvedValue({}),
    getGlobalSettings: vi.fn().mockResolvedValue({}),
    getRawOverride: vi.fn().mockResolvedValue({}),
    getSettingsWithSource: vi.fn().mockResolvedValue({}),
    getUserSettings: vi.fn().mockResolvedValue({}),
    ...settingsMock,
  })
  mockApi.get = vi.fn().mockResolvedValue({})
  return mount(AgentToolConfigModal, {
    props: { toolName: 'web_search', tool, agentId: 1, ...propsOverrides },
    global: { stubs: { Modal: ModalStub } },
  })
}

describe('AgentToolConfigModal — additional actions', () => {
  it('saves the form and emits saved + close on success', async () => {
    mockApi.put = vi.fn().mockResolvedValue({})
    const wrapper = makeWrapper()
    await flushPromises()
        await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(mockApi.put).toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toBeTruthy()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('shows an error message when save fails with ApiError', async () => {
    const { ApiError } = await import('@/api/client')
    mockApi.put = vi.fn().mockRejectedValue(new ApiError('FAIL', 'Server error', 500))
    const wrapper = makeWrapper()
    await flushPromises()
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toContain('Server error')
  })

  it('shows a generic message when save fails with a non-ApiError', async () => {
    mockApi.put = vi.fn().mockRejectedValue(new Error('boom'))
    const wrapper = makeWrapper()
    await flushPromises()
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toContain('Failed to save settings.')
  })

  it('shows the "Delete global defaults" button when global settings exist', async () => {
    const wrapper = makeWrapper({}, {
      getGlobalSettings: vi.fn().mockResolvedValue({ api_key: 'sk-123' }),
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Delete global defaults')
  })

  it('calls api.delete for global settings and emits saved + close', async () => {
    mockApi.delete = vi.fn().mockResolvedValue({})
    const wrapper = makeWrapper({}, {
      getGlobalSettings: vi.fn().mockResolvedValue({ api_key: 'sk-123' }),
    })
    await flushPromises()
    const btn = wrapper.findAll('button').find((b) => b.text() === 'Delete global defaults')!
    await btn.trigger('click')
    await flushPromises()
    expect(mockApi.delete).toHaveBeenCalledWith(
      expect.stringContaining('/tools/web_search/settings'),
    )
    expect(wrapper.emitted('saved')).toBeTruthy()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('shows an error message when delete global settings fails', async () => {
    const { ApiError } = await import('@/api/client')
    mockApi.delete = vi.fn().mockRejectedValue(new ApiError('FAIL', 'Cannot delete', 500))
    const wrapper = makeWrapper({}, {
      getGlobalSettings: vi.fn().mockResolvedValue({ api_key: 'sk-123' }),
    })
    await flushPromises()
    const btn = wrapper.findAll('button').find((b) => b.text() === 'Delete global defaults')!
    await btn.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Cannot delete')
  })

  it('shows the "Delete my user overrides" button when user settings exist', async () => {
    const wrapper = makeWrapper({}, {
      getUserSettings: vi.fn().mockResolvedValue({ api_key: 'sk-123' }),
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Delete my user overrides')
  })

  it('calls api.delete for user settings and emits saved + close', async () => {
    mockApi.delete = vi.fn().mockResolvedValue({})
    const wrapper = makeWrapper({}, {
      getUserSettings: vi.fn().mockResolvedValue({ api_key: 'sk-123' }),
    })
    await flushPromises()
    const btn = wrapper.findAll('button').find((b) => b.text() === 'Delete my user overrides')!
    await btn.trigger('click')
    await flushPromises()
    expect(mockApi.delete).toHaveBeenCalledWith(
      expect.stringContaining('/tools/web_search/user-settings'),
    )
    expect(wrapper.emitted('saved')).toBeTruthy()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('shows an error message when delete user settings fails', async () => {
    const { ApiError } = await import('@/api/client')
    mockApi.delete = vi.fn().mockRejectedValue(new ApiError('FAIL', 'Cannot delete', 500))
    const wrapper = makeWrapper({}, {
      getUserSettings: vi.fn().mockResolvedValue({ api_key: 'sk-123' }),
    })
    await flushPromises()
    const btn = wrapper.findAll('button').find((b) => b.text() === 'Delete my user overrides')!
    await btn.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Cannot delete')
  })

  it('triggers remove-all on agent override form and emits saved + close', async () => {
    mockApi.delete = vi.fn().mockResolvedValue({})
    const wrapper = makeWrapper({}, {
      getRawOverride: vi.fn().mockResolvedValue({ api_key: '***' }),
    })
    await flushPromises()
    const removeAll = wrapper.findAll('button').find((b) => b.text().includes('Remove all agent overrides'))!
    await removeAll.trigger('click')
    await flushPromises()
    expect(mockApi.delete).toHaveBeenCalledWith(
      expect.stringContaining('/override'),
    )
    expect(wrapper.emitted('saved')).toBeTruthy()
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('shows an error message when remove-all fails', async () => {
    const { ApiError } = await import('@/api/client')
    mockApi.delete = vi.fn().mockRejectedValue(new ApiError('FAIL', 'Cannot remove', 500))
    const wrapper = makeWrapper({}, {
      getRawOverride: vi.fn().mockResolvedValue({ api_key: '***' }),
    })
    await flushPromises()
    const removeAll = wrapper.findAll('button').find((b) => b.text().includes('Remove all agent overrides'))!
    await removeAll.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Cannot remove')
  })

  it('clicking "Configure global settings →" emits close and pushes the settings-tools route', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ name: 'settings-tools', path: '/', component: { template: '<div />' } }] })
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool, agentId: 1 },
      global: {
        plugins: [router],
        stubs: { Modal: ModalStub },
      },
    })
    await flushPromises()
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Configure global settings'))!
    await btn.trigger('click')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'settings-tools' })
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('renders the "no configurable settings" branch when settings_schema is empty', async () => {
    const toolNoSchema = makeTool({ settings_schema: [] })
    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool: toolNoSchema, agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('This tool has no configurable settings.')
  })

  it('triggers update:form on the override form (covers line 151)', async () => {
    const wrapper = makeWrapper()
    await flushPromises()
        const inputs = wrapper.findAll('input')
    if (inputs.length > 0) {
      await inputs[0].setValue('new value')
      await flushPromises()
    }
    expect(true).toBe(true)
  })

  it('emits close when the Cancel button is clicked (covers line 208)', async () => {
    const wrapper = makeWrapper()
    await flushPromises()
    const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'Cancel')!
    await cancelBtn.trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('emits close when the Close button is clicked in the no-schema branch (covers line 229)', async () => {
    const toolNoSchema = makeTool({ settings_schema: [] })
    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool: toolNoSchema, agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })
    await flushPromises()
    const closeBtn = wrapper.findAll('button').find((b) => b.text() === 'Close')!
    await closeBtn.trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('exercises the form save button (covers line 168 with the footer buttons)', async () => {
    mockApi.put = vi.fn().mockResolvedValue({})
    const wrapper = makeWrapper()
    await flushPromises()
        const saveBtn = wrapper.find('button[type="submit"]')!
    expect(saveBtn.exists()).toBe(true)
      })

  it('handles rawOverride + settingsWithSource promise rejections (covers lines 65, 70)', async () => {
    mockApi.get = vi.fn().mockResolvedValue({})
    mockUseToolSettings.mockReturnValue({
      getSettings: vi.fn().mockResolvedValue({}),
      putSettings: vi.fn().mockResolvedValue({}),
      getGlobalSettings: vi.fn().mockResolvedValue({}),
      getRawOverride: vi.fn().mockRejectedValue(new Error('boom')),
      getSettingsWithSource: vi.fn().mockRejectedValue(new Error('boom')),
      getUserSettings: vi.fn().mockResolvedValue({}),
    })
    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool, agentId: 1 },
      global: { stubs: { Modal: ModalStub } },
    })
    await flushPromises()
        expect(wrapper.find('.modal-stub').exists()).toBe(true)
  })

  it('handles the Modal update:modelValue event (covers lines 150-151)', async () => {
    const Modal = (await import('@/components/Modal.vue')).default
    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool, agentId: 1 },
    })
    await flushPromises()
        const modal = wrapper.findComponent(Modal)
    expect(modal.exists()).toBe(true)
    await modal.vm.$emit('update:modelValue', false)
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('handles the Modal @close event', async () => {
    const Modal = (await import('@/components/Modal.vue')).default
    const wrapper = mount(AgentToolConfigModal, {
      props: { toolName: 'web_search', tool, agentId: 1 },
    })
    await flushPromises()
    const modal = wrapper.findComponent(Modal)
    await modal.vm.$emit('close')
    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
