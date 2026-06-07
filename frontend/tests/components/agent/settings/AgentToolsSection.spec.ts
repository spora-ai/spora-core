/**
 * AgentToolsSection — tool registry grouped by category + enable/disable.
 *
 * Mocks the agent store, the tool-settings composable, and the api client.
 * Stubs AgentToolListItem, AgentToolConfigModal, and EnableWarningModal
 * since they're external dependencies that the page already wires elsewhere.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
  api: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

const agentStoreMock = {
  enableTool: vi.fn(),
  disableTool: vi.fn(),
  getAllOperationOverrides: vi.fn(),
  patchOperationOverride: vi.fn(),
}
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => agentStoreMock,
}))

const toolSettingsMock = {
  getAllToolStatuses: vi.fn(),
  getToolStatus: vi.fn(),
}
vi.mock('@/composables/useToolSettings', () => ({
  useToolSettings: () => toolSettingsMock,
}))

import AgentToolsSection from '@/components/agent/settings/AgentToolsSection.vue'
import { api } from '@/api/client'

const ListItemStub = {
  name: 'AgentToolListItem',
  props: ['tool', 'enabled', 'saving', 'missingRequired', 'operationStates'],
  emits: ['toggle', 'openConfig', 'toggleOperationEnabled', 'toggleOperationAutoApprove'],
  template: `
    <div class="tool-item" :data-tool-name="tool.tool_name" :data-enabled="enabled" :data-saving="saving">
      <button class="toggle" @click="$emit('toggle')">Toggle</button>
      <button class="config" @click="$emit('openConfig')">Config</button>
      <button class="op-enabled" @click="$emit('toggleOperationEnabled', 'op1')">Op1</button>
      <button class="op-auto" @click="$emit('toggleOperationAutoApprove', 'op1')">OpAuto</button>
    </div>
  `,
}
const ConfigModalStub = {
  name: 'AgentToolConfigModal',
  props: ['toolName', 'tool', 'agentId'],
  emits: ['saved', 'close'],
  template: '<div v-if="toolName" class="config-modal-stub"></div>',
}
const WarningModalStub = {
  name: 'EnableWarningModal',
  props: ['toolName', 'missingRequired'],
  emits: ['configure', 'close'],
  template: '<div v-if="toolName" class="warning-modal-stub"></div>',
}

const baseAgent = { id: 1, tools: [] }
const baseRegistry = [
  { tool_class: 'Spora\\Tools\\WebSearch', tool_name: 'web_search', display_name: 'Web Search', description: '', category: 'web', settings_schema: [] },
  { tool_class: 'Spora\\Tools\\Email', tool_name: 'send_email', display_name: 'Send Email', description: '', category: 'communication', settings_schema: [] },
]

beforeEach(() => {
  vi.mocked(api.get).mockReset()
  vi.mocked(api.get).mockResolvedValue({ tools: baseRegistry })
  agentStoreMock.enableTool.mockReset()
  agentStoreMock.enableTool.mockResolvedValue(undefined)
  agentStoreMock.disableTool.mockReset()
  agentStoreMock.disableTool.mockResolvedValue(undefined)
  agentStoreMock.getAllOperationOverrides.mockReset()
  agentStoreMock.getAllOperationOverrides.mockResolvedValue({})
  agentStoreMock.patchOperationOverride.mockReset()
  agentStoreMock.patchOperationOverride.mockResolvedValue(undefined)
  toolSettingsMock.getAllToolStatuses.mockReset()
  toolSettingsMock.getAllToolStatuses.mockResolvedValue({})
  toolSettingsMock.getToolStatus.mockReset()
  toolSettingsMock.getToolStatus.mockResolvedValue(null)
})

describe('AgentToolsSection', () => {
  it('loads tools and renders them grouped by category', async () => {
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    const items = wrapper.findAll('.tool-item')
    expect(items).toHaveLength(2)
    expect(wrapper.text()).toContain('Web')
    expect(wrapper.text()).toContain('Communication')
  })

  it('shows "No tools registered" when registry is empty', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({ tools: [] })
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('No tools registered')
  })

  it('disables a tool when the user toggles an enabled tool', async () => {
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    const item = wrapper.find('[data-tool-name="web_search"]')
    expect(item.attributes('data-enabled')).toBe('true')
    await item.find('.toggle').trigger('click')
    await flushPromises()
    expect(agentStoreMock.disableTool).toHaveBeenCalledWith(1, 'web_search')
    expect(item.attributes('data-enabled')).toBe('false')
  })

  it('shows enable warning when tool has missing required settings', async () => {
    toolSettingsMock.getAllToolStatuses.mockResolvedValue({
      web_search: { is_enabled: false, can_enable: false, missing_required: ['api_key'] },
    })
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.toggle').trigger('click')
    await flushPromises()
    expect(agentStoreMock.enableTool).not.toHaveBeenCalled()
    expect(wrapper.find('.warning-modal-stub').exists()).toBe(true)
  })

  it('surfaces an error message on toggle failure', async () => {
    const { ApiError } = await import('@/api/client')
    agentStoreMock.disableTool.mockRejectedValueOnce(new ApiError('nope'))
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.toggle').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="tools-error"]').text()).toBe('nope')
  })

  it('opens the config modal when openConfig is emitted', async () => {
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.config').trigger('click')
    await flushPromises()
    expect(wrapper.find('.config-modal-stub').exists()).toBe(true)
  })

  it('renders the EnableWarningModal when pendingEnableTool is set', async () => {
    toolSettingsMock.getAllToolStatuses.mockResolvedValue({
      web_search: { is_enabled: false, can_enable: false, missing_required: ['api_key'] },
    })
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.toggle').trigger('click')
    await flushPromises()
    expect(wrapper.find('.warning-modal-stub').exists()).toBe(true)
  })

  it('refreshes the tool status after the config modal emits "saved"', async () => {
    toolSettingsMock.getToolStatus.mockResolvedValueOnce({ is_enabled: true, can_enable: true, missing_required: [] })
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: {
            name: 'AgentToolConfigModal',
            props: ['toolName', 'tool', 'agentId'],
            emits: ['saved', 'close'],
            template: '<div v-if="toolName" class="config-modal-stub"><button @click="$emit(\'saved\', toolName)">Save</button></div>',
          },
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.config').trigger('click')
    await flushPromises()
    await wrapper.find('.config-modal-stub button').trigger('click')
    await flushPromises()
    expect(toolSettingsMock.getToolStatus).toHaveBeenCalledWith('web_search')
  })

  it('enables a tool when toggle is pressed on a can_enable=true tool', async () => {
    toolSettingsMock.getAllToolStatuses.mockResolvedValue({
      web_search: { is_enabled: false, can_enable: true, missing_required: [] },
    })
    toolSettingsMock.getToolStatus.mockResolvedValueOnce({ is_enabled: true, can_enable: true, missing_required: [] })
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    const item = wrapper.find('[data-tool-name="web_search"]')
    expect(item.attributes('data-enabled')).toBe('false')
    await item.find('.toggle').trigger('click')
    await flushPromises()
    expect(agentStoreMock.enableTool).toHaveBeenCalledWith(1, 'web_search')
    expect(item.attributes('data-enabled')).toBe('true')
  })

  it('shows enable warning when re-fetched status reports can_enable=false', async () => {
    toolSettingsMock.getAllToolStatuses.mockResolvedValue({
      web_search: { is_enabled: false, can_enable: true, missing_required: [] },
    })
    // First call (from onMounted) returns can_enable=true; second (after enable) returns can_enable=false.
    toolSettingsMock.getToolStatus.mockResolvedValueOnce({ is_enabled: false, can_enable: false, missing_required: ['api_key'] })
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.toggle').trigger('click')
    await flushPromises()
    expect(wrapper.find('.warning-modal-stub').exists()).toBe(true)
  })

  it('falls back to a generic error message when toggle fails with a non-ApiError', async () => {
    agentStoreMock.disableTool.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.toggle').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="tools-error"]').text()).toBe('Failed to update tool.')
  })

  it('toggles an operation enabled flag and stores the override', async () => {
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.op-enabled').trigger('click')
    await flushPromises()
    expect(agentStoreMock.patchOperationOverride).toHaveBeenCalledWith(1, 'web_search', 'op1', { enabled: true })
  })

  it('restores the previous operation state on patch failure', async () => {
    const { ApiError } = await import('@/api/client')
    agentStoreMock.patchOperationOverride.mockRejectedValueOnce(new ApiError('denied'))
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.op-enabled').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="tools-error"]').text()).toBe('denied')
  })

  it('falls back to a generic error on operation patch failure when not an ApiError', async () => {
    agentStoreMock.patchOperationOverride.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.op-enabled').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="tools-error"]').text()).toBe('Failed to update operation.')
  })

  it('toggles an operation auto-approve flag and stores the override', async () => {
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.op-auto').trigger('click')
    await flushPromises()
    expect(agentStoreMock.patchOperationOverride).toHaveBeenCalledWith(1, 'web_search', 'op1', { default_requires_approval: false })
  })

  it('falls back to a generic error on auto-approve patch failure when not an ApiError', async () => {
    agentStoreMock.patchOperationOverride.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(AgentToolsSection, {
      props: { agent: { id: 1, tools: [{ tool_name: 'web_search' }] }, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    await wrapper.find('[data-tool-name="web_search"]').find('.op-auto').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="tools-error"]').text()).toBe('Failed to update operation auto-approve.')
  })

  it('does not collapse the category by default (items are visible)', async () => {
    const wrapper = mount(AgentToolsSection, {
      props: { agent: baseAgent, agentId: 1 },
      global: {
        stubs: {
          AgentToolListItem: ListItemStub,
          AgentToolConfigModal: ConfigModalStub,
          EnableWarningModal: WarningModalStub,
        },
      },
    })
    await flushPromises()
    expect(wrapper.findAll('.tool-item').length).toBe(2)
  })
})
