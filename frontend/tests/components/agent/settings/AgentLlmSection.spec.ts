/**
 * AgentLlmSection — LLM config picker + save flow.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
  api: { patch: vi.fn(), get: vi.fn(), post: vi.fn() },
}))

const LlmModalStub = {
  name: 'AgentLlmConfigModal',
  props: ['show', 'llmDrivers'],
  emits: ['update:show', 'created'],
  template: '<div v-if="show" class="llm-modal-stub" @click="$emit(\'created\', { id: 99, name: \'New\', driver_display_name: \'Anthropic\', driver_class: \'A\', is_default: false, is_global: false })"></div>',
}

import { api } from '@/api/client'
import AgentLlmSection from '@/components/agent/settings/AgentLlmSection.vue'

const patchMock = api.patch as ReturnType<typeof vi.fn>
const getMock = api.get as ReturnType<typeof vi.fn>

const baseAgent = { id: 1, llm_driver_config_id: 10 }
const baseConfigs = [
  { id: 10, name: 'Primary', driver_display_name: 'OpenAI', driver_class: 'O', is_default: true, is_global: false },
  { id: 11, name: 'Global', driver_display_name: 'Anthropic', driver_class: 'A', is_default: false, is_global: true },
]
const baseDrivers: unknown[] = []

beforeEach(() => {
  patchMock.mockReset()
  patchMock.mockResolvedValue({})
  getMock.mockReset()
  getMock.mockImplementation((url: string) => {
    if (url === '/llm-configs') return Promise.resolve({ configs: baseConfigs })
    if (url === '/llm-drivers') return Promise.resolve({ drivers: baseDrivers })
    return Promise.resolve({})
  })
})

describe('AgentLlmSection', () => {
  it('loads configs and drivers on mount and selects the current one', async () => {
    const wrapper = mount(AgentLlmSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: { stubs: { AgentLlmConfigModal: LlmModalStub } },
    })
    await flushPromises()
    const select = wrapper.find('#llm-config')
    expect((select.element as HTMLSelectElement).value).toBe('10')
    expect(wrapper.text()).toContain('Driver: OpenAI')
  })

  it('sends PATCH with the selected llm_driver_config_id', async () => {
    const wrapper = mount(AgentLlmSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: { stubs: { AgentLlmConfigModal: LlmModalStub } },
    })
    await flushPromises()
    await wrapper.find('#llm-config').setValue('11')
    await wrapper.find('[data-testid="save-llm"]').trigger('click')
    await flushPromises()
    expect(patchMock).toHaveBeenCalledWith('/agents/1', { llm_driver_config_id: 11 })
  })

  it('disables save when no config is selected', async () => {
    const wrapper = mount(AgentLlmSection, {
      props: { agent: { ...baseAgent, llm_driver_config_id: null }, agentId: 1 },
      global: { stubs: { AgentLlmConfigModal: LlmModalStub } },
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="save-llm"]').attributes('disabled')).toBeDefined()
  })

  it('renders the error message on save failure', async () => {
    const { ApiError } = await import('@/api/client')
    patchMock.mockRejectedValueOnce(new ApiError('save failed'))
    const wrapper = mount(AgentLlmSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: { stubs: { AgentLlmConfigModal: LlmModalStub } },
    })
    await flushPromises()
    await wrapper.find('[data-testid="save-llm"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="llm-error"]').text()).toBe('save failed')
  })

  it('opens the create modal on + New and adds the new config', async () => {
    const wrapper = mount(AgentLlmSection, {
      props: { agent: { ...baseAgent, llm_driver_config_id: null }, agentId: 1 },
      global: { stubs: { AgentLlmConfigModal: LlmModalStub } },
    })
    await flushPromises()
    await wrapper.find('[data-testid="create-llm"]').trigger('click')
    await flushPromises()
    // The stub modal emits `created` when its outer div is clicked.
    await wrapper.find('.llm-modal-stub').trigger('click')
    await flushPromises()
    const select = wrapper.find('#llm-config')
    expect((select.element as HTMLSelectElement).value).toBe('99')
  })
})
