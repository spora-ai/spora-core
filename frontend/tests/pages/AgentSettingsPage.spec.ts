/**
 * AgentSettingsPage — thin shell that fetches the agent and wires the four
 * settings sub-components. Stubs the sub-components and the layout so this
 * suite only covers the page's own wiring.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref, computed } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const route = { params: { id: '42' } }
const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ push: pushMock }),
}))

const fetchAgentsMock = vi.fn().mockResolvedValue(undefined)
const fetchAgentMock = vi.fn().mockResolvedValue(undefined)
const currentAgentRef = ref<{ id: number; name: string } | null>(null)
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    get currentAgent() { return currentAgentRef.value },
    fetchAgents: fetchAgentsMock,
    fetchAgent: fetchAgentMock,
  }),
}))

const ensureMock = vi.fn().mockResolvedValue(undefined)
vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ ensure: ensureMock }),
}))

const loadPreferenceMock = vi.fn().mockResolvedValue(undefined)
vi.mock('@/stores/llmPreferencesStore', () => ({
  useLlmPreferencesStore: () => ({ loadPreference: loadPreferenceMock }),
}))

const AgentLayoutStub = {
  name: 'AgentLayout',
  props: ['agentId'],
  template: '<div class="agent-layout-stub"><slot /></div>',
}

const IdentityStub = {
  name: 'AgentIdentitySection',
  props: ['agent', 'agentId'],
  template: '<div class="identity-stub" :data-agent-id="agentId" />',
}
const LlmStub = {
  name: 'AgentLlmSection',
  props: ['agent', 'agentId'],
  template: '<div class="llm-stub" :data-agent-id="agentId" />',
}
const ToolsStub = {
  name: 'AgentToolsSection',
  props: ['agent', 'agentId'],
  template: '<div class="tools-stub" :data-agent-id="agentId" />',
}
const DangerStub = {
  name: 'AgentDangerZone',
  props: ['agent', 'agentId'],
  emits: ['deleted'],
  template: '<div class="danger-stub" :data-agent-id="agentId" @click="$emit(\'deleted\')" />',
}

import AgentSettingsPage from '@/pages/AgentSettingsPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  currentAgentRef.value = null
  fetchAgentsMock.mockClear()
  fetchAgentMock.mockClear()
  ensureMock.mockClear()
  loadPreferenceMock.mockClear()
  pushMock.mockReset()
})

describe('AgentSettingsPage', () => {
  it('shows the loading state until the agent is fetched', async () => {
    const wrapper = mount(AgentSettingsPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          AgentIdentitySection: IdentityStub,
          AgentLlmSection: LlmStub,
          AgentToolsSection: ToolsStub,
          AgentDangerZone: DangerStub,
        },
      },
    })
    // Before fetchAgents/fetchAgent resolves, currentAgent is null
    expect(wrapper.text()).toContain('Loading')
    await flushPromises()
  })

  it('fetches agent, configs, and preferences in parallel on mount', async () => {
    mount(AgentSettingsPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          AgentIdentitySection: IdentityStub,
          AgentLlmSection: LlmStub,
          AgentToolsSection: ToolsStub,
          AgentDangerZone: DangerStub,
        },
      },
    })
    await flushPromises()
    expect(fetchAgentsMock).toHaveBeenCalledTimes(1)
    expect(fetchAgentMock).toHaveBeenCalledWith(42)
    expect(ensureMock).toHaveBeenCalledTimes(1)
    expect(loadPreferenceMock).toHaveBeenCalledTimes(1)
  })

  it('parses the route id as a number and passes it down to the sub-sections', async () => {
    currentAgentRef.value = { id: 42, name: 'Loaded' }
    const wrapper = mount(AgentSettingsPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          AgentIdentitySection: IdentityStub,
          AgentLlmSection: LlmStub,
          AgentToolsSection: ToolsStub,
          AgentDangerZone: DangerStub,
        },
      },
    })
    await flushPromises()
    expect(wrapper.find('.identity-stub').attributes('data-agent-id')).toBe('42')
    expect(wrapper.find('.llm-stub').attributes('data-agent-id')).toBe('42')
    expect(wrapper.find('.tools-stub').attributes('data-agent-id')).toBe('42')
    expect(wrapper.find('.danger-stub').attributes('data-agent-id')).toBe('42')
  })

  it('renders all four sections once the agent is loaded', async () => {
    currentAgentRef.value = { id: 42, name: 'Loaded' }
    const wrapper = mount(AgentSettingsPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          AgentIdentitySection: IdentityStub,
          AgentLlmSection: LlmStub,
          AgentToolsSection: ToolsStub,
          AgentDangerZone: DangerStub,
        },
      },
    })
    await flushPromises()
    expect(wrapper.find('.identity-stub').exists()).toBe(true)
    expect(wrapper.find('.llm-stub').exists()).toBe(true)
    expect(wrapper.find('.tools-stub').exists()).toBe(true)
    expect(wrapper.find('.danger-stub').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Loading')
  })

  it('navigates to the dashboard when a section emits "deleted"', async () => {
    currentAgentRef.value = { id: 42, name: 'Loaded' }
    const wrapper = mount(AgentSettingsPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          AgentIdentitySection: IdentityStub,
          AgentLlmSection: LlmStub,
          AgentToolsSection: ToolsStub,
          AgentDangerZone: DangerStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('.danger-stub').trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'dashboard' })
  })
})
