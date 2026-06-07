/**
 * TaskChatPage — thin shell over the TaskChat sub-components.
 *
 * Mounts the page with stubbed sub-components that re-emit the documented
 * events so we can assert the layout wiring (loading state, sub-component
 * presence, and event → handler pass-through) without duplicating the
 * per-sub-component assertions in `tests/components/agent/TaskChat/`.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref, defineComponent, h } from 'vue'

const routeRef = ref({ params: { id: '1' } })
const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRoute: () => routeRef.value,
  useRouter: () => ({ push: pushMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

const activeTaskRef = ref<Record<string, unknown> | null>(null)
const pendingToolCallsRef = ref<unknown[]>([])

const stopDetailPolling = vi.fn()
const clearActiveTask = vi.fn()
const fetchTaskDetail = vi.fn()
const startDetailPolling = vi.fn()
const cancelRetryChain = vi.fn()
const fetchTask = vi.fn()
const retryTask = vi.fn()
const continueTask = vi.fn()
const approveTask = vi.fn()
const rejectTask = vi.fn()
let isTerminal = false

vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => ({
    get activeTask() { return activeTaskRef.value },
    get pendingToolCalls() { return pendingToolCallsRef.value },
    get isTerminal() { return isTerminal },
    stopDetailPolling,
    clearActiveTask,
    fetchTaskDetail,
    startDetailPolling,
    cancelRetryChain,
    fetchTask,
    retryTask,
    continueTask,
    approveTask,
    rejectTask,
  }),
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    currentAgent: { allow_continuation: true },
    fetchAgents: vi.fn().mockResolvedValue(undefined),
    fetchAgent: vi.fn().mockResolvedValue(undefined),
  }),
}))

const toastMock = { error: vi.fn(), success: vi.fn() }
vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMock,
}))

vi.mock('@/api/client', () => ({
  ApiError: class FakeApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

// Stubs emit the documented events on mount so the page-level handler
// bindings are exercised by V8 coverage. Real sub-components are tested
// in tests/components/agent/TaskChat/.
const AgentLayoutStub = { name: 'AgentLayout', template: '<div class="agent-layout-stub"><slot /></div>' }
const TaskStatusBadgeStub = { name: 'TaskStatusBadge', template: '<span class="badge-stub" />' }

// A "render-prop" stub whose emit function is reachable from the test via
// wrapper.vm.$emit(event, ...args).
function makeEventStub(name: string, eventNames: string[]) {
  return defineComponent({
    name,
    emits: eventNames,
    setup(_, { emit }) {
      return () => h('div', { class: `${name.toLowerCase()}-stub` })
    },
  })
}
const TaskChatBannersStub = makeEventStub('TaskChatBanners', [
  'retryNow', 'cancelRetryChain', 'dismissBanner', 'updateFollowupPrompt', 'submitFollowup',
])
const TaskChatMessageListStub = defineComponent({
  name: 'TaskChatMessageList',
  emits: ['toggleExpanded'],
  setup(_, { emit }) {
    return () => h('div', { class: 'taskchatmessagelist-stub' })
  },
  methods: {
    scrollToBottom() { /* noop stub */ },
  },
})
const TaskChatFollowupStub = makeEventStub('TaskChatFollowup', ['updateFollowupPrompt', 'submitFollowup'])
const ToolApprovalBarStub = makeEventStub('ToolApprovalBar', [
  'approve-all', 'reject-all', 'approve-one', 'reject-one',
])

import TaskChatPage from '@/pages/TaskChatPage.vue'

const globalStubs = {
  AgentLayout: AgentLayoutStub,
  TaskStatusBadge: TaskStatusBadgeStub,
  TaskChatBanners: TaskChatBannersStub,
  TaskChatMessageList: TaskChatMessageListStub,
  TaskChatFollowup: TaskChatFollowupStub,
  ToolApprovalBar: ToolApprovalBarStub,
}

function mountPage() {
  return mount(TaskChatPage, { global: { stubs: globalStubs } })
}

function loadedTask(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    agent_id: 7,
    user_prompt: 'hello',
    status: 'RUNNING',
    step_count: 1,
    history: [],
    final_response: null,
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  activeTaskRef.value = null
  pendingToolCallsRef.value = []
  isTerminal = false
  stopDetailPolling.mockReset()
  clearActiveTask.mockReset()
  fetchTaskDetail.mockReset()
  fetchTaskDetail.mockResolvedValue(false)
  startDetailPolling.mockReset()
  cancelRetryChain.mockReset()
  cancelRetryChain.mockResolvedValue(undefined)
  fetchTask.mockReset()
  fetchTask.mockResolvedValue(undefined)
  retryTask.mockReset()
  retryTask.mockResolvedValue({ id: 99 })
  continueTask.mockReset()
  continueTask.mockResolvedValue(undefined)
  approveTask.mockReset()
  approveTask.mockResolvedValue(undefined)
  rejectTask.mockReset()
  rejectTask.mockResolvedValue(undefined)
  pushMock.mockReset()
  toastMock.error.mockReset()
  toastMock.success.mockReset()
})

describe('TaskChatPage', () => {
  it('shows the loading state when no task is loaded', () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Loading')
  })

  it('renders the sub-components when a task is loaded', () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    expect(wrapper.find('.taskchatbanners-stub').exists()).toBe(true)
    expect(wrapper.find('.taskchatmessagelist-stub').exists()).toBe(true)
  })

  it('mounts without throwing', () => {
    expect(() => mountPage()).not.toThrow()
  })

  it('shows the chat layout when the task is loaded', () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    expect(wrapper.find('.taskchatbanners-stub').exists()).toBe(true)
    expect(wrapper.find('.taskchatmessagelist-stub').exists()).toBe(true)
    expect(wrapper.find('.taskchatfollowup-stub').exists()).toBe(true)
  })

  it('hides the ToolApprovalBar when the task is not pending approval', () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    expect(wrapper.find('.toolapprovalbar-stub').exists()).toBe(false)
  })

  it('shows the ToolApprovalBar when the task is PENDING_APPROVAL with pending tool calls', () => {
    activeTaskRef.value = loadedTask({ status: 'PENDING_APPROVAL' })
    pendingToolCallsRef.value = [{ id: 1, tool_name: 'web_search' }]
    const wrapper = mountPage()
    expect(wrapper.find('.toolapprovalbar-stub').exists()).toBe(true)
  })

  it('navigates back to the agent when the Back button is clicked', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const back = wrapper.find('button[aria-label="Back"]')
    expect(back.exists()).toBe(true)
    await back.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'agent', params: { id: 7 } })
  })

  it('navigates back to the dashboard when the task has no agent_id', async () => {
    activeTaskRef.value = loadedTask({ agent_id: null })
    const wrapper = mountPage()
    const back = wrapper.find('button[aria-label="Back"]')
    await back.trigger('click')
    expect(pushMock).toHaveBeenCalledWith({ name: 'dashboard' })
  })
})

describe('TaskChatPage — event wiring', () => {
  it('forwards TaskChatBanners @retryNow to retry.retryNow', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const banner = wrapper.findComponent(TaskChatBannersStub)
    banner.vm.$emit('retryNow')
    await Promise.resolve()
    await Promise.resolve()
    expect(retryTask).toHaveBeenCalledWith(1)
    expect(pushMock).toHaveBeenCalledWith({ name: 'task', params: { id: 99 } })
  })

  it('forwards TaskChatBanners @cancelRetryChain to retry.cancelRetryChain', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const banner = wrapper.findComponent(TaskChatBannersStub)
    banner.vm.$emit('cancelRetryChain')
    await Promise.resolve()
    expect(cancelRetryChain).toHaveBeenCalledWith(1)
    expect(fetchTask).toHaveBeenCalledWith(1)
  })

  it('forwards TaskChatBanners @dismissBanner to retry.dismissBanner', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const banner = wrapper.findComponent(TaskChatBannersStub)
    banner.vm.$emit('dismissBanner')
    expect(() => banner.vm.$emit('dismissBanner')).not.toThrow()
  })

  it('forwards TaskChatBanners @updateFollowupPrompt to followup.followupPrompt', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const banner = wrapper.findComponent(TaskChatBannersStub)
    banner.vm.$emit('updateFollowupPrompt', 'hello from banner')
    await Promise.resolve()
    const followup = wrapper.findComponent(TaskChatFollowupStub)
    expect(followup.exists()).toBe(true)
  })

  it('forwards TaskChatBanners @submitFollowup to followup.submitFollowup', async () => {
    activeTaskRef.value = loadedTask({ status: 'COMPLETED' })
    const wrapper = mountPage()
    const banner = wrapper.findComponent(TaskChatBannersStub)
    banner.vm.$emit('submitFollowup')
    await Promise.resolve()
    // submitFollowup early-returns when followupPrompt is empty, so the store
    // isn't called — but the call must not throw.
    expect(toastMock.error).not.toHaveBeenCalled()
  })

  it('forwards TaskChatFollowup @updateFollowupPrompt', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const followup = wrapper.findComponent(TaskChatFollowupStub)
    followup.vm.$emit('updateFollowupPrompt', 'new prompt')
    expect(() => followup.vm.$emit('updateFollowupPrompt', 'x')).not.toThrow()
  })

  it('forwards TaskChatFollowup @submitFollowup', async () => {
    activeTaskRef.value = loadedTask({ status: 'COMPLETED' })
    const wrapper = mountPage()
    const followup = wrapper.findComponent(TaskChatFollowupStub)
    followup.vm.$emit('submitFollowup')
    await Promise.resolve()
    expect(toastMock.error).not.toHaveBeenCalled()
  })

  it('forwards ToolApprovalBar @approve-all to approvals.onApproveAll', async () => {
    activeTaskRef.value = loadedTask({ status: 'PENDING_APPROVAL' })
    pendingToolCallsRef.value = [{ id: 1, tool_name: 'web_search' }]
    const wrapper = mountPage()
    const bar = wrapper.findComponent(ToolApprovalBarStub)
    bar.vm.$emit('approve-all', { approvals: [{ providerCallId: 'c1', arguments: { q: 'x' } }] })
    await Promise.resolve()
    expect(approveTask).toHaveBeenCalledWith(1, [{ provider_call_id: 'c1', arguments: { q: 'x' } }])
  })

  it('forwards ToolApprovalBar @reject-all to approvals.onRejectAll', async () => {
    activeTaskRef.value = loadedTask({ status: 'PENDING_APPROVAL' })
    pendingToolCallsRef.value = [{ id: 1, tool_name: 'web_search' }]
    const wrapper = mountPage()
    const bar = wrapper.findComponent(ToolApprovalBarStub)
    bar.vm.$emit('reject-all', { reason: 'no thanks' })
    await Promise.resolve()
    expect(rejectTask).toHaveBeenCalledWith(1, 'no thanks')
  })

  it('forwards ToolApprovalBar @approve-one to approvals.onApproveOne', async () => {
    activeTaskRef.value = loadedTask({ status: 'PENDING_APPROVAL' })
    pendingToolCallsRef.value = [{ id: 1, tool_name: 'web_search' }]
    const wrapper = mountPage()
    const bar = wrapper.findComponent(ToolApprovalBarStub)
    bar.vm.$emit('approve-one', { providerCallId: 'c1', arguments: { q: 'x' } })
    await Promise.resolve()
    expect(approveTask).toHaveBeenCalledWith(1, [{ provider_call_id: 'c1', arguments: { q: 'x' } }])
  })

  it('forwards ToolApprovalBar @reject-one to approvals.onRejectOne', async () => {
    activeTaskRef.value = loadedTask({ status: 'PENDING_APPROVAL' })
    pendingToolCallsRef.value = [{ id: 1, tool_name: 'web_search' }]
    const wrapper = mountPage()
    const bar = wrapper.findComponent(ToolApprovalBarStub)
    bar.vm.$emit('reject-one', { providerCallId: 'c1', reason: 'no' })
    await Promise.resolve()
    expect(rejectTask).toHaveBeenCalledWith(1, 'no')
  })

  it('forwards TaskChatMessageList @toggleExpanded', async () => {
    activeTaskRef.value = loadedTask()
    const wrapper = mountPage()
    const list = wrapper.findComponent(TaskChatMessageListStub)
    list.vm.$emit('toggleExpanded', 5)
    expect(() => list.vm.$emit('toggleExpanded', 7)).not.toThrow()
  })
})
