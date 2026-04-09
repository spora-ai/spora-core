import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import AgentLlmConfigModal from '@/components/agent/AgentLlmConfigModal.vue'
import { useLlmConfigsStore } from '@/stores/llmConfigs'

// Inline Modal stub — renders slot content directly, avoids Teleport issues in JSDOM
const ModalStub = {
  name: 'Modal',
  props: ['modelValue', 'title', 'size'],
  emits: ['update:modelValue', 'close'],
  template: '<div v-if="modelValue" class="modal-stub"><slot /><slot name="footer" /></div>',
}

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: vi.fn(),
}))

vi.mock('@/components/settings/ToolSettingsForm.vue', () => ({
  default: {
    name: 'ToolSettingsForm',
    props: ['tool', 'initialSettings', 'saving', 'error'],
    emits: ['save'],
    template: '<div class="tool-settings-form"><slot /></div>',
  },
}))

const mockUseLlmConfigsStore = useLlmConfigsStore as ReturnType<typeof vi.fn>

const mockDrivers = [
  {
    name: 'openai_compatible',
    display_name: 'OpenAI Compatible',
    driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
    settings_schema: [
      { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
      { key: 'model', label: 'Model', type: 'text', description: '', default: 'gpt-4o', required: false, scope: 'global', options: null },
    ],
  },
  {
    name: 'anthropic_compatible',
    display_name: 'Anthropic Compatible',
    driver_class: 'Spora\\Drivers\\AnthropicCompatibleDriver',
    settings_schema: [
      { key: 'api_key', label: 'API Key', type: 'password', description: '', default: null, required: false, scope: 'global', options: null },
    ],
  },
]

describe('AgentLlmConfigModal', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    setActivePinia(createPinia())
  })

  describe('renders correctly', () => {
    it('renders Modal when show=true', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.findComponent({ name: 'Modal' }).exists()).toBe(true)
    })

    it('does not render modal stub when show=false', () => {
      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: false, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })
      // The ModalStub's template has v-if="modelValue", so nothing renders when show=false
      expect(wrapper.find('.modal-stub').exists()).toBe(false)
    })

    it('shows driver options from llmDrivers prop', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.text()).toContain('OpenAI Compatible')
      expect(wrapper.text()).toContain('Anthropic Compatible')
    })

    it('shows driver selection prompt when no driver selected', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()
      expect(wrapper.text()).toContain('Select a driver above to see available settings fields')
    })
  })

  describe('driver selection', () => {
    it('popsulates formSettings with driver defaults when driver selected', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      // Select the OpenAI driver
      const select = wrapper.find('select')
      await select.setValue('Spora\\Drivers\\OpenAICompatibleDriver')
      await wrapper.vm.$nextTick()

      // formSettings should now have defaults from the driver's schema
      expect(wrapper.vm.formSettings).toEqual({ model: 'gpt-4o' })
    })
  })

  describe('form validation', () => {
    it('Create button is disabled when name is empty', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      const createBtn = wrapper.findAll('button').find((b) => b.text() === 'Create')
      expect(createBtn?.attributes('disabled')).toBeDefined()
    })

    it('Create button is disabled when no driver selected', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      // Fill in name but no driver
      await wrapper.find('input[type="text"]').setValue('My Config')

      const createBtn = wrapper.findAll('button').find((b) => b.text() === 'Create')
      expect(createBtn?.attributes('disabled')).toBeDefined()
    })

    it('Create button is enabled when name and driver are set', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      // Fill in name
      await wrapper.find('input[type="text"]').setValue('My Config')
      // Select driver
      const select = wrapper.find('select')
      await select.setValue('Spora\\Drivers\\OpenAICompatibleDriver')
      await wrapper.vm.$nextTick()

      const createBtn = wrapper.findAll('button').find((b) => b.text() === 'Create')
      expect(createBtn?.attributes('disabled')).toBeUndefined()
    })
  })

  describe('submit creates config', () => {
    it('calls llmStore.createConfig with correct payload', async () => {
      const createConfig = vi.fn().mockResolvedValue({
        id: 5,
        name: 'My Config',
        driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
        driver_name: 'openai_compatible',
        driver_display_name: 'OpenAI Compatible',
        settings: { model: 'gpt-4o' },
        is_default: false,
      })
      mockUseLlmConfigsStore.mockReturnValue({ createConfig })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      // Fill name and select driver
      await wrapper.find('input[type="text"]').setValue('My Config')
      const select = wrapper.find('select')
      await select.setValue('Spora\\Drivers\\OpenAICompatibleDriver')
      await wrapper.vm.$nextTick()

      // Click Create
      const createBtn = wrapper.findAll('button').find((b) => b.text() === 'Create')
      await createBtn!.trigger('click')

      await flushPromises()

      expect(createConfig).toHaveBeenCalledWith({
        name: 'My Config',
        driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
        settings: { model: 'gpt-4o' },
      })
    })

    it('emits created and update:show(false) on success', async () => {
      const createConfig = vi.fn().mockResolvedValue({
        id: 5,
        name: 'My Config',
        driver_class: 'Spora\\Drivers\\OpenAICompatibleDriver',
        driver_name: 'openai_compatible',
        driver_display_name: 'OpenAI Compatible',
        settings: { model: 'gpt-4o' },
        is_default: false,
      })
      mockUseLlmConfigsStore.mockReturnValue({ createConfig })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      await wrapper.find('input[type="text"]').setValue('My Config')
      const select = wrapper.find('select')
      await select.setValue('Spora\\Drivers\\OpenAICompatibleDriver')
      await wrapper.vm.$nextTick()

      const createBtn = wrapper.findAll('button').find((b) => b.text() === 'Create')
      await createBtn!.trigger('click')

      await flushPromises()

      expect(wrapper.emitted('created')).toBeDefined()
      expect(wrapper.emitted('update:show')).toBeDefined()
      expect(wrapper.emitted('update:show')![0]).toEqual([false])
    })

    it('sets error and keeps modal open when create fails', async () => {
      const createConfig = vi.fn().mockRejectedValue(new Error('Server error'))
      mockUseLlmConfigsStore.mockReturnValue({ createConfig })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      await wrapper.find('input[type="text"]').setValue('My Config')
      const select = wrapper.find('select')
      await select.setValue('Spora\\Drivers\\OpenAICompatibleDriver')
      await wrapper.vm.$nextTick()

      const createBtn = wrapper.findAll('button').find((b) => b.text() === 'Create')
      await createBtn!.trigger('click')

      await flushPromises()
      await wrapper.vm.$nextTick()

      // Plain Error is caught as 'Failed to create configuration.'
      expect(wrapper.vm.error).toBe('Failed to create configuration.')
      // Modal should still be visible
      expect(wrapper.find('.modal-stub').exists()).toBe(true)
    })
  })

  describe('close resets form', () => {
    it('resets formName, formDriverClass, formSettings when close is emitted', async () => {
      mockUseLlmConfigsStore.mockReturnValue({
        createConfig: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
      })

      const wrapper = mount(AgentLlmConfigModal, {
        props: { show: true, llmDrivers: mockDrivers },
        global: { stubs: { Modal: ModalStub } },
      })

      await flushPromises()

      // Fill in form
      await wrapper.find('input[type="text"]').setValue('My Config')
      const select = wrapper.find('select')
      await select.setValue('Spora\\Drivers\\OpenAICompatibleDriver')
      await wrapper.vm.$nextTick()

      // Reset
      wrapper.vm.close()
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.formName).toBe('')
      expect(wrapper.vm.formDriverClass).toBe('')
      expect(wrapper.vm.formSettings).toEqual({})
    })
  })
})
