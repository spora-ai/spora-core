import { mount } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import AgentSidebar from '@/components/layout/AgentSidebar.vue'

const makeAgent = (id: number, name: string) => ({
  id,
  name,
  description: null,
  recipe_id: null,
  system_prompt: null,
  llm_provider: 'openai_compatible',
  llm_model: 'gpt-4o',
  llm_base_url: null,
  llm_driver_config_id: null,
  max_steps: 10,
  is_active: true,
  tools: [],
})

const mockAgentStore = {
  agents: [],
}

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => mockAgentStore,
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: vi.fn(),
  }),
}))

describe('AgentSidebar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAgentStore.agents = []
  })

  it('renders agents list from store', () => {
    mockAgentStore.agents = [makeAgent(1, 'Agent One'), makeAgent(2, 'Agent Two')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 1 },
    })

    expect(wrapper.text()).toContain('Agent One')
    expect(wrapper.text()).toContain('Agent Two')
  })

  it('highlights the active agent', () => {
    mockAgentStore.agents = [makeAgent(1, 'Agent One'), makeAgent(2, 'Agent Two')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 2 },
    })

    const items = wrapper.findAll('li')
    expect(items[1].classes()).toContain('bg-primary/10')
    expect(items[1].classes()).toContain('border-r-2')
    expect(items[1].classes()).toContain('border-primary')
  })

  it('does not highlight inactive agents when agentId does not match', () => {
    mockAgentStore.agents = [makeAgent(1, 'Agent One'), makeAgent(2, 'Agent Two')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 99 },
    })

    const items = wrapper.findAll('li')
    // No item should have active classes
    for (const item of items) {
      expect(item.classes()).not.toContain('bg-primary/10')
    }
  })

  it('shows agent initial in avatar circle', () => {
    mockAgentStore.agents = [makeAgent(1, 'My Agent')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 1 },
    })

    const avatars = wrapper.findAll('.rounded-full')
    expect(avatars[0].text()).toBe('M')
  })

  it('emits close when an agent is clicked', async () => {
    mockAgentStore.agents = [makeAgent(1, 'Agent One')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 2 },
    })

    await wrapper.findAll('li')[0].trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('renders extra slot content', () => {
    const wrapper = mount(AgentSidebar, {
      props: { agentId: 0 },
      slots: {
        extra: '<button data-testid="extra-btn">Extra</button>',
      },
    })

    expect(wrapper.find('[data-testid="extra-btn"]').exists()).toBe(true)
  })

  it('has hidden lg:flex class by default (desktop behavior)', () => {
    mockAgentStore.agents = [makeAgent(1, 'Agent')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 1 },
    })

    expect(wrapper.classes()).toContain('hidden')
    expect(wrapper.classes()).toContain('lg:flex')
  })

  it('removes hidden class when mobileOpen=true', () => {
    mockAgentStore.agents = [makeAgent(1, 'Agent')]

    const wrapper = mount(AgentSidebar, {
      props: { agentId: 1, mobileOpen: true },
    })

    expect(wrapper.classes()).not.toContain('hidden')
  })
})
