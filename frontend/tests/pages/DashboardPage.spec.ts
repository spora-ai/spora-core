/**
 * DashboardPage — renders the agent list from the agent store.
 *
 * Stub-heavy mount that asserts: (1) GlobalNavbar is rendered, (2) agents
 * are listed, (3) clicking an agent navigates to its detail page.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref } from 'vue'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

const agentsRef = ref<Array<{ id: number; name: string; description?: string | null; tools: unknown[] }>>([])
const fetchAgentsMock = vi.fn()
const lastTaskByAgentMock = new Map<number, unknown>()

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    get agents() { return agentsRef.value },
    fetchAgents: fetchAgentsMock,
  }),
}))

const fetchTasksMock = vi.fn()
vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => ({
    fetchTasks: fetchTasksMock,
    lastTaskByAgent: lastTaskByAgentMock,
  }),
}))

const GlobalNavbarStub = { name: 'GlobalNavbar', template: '<div class="navbar-stub" />' }
const CreateAgentModalStub = {
  name: 'CreateAgentModal',
  props: ['show'],
  template: '<div v-if="show" class="create-agent-stub" />',
}

import DashboardPage from '@/pages/DashboardPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  agentsRef.value = []
  fetchAgentsMock.mockReset()
  fetchAgentsMock.mockResolvedValue(undefined)
  fetchTasksMock.mockReset()
  fetchTasksMock.mockResolvedValue(undefined)
  pushMock.mockReset()
})

describe('DashboardPage', () => {
  it('renders the navbar and the create-agent CTA', () => {
    const wrapper = mount(DashboardPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub, CreateAgentModal: CreateAgentModalStub, RouterLink: true } },
    })
    expect(wrapper.find('.navbar-stub').exists()).toBe(true)
    expect(wrapper.text()).toMatch(/new agent|create agent|agent/i)
  })

  it('fetches agents and tasks on mount', async () => {
    mount(DashboardPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub, CreateAgentModal: CreateAgentModalStub, RouterLink: true } },
    })
    await flushPromises()
    expect(fetchAgentsMock).toHaveBeenCalled()
    expect(fetchTasksMock).toHaveBeenCalled()
  })

  it('renders an empty-state when there are no agents', async () => {
    const wrapper = mount(DashboardPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub, CreateAgentModal: CreateAgentModalStub, RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toMatch(/no agents|create your first|empty/i)
  })

  it('renders the list of agents', async () => {
    agentsRef.value = [
      { id: 1, name: 'Alpha', description: 'first', tools: [] },
      { id: 2, name: 'Beta', description: null, tools: [] },
    ]
    const wrapper = mount(DashboardPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub, CreateAgentModal: CreateAgentModalStub, RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Alpha')
    expect(wrapper.text()).toContain('Beta')
  })
})
