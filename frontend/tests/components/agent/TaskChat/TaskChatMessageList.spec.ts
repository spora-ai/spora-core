/**
 * TaskChatMessageList — scrollable chat history.
 *
 * Asserts the user/assistant/tool bubbles, the running indicator, the
 * final-response pill, the failed banner, and the scroll-to-bottom ref.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, vi } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import TaskChatMessageList from '@/components/agent/TaskChat/TaskChatMessageList.vue'
import TaskFailedBanner from '@/components/agent/TaskFailedBanner.vue'
import type { TaskDetail, HistoryEntry, ToolCall } from '@/types/task'
import type { ChatMessage } from '@/composables/useTaskChat'

vi.mock('@/composables/useMarkdown', () => ({
  renderMarkdown: (text: string) => text,
}))

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div />' } },
      { path: '/tasks/:id', name: 'task', component: { template: '<div />' } },
    ],
  })
}

const baseTask: TaskDetail = {
  id: 1,
  agent_id: 1,
  status: 'COMPLETED',
  user_prompt: 'hi',
  final_response: null,
  step_count: 0,
  max_steps: 10,
  error_code: null,
  error_message: null,
  failure_reason: null,
  history: [],
  tool_calls: [],
  created_at: '',
  updated_at: '',
}

function makeEntry(role: HistoryEntry['role'], overrides: Partial<HistoryEntry> = {}): HistoryEntry {
  return {
    sequence: 0,
    role,
    content: 'content',
    reasoning: null,
    tool_call_id: null,
    tool_name: null,
    ...overrides,
  }
}

describe('TaskChatMessageList', () => {
  it('renders the user/assistant/tool bubbles from chatMessages', () => {
    const messages: ChatMessage[] = [
      { kind: 'user', entry: makeEntry('user', { sequence: 1, content: 'hello' }) },
      { kind: 'assistant', entry: makeEntry('assistant', { sequence: 2, content: 'hi there' }) },
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 3, content: 'tool result', tool_name: 'web_search' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: baseTask, chatMessages: messages, finalReasoning: null },
    })
    expect(wrapper.text()).toContain('hello')
    expect(wrapper.text()).toContain('hi there')
    expect(wrapper.text()).toContain('web_search')
  })

  it('renders the running indicator for RUNNING tasks', () => {
    const wrapper = mount(TaskChatMessageList, {
      props: {
        task: { ...baseTask, status: 'RUNNING' },
        chatMessages: [],
        finalReasoning: null,
      },
    })
    expect(wrapper.find('.animate-bounce').exists()).toBe(true)
  })

  it('renders the final-response pill for COMPLETED tasks with a final_response', () => {
    const wrapper = mount(TaskChatMessageList, {
      props: {
        task: { ...baseTask, status: 'COMPLETED', final_response: 'The answer is 42.' },
        chatMessages: [],
        finalReasoning: null,
      },
    })
    expect(wrapper.text()).toContain('The answer is 42.')
  })

  it('renders the failed banner for FAILED tasks', () => {
    const wrapper = mount(TaskChatMessageList, {
      props: {
        task: { ...baseTask, status: 'FAILED' },
        chatMessages: [],
        finalReasoning: null,
      },
    })
    // TaskFailedBanner is rendered
    expect(wrapper.findComponent(TaskFailedBanner).exists()).toBe(true)
  })

  it('emits toggleExpanded when a truncated tool result is expanded', async () => {
    const longContent = 'x'.repeat(400)
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 5, content: longContent, tool_name: 'web_search' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: baseTask, chatMessages: messages, finalReasoning: null },
    })
    const button = wrapper.find('button')
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('▼ more')
    await button.trigger('click')
    expect(wrapper.emitted('toggleExpanded')).toBeTruthy()
    expect(wrapper.emitted('toggleExpanded')![0]).toEqual([5])
  })

  it('renders the full content and flips the label to "less" when expandedTools[seq] is true', () => {
    const longContent = 'x'.repeat(400) + 'TAIL_MARKER'
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 5, content: longContent, tool_name: 'web_search' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: {
        task: baseTask,
        chatMessages: messages,
        finalReasoning: null,
        expandedTools: { 5: true },
      },
    })
    expect(wrapper.text()).toContain('TAIL_MARKER')
    expect(wrapper.find('button').text()).toBe('▲ less')
  })

  it('keeps the truncated preview when expandedTools[seq] is false', () => {
    const longContent = 'x'.repeat(400) + 'TAIL_MARKER'
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 5, content: longContent, tool_name: 'web_search' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: {
        task: baseTask,
        chatMessages: messages,
        finalReasoning: null,
        expandedTools: { 5: false },
      },
    })
    expect(wrapper.text()).not.toContain('TAIL_MARKER')
    expect(wrapper.find('button').text()).toBe('▼ more')
  })

  it('flips from "more" to "less" when the parent updates expandedTools in response to the emit', async () => {
    const longContent = 'x'.repeat(400) + 'TAIL_MARKER'
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 5, content: longContent, tool_name: 'web_search' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: baseTask, chatMessages: messages, finalReasoning: null, expandedTools: { 5: false } },
    })
    expect(wrapper.find('button').text()).toBe('▼ more')
    await wrapper.find('button').trigger('click')
    await wrapper.setProps({ expandedTools: { 5: true } })
    expect(wrapper.find('button').text()).toBe('▲ less')
    expect(wrapper.text()).toContain('TAIL_MARKER')
  })

  it('exposes scrollToBottom via defineExpose', () => {
    const wrapper = mount(TaskChatMessageList, {
      props: { task: baseTask, chatMessages: [], finalReasoning: null },
    })
    const exposed = wrapper.vm as unknown as { scrollToBottom?: () => void }
    expect(typeof exposed.scrollToBottom).toBe('function')
  })

  it('renders finalReasoning foldout when set', () => {
    const wrapper = mount(TaskChatMessageList, {
      props: { task: baseTask, chatMessages: [], finalReasoning: 'because reasons' },
    })
    expect(wrapper.text()).toContain('because reasons')
    expect(wrapper.text()).toContain('Reasoning')
  })

  // Regression: the "more" button lives inside a <details>. Without
  // .prevent the native <details> toggle swallows the click and the
  // user sees nothing happen.
  it('emits toggleExpanded without closing the parent <details>', async () => {
    const longContent = 'x'.repeat(400)
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 7, content: longContent, tool_name: 'web_search' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: baseTask, chatMessages: messages, finalReasoning: null },
    })
    const details = wrapper.find('details')
    expect(details.exists()).toBe(true)
    details.element.setAttribute('open', '')
    expect((details.element as HTMLDetailsElement).open).toBe(true)
    const button = wrapper.find('button')
    expect(button.text()).toBe('▼ more')
    await button.trigger('click')
    expect(wrapper.emitted('toggleExpanded')).toBeTruthy()
    expect((details.element as HTMLDetailsElement).open).toBe(true)
  })
})

function makeToolCall(overrides: Partial<ToolCall> = {}): ToolCall {
  return {
    id: 1,
    provider_call_id: 'pc_1',
    tool_name: 'handover',
    tool_type: 'handover',
    operation: null,
    operation_description: null,
    status: 'EXECUTED',
    proposed_arguments: null,
    approved_arguments: null,
    human_description: null,
    result_content: 'Handed over.',
    executed_at: null,
    ...overrides,
  }
}

describe('TaskChatMessageList — tool-call deep link', () => {
  const router = makeRouter()
  const global = { plugins: [router] }

  it('renders an "Open chat #X" RouterLink when result_data.new_task_id is set', () => {
    const toolCall = makeToolCall({
      result_data: { new_task_id: 42, handover: true },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Handed over.', tool_name: 'handover', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    const link = wrapper.find('a')
    expect(link.exists()).toBe(true)
    expect(link.text()).toContain('Open chat #42')
    expect(link.text()).toContain('→')
  })

  it('prefixes the link text with "Handed off —" when result_data.handover is true', () => {
    const toolCall = makeToolCall({
      result_data: { new_task_id: 42, handover: true },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Handed over.', tool_name: 'handover', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    const text = wrapper.find('a').text()
    expect(text).toContain('Handed off')
    expect(text).toContain('Open chat #42')
  })

  it('omits the "Handed off —" prefix when result_data.handover is not true', () => {
    const toolCall = makeToolCall({
      result_data: { new_task_id: 42 },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Done.', tool_name: 'generic_tool', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    const link = wrapper.find('a')
    expect(link.exists()).toBe(true)
    expect(link.text()).not.toContain('Handed off')
    expect(link.text()).toContain('Open chat #42')
  })

  it('falls back to task_id when new_task_id is absent', () => {
    const toolCall = makeToolCall({
      result_data: { task_id: 99 },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Done.', tool_name: 'generic_tool', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    const link = wrapper.find('a')
    expect(link.exists()).toBe(true)
    expect(link.text()).toContain('Open chat #99')
  })

  it('does not render a link when result_data has neither new_task_id nor task_id', () => {
    const toolCall = makeToolCall({
      result_data: { foo: 'bar' },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Done.', tool_name: 'web_search', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('does not render a link when result_data is absent', () => {
    const toolCall = makeToolCall({})
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Done.', tool_name: 'web_search', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('does not render a link when the history row has no tool_call_id', () => {
    const toolCall = makeToolCall({
      result_data: { new_task_id: 42 },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Done.', tool_name: 'web_search', tool_call_id: null }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('uses the "task" route name with the new_task_id as a string param', () => {
    const toolCall = makeToolCall({
      result_data: { new_task_id: 7 },
    })
    const messages: ChatMessage[] = [
      { kind: 'tool-result', entry: makeEntry('tool', { sequence: 1, content: 'Done.', tool_name: 'handover', tool_call_id: 'pc_1' }) },
    ]
    const wrapper = mount(TaskChatMessageList, {
      props: { task: { ...baseTask, tool_calls: [toolCall] }, chatMessages: messages, finalReasoning: null },
      global,
    })
    const link = wrapper.find('a')
    expect(link.attributes('href')).toBe('/tasks/7')
  })
})
