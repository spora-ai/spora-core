/**
 * AgentIdentitySection — identity form + save flow.
 *
 * Mounts the component, types into the form, stubs the api.patch call, and
 * asserts the PATCH body + the success/error paths.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(public code: string, message: string) {
      super(message)
      this.name = 'ApiError'
    }
  },
  api: { patch: vi.fn(), get: vi.fn() },
}))

import { api } from '@/api/client'
import AgentIdentitySection from '@/components/agent/settings/AgentIdentitySection.vue'

const patchMock = api.patch as ReturnType<typeof vi.fn>

const baseAgent = {
  id: 1,
  name: 'Test Agent',
  description: 'desc',
  system_prompt: 'be helpful',
  max_steps: 10,
  allow_continuation: true,
  retry_after_minutes: 0,
  max_retries: 0,
}

beforeEach(() => {
  patchMock.mockReset()
  vi.mocked(api.patch).mockReset()
  vi.mocked(api.patch).mockResolvedValue({})
  patchMock.mockResolvedValue({})
})

describe('AgentIdentitySection', () => {
  it('renders all identity fields with initial agent values', () => {
    const wrapper = mount(AgentIdentitySection, {
      props: { agent: baseAgent, agentId: 1 },
    })
    expect((wrapper.find('#agent-name').element as HTMLInputElement).value).toBe('Test Agent')
    expect((wrapper.find('#agent-desc').element as HTMLInputElement).value).toBe('desc')
    expect((wrapper.find('#system-prompt').element as HTMLTextAreaElement).value).toBe('be helpful')
    expect((wrapper.find('#max-steps').element as HTMLInputElement).value).toBe('10')
    expect((wrapper.find('#allow-continuation').element as HTMLInputElement).checked).toBe(true)
  })

  it('disables the save button when name is empty', async () => {
    const wrapper = mount(AgentIdentitySection, {
      props: { agent: { ...baseAgent, name: 'Test Agent' }, agentId: 1 },
    })
    await wrapper.find('#agent-name').setValue('')
    expect(wrapper.find('[data-testid="save-identity"]').attributes('disabled')).toBeDefined()
  })

  it('sends the right PATCH payload on save and shows Saved!', async () => {
    const wrapper = mount(AgentIdentitySection, {
      props: { agent: baseAgent, agentId: 42 },
    })
    await wrapper.find('#agent-desc').setValue('new description')
    await wrapper.find('[data-testid="save-identity"]').trigger('click')
    await flushPromises()
    expect(patchMock).toHaveBeenCalledWith('/agents/42', {
      name: 'Test Agent',
      description: 'new description',
      system_prompt: 'be helpful',
      max_steps: 10,
      allow_continuation: true,
      retry_after_minutes: 0,
      max_retries: 0,
    })
    expect(wrapper.find('[data-testid="identity-saved"]').exists()).toBe(true)
  })

  it('shows the generic fallback message for non-ApiError rejections', async () => {
    patchMock.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(AgentIdentitySection, {
      props: { agent: baseAgent, agentId: 1 },
    })
    await wrapper.find('[data-testid="save-identity"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="identity-error"]').text()).toBe('Failed to save.')
  })

  it('uses ApiError.message when an ApiError is thrown', async () => {
    const { ApiError } = await import('@/api/client')
    patchMock.mockRejectedValueOnce(new ApiError('CONFLICT', 'duplicate name'))
    const wrapper = mount(AgentIdentitySection, {
      props: { agent: baseAgent, agentId: 1 },
    })
    await wrapper.find('[data-testid="save-identity"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="identity-error"]').text()).toBe('duplicate name')
  })

  it('coerces empty description / system_prompt to null in the payload', async () => {
    const wrapper = mount(AgentIdentitySection, {
      props: {
        agent: { ...baseAgent, description: '', system_prompt: '' },
        agentId: 1,
      },
    })
    await wrapper.find('[data-testid="save-identity"]').trigger('click')
    await flushPromises()
    expect(patchMock).toHaveBeenCalledWith('/agents/1', expect.objectContaining({
      description: null,
      system_prompt: null,
    }))
  })
})
