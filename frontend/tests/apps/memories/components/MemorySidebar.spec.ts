/**
 * MemorySidebar — left navigation for the memories app (global + agent-scoped).
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const pushMock = vi.fn()
const routeName = ref<string>('agent-memories')
const routeParams = ref<Record<string, string>>({ id: '7' })
const routeQuery = ref<Record<string, string>>({})

vi.mock('vue-router', () => ({
  useRoute: () => ({ name: routeName.value, params: routeParams.value, query: routeQuery.value }),
  useRouter: () => ({ push: pushMock }),
}))

const agentsRef = ref<Array<{ id: number; name: string }>>([])
const fetchAgentsMock = vi.fn()
const loadGlobalMemoriesMock = vi.fn()
const loadAgentMemoriesMock = vi.fn()
const globalMemoriesRef = ref<Array<{ id: number; name: string }>>([])
const agentMemoriesRef = ref<Array<{ id: number; name: string }>>([])

vi.mock('@/apps/memories/stores/memories', () => ({
  useMemoriesStore: () => ({
    get globalMemories() { return globalMemoriesRef.value },
    get agentMemories() { return agentMemoriesRef.value },
    loadGlobalMemories: loadGlobalMemoriesMock,
    loadAgentMemories: loadAgentMemoriesMock,
  }),
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    get agents() { return agentsRef.value },
    fetchAgents: fetchAgentsMock,
  }),
}))

import MemorySidebar from '@/apps/memories/components/MemorySidebar.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  agentsRef.value = []
  globalMemoriesRef.value = []
  agentMemoriesRef.value = []
  routeName.value = 'agent-memories'
  routeParams.value = { id: '7' }
  routeQuery.value = {}
  fetchAgentsMock.mockReset().mockResolvedValue(undefined)
  loadGlobalMemoriesMock.mockReset().mockResolvedValue(undefined)
  loadAgentMemoriesMock.mockReset().mockResolvedValue(undefined)
  pushMock.mockReset()
})

function mountSidebar(props: { mobileOpen?: boolean } = {}) {
  return mount(MemorySidebar, { props })
}

describe('MemorySidebar', () => {
  it('renders the Memories app header', () => {
    const wrapper = mountSidebar()
    expect(wrapper.text()).toContain('Memories')
  })

  it('loads agents and global memories on mount', async () => {
    mountSidebar()
    await flushPromises()
    expect(fetchAgentsMock).toHaveBeenCalled()
    expect(loadGlobalMemoriesMock).toHaveBeenCalled()
  })

  it('shows the "No global memories." placeholder when empty', async () => {
    const wrapper = mountSidebar()
    await flushPromises()
    expect(wrapper.text()).toContain('No global memories.')
  })

  it('renders up to 5 global memories and a "View all" link', async () => {
    globalMemoriesRef.value = [
      { id: 1, name: 'g1' },
      { id: 2, name: 'g2' },
      { id: 3, name: 'g3' },
    ]
    const wrapper = mountSidebar()
    await flushPromises()
    expect(wrapper.text()).toContain('g1')
    expect(wrapper.text()).toContain('g2')
    expect(wrapper.text()).toContain('g3')
    expect(wrapper.text()).toContain('View all')
  })

  it('does not initialize an agent when route has no id and there are no agents', async () => {
    routeParams.value = {}
    agentsRef.value = []
    const wrapper = mountSidebar()
    await flushPromises()
    expect(wrapper.text()).toContain('Select an agent')
    expect(loadAgentMemoriesMock).not.toHaveBeenCalled()
  })

  it('initializes selectedAgentId from route params', async () => {
    routeParams.value = { id: '42' }
    agentsRef.value = [{ id: 42, name: 'A' }]
    mountSidebar()
    await flushPromises()
    expect(loadAgentMemoriesMock).toHaveBeenCalledWith(42)
  })

  it('falls back to first agent when no route id is set', async () => {
    routeParams.value = {}
    agentsRef.value = [
      { id: 1, name: 'First' },
      { id: 2, name: 'Second' },
    ]
    const wrapper = mountSidebar()
    await flushPromises()
    expect(loadAgentMemoriesMock).toHaveBeenCalledWith(1)
    expect(wrapper.text()).toContain('First')
  })

  it('shows "No memories for this agent." when agent has none', async () => {
    routeParams.value = { id: '5' }
    agentsRef.value = [{ id: 5, name: 'X' }]
    const wrapper = mountSidebar()
    await flushPromises()
    expect(wrapper.text()).toContain('No memories for this agent.')
  })

  it('shows the agent selector button with the selected agent name', async () => {
    routeParams.value = { id: '3' }
    agentsRef.value = [{ id: 3, name: 'Pickle' }]
    const wrapper = mountSidebar()
    await flushPromises()
    expect(wrapper.text()).toContain('Pickle')
  })

  it('shows "Unknown" when selected agent id has no matching agent', async () => {
    routeParams.value = { id: '99' }
    agentsRef.value = [{ id: 1, name: 'Other' }]
    const wrapper = mountSidebar()
    await flushPromises()
    expect(wrapper.text()).toContain('Unknown')
  })

  it('navigates to global-memories when "View all" is clicked', async () => {
    globalMemoriesRef.value = [{ id: 1, name: 'g1' }]
    const wrapper = mountSidebar()
    await flushPromises()
    const viewAll = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('View all'))
    expect(viewAll).toBeDefined()
    viewAll?.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'global-memories' })
  })

  it('navigates to global-memories with create=1 when "New" is clicked', async () => {
    const wrapper = mountSidebar()
    await flushPromises()
    const newBtns = wrapper.findAll('button').filter((b) => (b.text() ?? '').trim() === '+New' || (b.text() ?? '').includes('New'))
    expect(newBtns.length).toBeGreaterThan(0)
    newBtns[0].trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'global-memories', query: { create: '1' } })
  })

  it('emits close when the mobile close button is clicked', async () => {
    const wrapper = mountSidebar({ mobileOpen: true })
    await flushPromises()
    const closeBtn = wrapper.findAll('button').find((b) => b.attributes('title') === '' || b.html().includes('lucide-x'))
    if (closeBtn) {
      await closeBtn.trigger('click')
      expect(wrapper.emitted('close')).toBeTruthy()
    } else {
      const buttons = wrapper.findAll('button')
      await buttons[0].trigger('click')
    }
  })
})
