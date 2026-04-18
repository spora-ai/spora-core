import { mount } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import AgentHeaderToolbar from '@/components/layout/AgentHeaderToolbar.vue'

// Module-level mock state (reset in beforeEach/afterEach)
let mockCurrentAgent: ReturnType<typeof makeMockAgent> | null = null

function makeMockAgent(overrides = {}) {
  return {
    id: 1,
    name: 'Test Agent',
    description: 'A test agent description',
    recipe_id: null,
    system_prompt: null,
    llm_provider: 'openai_compatible',
    llm_model: 'gpt-4o',
    llm_base_url: null,
    llm_driver_config_id: null,
    max_steps: 10,
    is_active: true,
    tools: [{ tool_name: 'web_search', tool_class: 'TestTool', display_name: 'Web Search', settings_schema: [], auto_approve: null }],
    ...overrides,
  }
}

const mockAgentStore = {
  currentAgent: null,
  agents: [],
  fetchAgents: vi.fn(),
  fetchAgent: vi.fn(),
  clearCurrentAgent: vi.fn(),
}

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => mockAgentStore,
}))

const mockRouter = { push: vi.fn() }
vi.mock('vue-router', () => ({
  useRouter: () => mockRouter,
  useRoute: () => ({ name: 'agent', params: { id: '1' } }),
}))

beforeEach(() => {
  vi.restoreAllMocks()
  mockCurrentAgent = null
  mockAgentStore.currentAgent = null
  mockAgentStore.agents = []
})

describe('AgentHeaderToolbar', () => {
  it('renders agent name', () => {
    mockCurrentAgent = makeMockAgent()
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    expect(wrapper.text()).toContain('Test Agent')
  })

  it('renders agent description', () => {
    mockCurrentAgent = makeMockAgent()
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    expect(wrapper.text()).toContain('A test agent description')
  })

  it('hides description when not set', () => {
    mockCurrentAgent = makeMockAgent({ description: null })
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    expect(wrapper.find('p.text-sm.text-muted-foreground').exists()).toBe(false)
  })

  it('shows tab bar with Chats, Schedules, Settings tabs', () => {
    mockCurrentAgent = makeMockAgent()
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    const texts = wrapper.findAll('nav button').map((b) => b.text())
    expect(texts).toContain('Chats')
    expect(texts).toContain('Schedules')
    expect(texts).toContain('Settings')
  })

  it('shows LLM unconfigured banner when llmUnconfigured=true', () => {
    mockCurrentAgent = makeMockAgent()
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: true },
    })

    expect(wrapper.text()).toContain('LLM not configured')
    expect(wrapper.text()).toContain('Add your API key before running tasks.')
  })

  it('hides LLM unconfigured banner when llmUnconfigured=false', () => {
    mockCurrentAgent = makeMockAgent()
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    expect(wrapper.text()).not.toContain('LLM not configured')
  })

  it('emits open-sidebar when sidebar hamburger clicked', async () => {
    mockCurrentAgent = makeMockAgent()
    mockAgentStore.currentAgent = mockCurrentAgent

    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    const hamburger = wrapper.findAll('button').find((b) => b.attributes('title') === 'Show agent list')
    expect(hamburger).toBeDefined()

    await hamburger!.trigger('click')
    expect(wrapper.emitted('open-sidebar')).toHaveLength(1)
  })

  it('shows loading state when currentAgent is null', () => {
    const wrapper = mount(AgentHeaderToolbar, {
      props: { agentId: 1, llmUnconfigured: false },
    })

    expect(wrapper.text()).toContain('Loading…')
  })
})
