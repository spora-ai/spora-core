/**
 * AgentPage — agent detail with composer + task history.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const fetchAgentsMock = vi.fn().mockResolvedValue(undefined)
const fetchAgentMock = vi.fn().mockResolvedValue(undefined)
const fetchAgentTasksMock = vi.fn().mockResolvedValue(undefined)
const deleteTaskMock = vi.fn().mockResolvedValue(undefined)
const clearCurrentAgentMock = vi.fn()
const loadMoreTasksMock = vi.fn().mockResolvedValue(undefined)

const currentAgentTasksRef = (globalThis as { __tasks?: unknown[] }).__tasks ?? []
const tasksLoadingRef = (globalThis as { __tasksLoading?: boolean }).__tasksLoading ?? false
const tasksHasMoreRef = (globalThis as { __tasksHasMore?: boolean }).__tasksHasMore ?? false

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

const { apiGetMock } = vi.hoisted(() => ({ apiGetMock: vi.fn() }))
vi.mock('@/api/client', () => ({
  api: { get: apiGetMock },
  ApiError: class ApiError extends Error { constructor(public m: string) { super(m) } },
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    get agents() { return [{ id: 1, name: 'Test', description: '', tools: [] }] },
    get currentAgent() { return { id: 1, name: 'Test', description: '', tools: [], llm_config: { provider: 'openai' } } },
    get currentAgentTasks() { return currentAgentTasksRef },
    get tasksLoading() { return tasksLoadingRef },
    get tasksHasMore() { return tasksHasMoreRef },
    fetchAgents: fetchAgentsMock,
    fetchAgent: fetchAgentMock,
    fetchAgentTasks: fetchAgentTasksMock,
    loadMoreTasks: loadMoreTasksMock,
    deleteTask: deleteTaskMock,
    clearCurrentAgent: clearCurrentAgentMock,
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
  apiGetMock.mockResolvedValue({ llm_config: { provider: 'openai' } })
  currentAgentTasksRef.length = 0
})

function mountPage() {
  return mount(AgentPage, {
    global: {
      stubs: { AgentLayout: AgentLayoutStub, ComposerInput: ComposerInputStub, RouterLink: true, TaskStatusBadge: true, Icon: true },
    },
  })
}

describe('AgentPage', () => {
  it('mounts and renders the agent layout and composer', () => {
    const wrapper = mountPage()
    expect(wrapper.find('.agent-layout-stub').exists()).toBe(true)
    expect(wrapper.find('.composer-stub').exists()).toBe(true)
  })

  it('shows an empty-state when the agent has no tasks', () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toMatch(/no messages yet|start a conversation/i)
  })

  it('fetches agent data on mount', async () => {
    mountPage()
    await flushPromises()
    expect(fetchAgentsMock).toHaveBeenCalled()
    expect(fetchAgentMock).toHaveBeenCalledWith(1)
    expect(fetchAgentTasksMock).toHaveBeenCalled()
  })

  it('renders a task row with status badge for existing tasks', async () => {
    currentAgentTasksRef.push(
      { id: 7, status: 'COMPLETED', user_prompt: 'Hello world', updated_at: new Date().toISOString(), step_count: 2 },
      { id: 8, status: 'RUNNING', user_prompt: 'In progress', updated_at: new Date().toISOString(), step_count: 0 },
    )
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Hello world')
    expect(wrapper.text()).toContain('In progress')
  })

  it('shows the trash button for each task', async () => {
    currentAgentTasksRef.push({ id: 7, status: 'COMPLETED', user_prompt: 'X', updated_at: new Date().toISOString(), step_count: 0 })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('button[title="Delete conversation"]').exists()).toBe(true)
  })

  it('opens inline delete confirmation and cancels', async () => {
    currentAgentTasksRef.push({ id: 7, status: 'COMPLETED', user_prompt: 'X', updated_at: new Date().toISOString(), step_count: 0 })
    const wrapper = mountPage()
    await flushPromises()
    const trash = wrapper.find('button[title="Delete conversation"]')
    await trash.trigger('click')
    expect(wrapper.text()).toContain('Delete this conversation')
    const cancel = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Cancel')
    await cancel!.trigger('click')
    expect(wrapper.text()).not.toContain('Delete this conversation')
  })

  it('confirms delete by calling agentStore.deleteTask', async () => {
    currentAgentTasksRef.push({ id: 7, status: 'COMPLETED', user_prompt: 'X', updated_at: new Date().toISOString(), step_count: 0 })
    const wrapper = mountPage()
    await flushPromises()
    const trash = wrapper.find('button[title="Delete conversation"]')
    await trash.trigger('click')
    const confirmBtn = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Delete')
    await confirmBtn!.trigger('click')
    await flushPromises()
    expect(deleteTaskMock).toHaveBeenCalledWith(7)
  })
})
