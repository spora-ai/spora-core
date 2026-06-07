/**
 * AgentDangerZone — delete-agent confirmation form.
 *
 * The input is only valid when it matches the agent's name exactly. On a
 * successful delete, the component emits `deleted` and the page navigates.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

const deleteAgentMock = vi.fn()
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({ deleteAgent: deleteAgentMock }),
}))

import AgentDangerZone from '@/components/agent/settings/AgentDangerZone.vue'

const baseAgent = { id: 7, name: 'My Agent' }

beforeEach(() => {
  deleteAgentMock.mockReset()
  deleteAgentMock.mockResolvedValue(undefined)
})

describe('AgentDangerZone', () => {
  it('renders the agent name in the confirm prompt', () => {
    const wrapper = mount(AgentDangerZone, {
      props: { agent: baseAgent, agentId: 7 },
    })
    expect(wrapper.text()).toContain('My Agent')
  })

  it('disables delete when the typed name does not match', async () => {
    const wrapper = mount(AgentDangerZone, {
      props: { agent: baseAgent, agentId: 7 },
    })
    expect(wrapper.find('[data-testid="delete-agent"]').attributes('disabled')).toBeDefined()
    await wrapper.find('#delete-confirm').setValue('wrong name')
    expect(wrapper.find('[data-testid="delete-agent"]').attributes('disabled')).toBeDefined()
  })

  it('enables delete when the typed name matches', async () => {
    const wrapper = mount(AgentDangerZone, {
      props: { agent: baseAgent, agentId: 7 },
    })
    await wrapper.find('#delete-confirm').setValue('My Agent')
    expect(wrapper.find('[data-testid="delete-agent"]').attributes('disabled')).toBeUndefined()
  })

  it('calls store.deleteAgent and emits "deleted" on success', async () => {
    const wrapper = mount(AgentDangerZone, {
      props: { agent: baseAgent, agentId: 7 },
    })
    await wrapper.find('#delete-confirm').setValue('My Agent')
    await wrapper.find('[data-testid="delete-agent"]').trigger('click')
    await flushPromises()
    expect(deleteAgentMock).toHaveBeenCalledWith(7)
    expect(wrapper.emitted('deleted')).toBeTruthy()
  })

  it('shows the error message on delete failure', async () => {
    deleteAgentMock.mockRejectedValueOnce(new Error('server error'))
    const wrapper = mount(AgentDangerZone, {
      props: { agent: baseAgent, agentId: 7 },
    })
    await wrapper.find('#delete-confirm').setValue('My Agent')
    await wrapper.find('[data-testid="delete-agent"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="delete-error"]').text()).toBe('server error')
  })

  it('uses the agentId prop for the delete call (not the agent.id)', async () => {
    const wrapper = mount(AgentDangerZone, {
      props: { agent: baseAgent, agentId: 99 },
    })
    await wrapper.find('#delete-confirm').setValue('My Agent')
    await wrapper.find('[data-testid="delete-agent"]').trigger('click')
    await flushPromises()
    expect(deleteAgentMock).toHaveBeenCalledWith(99)
  })
})
