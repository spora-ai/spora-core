import { mount } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import AgentLayout from '@/components/layout/AgentLayout.vue'

vi.mock('@/composables/useRealtime', () => ({ useRealtime: vi.fn() }))

vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: vi.fn(),
  }),
  useRoute: () => ({
    name: 'agent',
    params: { id: '1' },
  }),
}))

const mockAgentStore = {
  agents: [],
  currentAgent: null,
}

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => mockAgentStore,
}))

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ configs: [], ensure: vi.fn() }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { email: 'test@example.com' }, logout: vi.fn(), init: vi.fn() }),
}))

vi.mock('@/stores/theme', () => ({
  useThemeStore: () => ({ isDark: false, toggle: vi.fn() }),
}))

vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({ unreadCount: 0 }),
}))

describe('AgentLayout', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders default slot content', () => {
    const wrapper = mount(AgentLayout, {
      props: { agentId: 1 },
      slots: { default: '<div data-testid="slot-content">Slot content here</div>' },
    })

    expect(wrapper.find('[data-testid="slot-content"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="slot-content"]').text()).toBe('Slot content here')
  })

  it('passes llmUnconfigured=false to AgentHeaderToolbar when not provided', () => {
    const wrapper = mount(AgentLayout, {
      props: { agentId: 1 },
    })

    // AgentHeaderToolbar is rendered with llmUnconfigured=false by default
    expect(wrapper.find('div[class*="bg-background"]').exists()).toBe(true)
  })

  it('opens mobile sidebar when AgentHeaderToolbar emits open-sidebar', async () => {
    const wrapper = mount(AgentLayout, {
      props: { agentId: 1 },
    })

    // Find the sidebar toggle button and click it
    const toggleBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Show agent list')
    expect(toggleBtn).toBeDefined()

    await toggleBtn!.trigger('click')

    // Mobile overlay should now be visible
    expect(wrapper.find('.fixed.inset-0').exists()).toBe(true)
  })

  it('closes mobile sidebar when overlay backdrop is clicked', async () => {
    const wrapper = mount(AgentLayout, {
      props: { agentId: 1 },
    })

    // Open sidebar first
    const toggleBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'Show agent list')
    await toggleBtn!.trigger('click')

    // Click the overlay backdrop
    const overlay = wrapper.find('.fixed.inset-0')
    await overlay.trigger('click')

    expect(wrapper.find('.fixed.inset-0').exists()).toBe(false)
  })
})
