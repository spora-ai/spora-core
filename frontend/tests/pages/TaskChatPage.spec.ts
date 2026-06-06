/**
 * TaskChatPage — thin shell over the TaskChat sub-components.
 *
 * Mounts the page with stubbed sub-components to assert the layout wiring
 * (loading state, sub-component presence) without duplicating the per-
 * sub-component assertions covered in `tests/components/agent/TaskChat/`.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref } from 'vue'

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
const isTerminal = false

vi.mock('@/stores/tasks', () => ({
  useTaskStore: () => ({
    get activeTask() { return activeTaskRef.value },
    get pendingToolCalls() { return pendingToolCallsRef.value },
    stopDetailPolling,
    clearActiveTask,
    fetchTaskDetail,
    startDetailPolling: vi.fn(),
    get isTerminal() { return isTerminal },
  }),
}))

vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    currentAgent: null,
    fetchAgents: vi.fn().mockResolvedValue(undefined),
    fetchAgent: vi.fn().mockResolvedValue(undefined),
  }),
}))

const AgentLayoutStub = { name: 'AgentLayout', template: '<div class="agent-layout-stub"><slot /></div>' }
const TaskStatusBadgeStub = { name: 'TaskStatusBadge', template: '<span class="badge-stub" />' }
const TaskChatBannersStub = { name: 'TaskChatBanners', template: '<div class="banners-stub" />' }
const TaskChatMessageListStub = { name: 'TaskChatMessageList', template: '<div class="message-list-stub" />' }
const TaskChatFollowupStub = { name: 'TaskChatFollowup', template: '<div class="followup-stub" />' }
const ToolApprovalBarStub = { name: 'ToolApprovalBar', template: '<div class="approval-bar-stub" />' }

import TaskChatPage from '@/pages/TaskChatPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  activeTaskRef.value = null
  pendingToolCallsRef.value = []
  stopDetailPolling.mockReset()
  clearActiveTask.mockReset()
  fetchTaskDetail.mockReset()
  fetchTaskDetail.mockResolvedValue(false)
})

describe('TaskChatPage', () => {
  it('shows the loading state when no task is loaded', () => {
    const wrapper = mount(TaskChatPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          TaskStatusBadge: TaskStatusBadgeStub,
          TaskChatBanners: TaskChatBannersStub,
          TaskChatMessageList: TaskChatMessageListStub,
          TaskChatFollowup: TaskChatFollowupStub,
          ToolApprovalBar: ToolApprovalBarStub,
        },
      },
    })
    expect(wrapper.text()).toContain('Loading')
  })

  it('renders the sub-components when a task is loaded', () => {
    activeTaskRef.value = {
      id: 1,
      agent_id: 1,
      status: 'COMPLETED',
      user_prompt: 'Hi',
      final_response: null,
      step_count: 0,
      max_steps: 10,
      history: [],
      tool_calls: [],
    }
    const wrapper = mount(TaskChatPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          TaskStatusBadge: TaskStatusBadgeStub,
          TaskChatBanners: TaskChatBannersStub,
          TaskChatMessageList: TaskChatMessageListStub,
          TaskChatFollowup: TaskChatFollowupStub,
          ToolApprovalBar: ToolApprovalBarStub,
        },
      },
    })
    expect(wrapper.find('.banners-stub').exists()).toBe(true)
    expect(wrapper.find('.message-list-stub').exists()).toBe(true)
  })

  it('mounts without throwing', () => {
    expect(() => mount(TaskChatPage, {
      global: {
        stubs: {
          AgentLayout: AgentLayoutStub,
          TaskStatusBadge: TaskStatusBadgeStub,
          TaskChatBanners: TaskChatBannersStub,
          TaskChatMessageList: TaskChatMessageListStub,
          TaskChatFollowup: TaskChatFollowupStub,
          ToolApprovalBar: ToolApprovalBarStub,
        },
      },
    })).not.toThrow()
  })
})
