/**
 * ScheduledRunsPage — list of scheduled runs for an agent.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

const fetchAgentMock = vi.fn().mockResolvedValue({ id: 1, name: 'Test' })
const fetchAgentsMock = vi.fn().mockResolvedValue(undefined)
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({
    fetchAgent: fetchAgentMock,
    fetchAgents: fetchAgentsMock,
  }),
}))

const confirmMock = vi.fn().mockResolvedValue(true)
vi.mock('@/composables/useConfirmDialog', () => ({
  useConfirmDialog: () => ({ confirm: confirmMock }),
}))

const AgentLayoutStub = { name: 'AgentLayout', template: '<div class="agent-layout-stub"><slot /></div>' }
const EditorStub = { name: 'SharedScheduleEditor', template: '<div class="editor-stub" />' }
const ToggleStub = {
  name: 'Toggle',
  props: ['modelValue'],
  emits: ['update:modelValue'],
  template: '<button role="switch" :aria-checked="modelValue" @click="$emit(\'update:modelValue\', !modelValue)" />',
}

import { api } from '@/api/client'
import ScheduledRunsPage from '@/pages/ScheduledRunsPage.vue'

const getMock = api.get as ReturnType<typeof vi.fn>
const postMock = api.post as ReturnType<typeof vi.fn>
const putMock = api.put as ReturnType<typeof vi.fn>
const deleteMock = api.delete as ReturnType<typeof vi.fn>

const sampleRun = (overrides: Partial<{ id: number; is_active: boolean; template_name: string | null; raw_prompt: string | null }> = {}) => ({
  id: 1, name: 'Daily', is_active: true, schedule_kind: 'recurring', template_name: null, template_id: null, raw_prompt: 'My run prompt', cron_expression: null, run_at: null, timezone: 'UTC', last_run_at: null, last_run_status: null, run_count: 0, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z', ...overrides,
})

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  getMock.mockImplementation((url: string) => {
    if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
    if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [] })
    return Promise.resolve({})
  })
  postMock.mockReset().mockResolvedValue({})
  putMock.mockReset().mockResolvedValue({})
  deleteMock.mockReset().mockResolvedValue({})
  confirmMock.mockReset().mockResolvedValue(true)
})

function mountPage() {
  return mount(ScheduledRunsPage, {
    global: { stubs: { AgentLayout: AgentLayoutStub, SharedScheduleEditor: EditorStub, Toggle: ToggleStub, Icon: true } },
  })
}

describe('ScheduledRunsPage', () => {
  it('mounts and fetches runs for the agent', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(getMock).toHaveBeenCalledWith(expect.stringContaining('/agents/1'))
  })

  it('renders the agent layout wrapper', () => {
    const wrapper = mountPage()
    expect(wrapper.find('.agent-layout-stub').exists()).toBe(true)
  })

  it('shows the empty state when there are no runs', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('No scheduled runs')
    expect(wrapper.text()).toContain('New Schedule')
  })

  it('renders scheduled runs when present', async () => {
    getMock.mockImplementation((url: string) => {
      if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
      if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [sampleRun({ id: 5 })] })
      return Promise.resolve({})
    })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('My run prompt')
  })

  it('shows an error banner when the load fails', async () => {
    getMock.mockImplementation(() => Promise.reject(new Error('boom')))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Failed to load scheduled runs')
  })

  it('toggles the active state of a run via PATCH', async () => {
    getMock.mockImplementation((url: string) => {
      if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
      if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [sampleRun({ id: 7, is_active: true })] })
      return Promise.resolve({})
    })
    putMock.mockResolvedValue({ scheduled_run: { id: 7, is_active: false } })
    const wrapper = mountPage()
    await flushPromises()
    const toggles = wrapper.findAll('button[role="switch"]')
    expect(toggles.length).toBeGreaterThan(0)
    await toggles[0].trigger('click')
    await flushPromises()
    expect(putMock).toHaveBeenCalledWith(expect.stringContaining('/scheduled-runs/7'), { is_active: false })
  })

  it('opens the editor when "New Schedule" is clicked', async () => {
    const wrapper = mountPage()
    await flushPromises()
    const newBtn = wrapper.find('[data-testid="open-schedule-editor-empty"]')
    expect(newBtn.exists()).toBe(true)
    await newBtn.trigger('click')
    expect(wrapper.find('.editor-stub').exists()).toBe(true)
  })

  it('triggers a run when the lightning button is clicked', async () => {
    getMock.mockImplementation((url: string) => {
      if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
      if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [sampleRun({ id: 9 })] })
      return Promise.resolve({})
    })
    postMock.mockResolvedValue({ scheduled_run: sampleRun({ id: 9 }) })
    const wrapper = mountPage()
    await flushPromises()
    const triggerBtn = wrapper.find('button[title="Trigger now"]')
    expect(triggerBtn.exists()).toBe(true)
    await triggerBtn.trigger('click')
    await flushPromises()
    expect(postMock).toHaveBeenCalledWith(expect.stringContaining('/scheduled-runs/9/trigger'))
  })

  it('opens the editor when the edit button is clicked', async () => {
    getMock.mockImplementation((url: string) => {
      if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
      if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [sampleRun({ id: 11 })] })
      return Promise.resolve({})
    })
    const wrapper = mountPage()
    await flushPromises()
    const editBtn = wrapper.find('button[title="Edit"]')
    expect(editBtn.exists()).toBe(true)
    await editBtn.trigger('click')
    expect(wrapper.find('.editor-stub').exists()).toBe(true)
  })

  it('deletes a run after confirming the dialog', async () => {
    getMock.mockImplementation((url: string) => {
      if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
      if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [sampleRun({ id: 11 })] })
      return Promise.resolve({})
    })
    deleteMock.mockResolvedValue({})
    const wrapper = mountPage()
    await flushPromises()
    const delBtn = wrapper.find('button[title="Delete"]')
    expect(delBtn.exists()).toBe(true)
    await delBtn.trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(deleteMock).toHaveBeenCalledWith(expect.stringContaining('/scheduled-runs/11'))
  })

  it('does not delete when the confirm dialog is cancelled', async () => {
    confirmMock.mockResolvedValue(false)
    getMock.mockImplementation((url: string) => {
      if (url.endsWith('/agents/1')) return Promise.resolve({ agent: { id: 1, name: 'Test' } })
      if (url.includes('/scheduled-runs')) return Promise.resolve({ scheduled_runs: [sampleRun({ id: 11 })] })
      return Promise.resolve({})
    })
    const wrapper = mountPage()
    await flushPromises()
    const delBtn = wrapper.find('button[title="Delete"]')
    await delBtn.trigger('click')
    await flushPromises()
    expect(deleteMock).not.toHaveBeenCalled()
  })
})
