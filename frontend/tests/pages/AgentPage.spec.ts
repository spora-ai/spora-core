/**
 * AgentPage — agent detail with composer + task history.
 *
 * Smoke test: mounts the page with stubbed stores and asserts the composer
 * is rendered. A full interaction test would require extensive stubs (the
 * page reads from agent store, prompt templates, llm configs, etc.).
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const agents = [{ id: 1, name: 'Test', description: '', tools: [] }]
const currentAgent = agents[0]
const fetchAgentsMock = vi.fn()
const fetchAgentMock = vi.fn()
const fetchAgentTasksMock = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    agents,
    currentAgent,
    currentAgentTasks: [],
    tasksLoading: false,
    tasksHasMore: false,
    fetchAgents: fetchAgentsMock,
    fetchAgent: fetchAgentMock,
    fetchAgentTasks: fetchAgentTasksMock,
    loadMoreTasks: vi.fn(),
    deleteTask: vi.fn(),
    clearCurrentAgent: vi.fn(),
  }),
}))

vi.mock('@/stores/promptTemplates', () => ({
  usePromptTemplatesStore: () => ({ fetchTemplates: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), configs: [] }),
}))

vi.mock('@/stores/llmPreferencesStore', () => ({
  useLlmPreferencesStore: () => ({ loadPreference: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useRealtime', () => ({
  useRealtime: vi.fn(),
}))

const AgentLayoutStub = { name: 'AgentLayout', template: '<div class="agent-layout-stub"><slot /></div>' }
const ComposerInputStub = { name: 'ComposerInput', template: '<div class="composer-stub" />' }

import AgentPage from '@/pages/AgentPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('AgentPage', () => {
  it('mounts without throwing', () => {
    const wrapper = mount(AgentPage, {
      global: {
        stubs: { AgentLayout: AgentLayoutStub, ComposerInput: ComposerInputStub, RouterLink: true, TaskStatusBadge: true },
      },
    })
    expect(wrapper.find('.agent-layout-stub').exists()).toBe(true)
  })

  it('renders the composer input', () => {
    const wrapper = mount(AgentPage, {
      global: {
        stubs: { AgentLayout: AgentLayoutStub, ComposerInput: ComposerInputStub, RouterLink: true, TaskStatusBadge: true },
      },
    })
    expect(wrapper.find('.composer-stub').exists()).toBe(true)
  })

  it('shows an empty-state when the agent has no tasks', () => {
    const wrapper = mount(AgentPage, {
      global: {
        stubs: { AgentLayout: AgentLayoutStub, ComposerInput: ComposerInputStub, RouterLink: true, TaskStatusBadge: true },
      },
    })
    expect(wrapper.text()).toMatch(/no messages yet|start a conversation/i)
  })
})
