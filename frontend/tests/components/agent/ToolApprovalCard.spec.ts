import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ToolApprovalCard from '@/components/agent/ToolApprovalCard.vue'
import type { ToolCall } from '@/types/task'

const global = { stubs: { Icon: true } }

function makeToolCall(overrides: Partial<ToolCall> = {}): ToolCall {
  return {
    id: 1,
    provider_call_id: 'pc_abc',
    tool_name: 'send_email',
    tool_type: 'output',
    operation: 'send_email',
    operation_description: 'Send an email to a recipient',
    status: 'PENDING_APPROVAL',
    proposed_arguments: { to: 'a@b.c', subject: 'hi', body: 'msg' },
    approved_arguments: null,
    human_description: null,
    result_content: null,
    executed_at: null,
    parameter_schema: {
      type: 'object',
      properties: {
        to: { type: 'string' },
        subject: { type: 'string' },
        body: { type: 'string' },
      },
      required: ['to'],
    },
    ...overrides,
  }
}

describe('ToolApprovalCard', () => {
  it('renders the tool name, operation badge, and operation description', () => {
    const wrapper = mount(ToolApprovalCard, {
      props: { toolCall: makeToolCall() },
      global,
    })

    expect(wrapper.text()).toContain('send_email')
    expect(wrapper.text()).toContain('Send an email to a recipient')
  })

  it('falls back to human_description when operation_description is null', () => {
    const wrapper = mount(ToolApprovalCard, {
      props: { toolCall: makeToolCall({ operation_description: null, human_description: 'Send the recap email' }) },
      global,
    })

    expect(wrapper.text()).toContain('Send the recap email')
  })

  it('emits approve with the parsed JSON arguments when Approve is clicked', async () => {
    const wrapper = mount(ToolApprovalCard, {
      props: { toolCall: makeToolCall() },
      global,
    })

    await wrapper.find('button').trigger('click')

    const events = wrapper.emitted('approve')
    expect(events).toBeTruthy()
    expect(events![0][0]).toEqual({
      providerCallId: 'pc_abc',
      arguments: { to: 'a@b.c', subject: 'hi', body: 'msg' },
    })
  })

  it('reveals the reject reason input then emits reject when Confirm Reject is clicked', async () => {
    const wrapper = mount(ToolApprovalCard, {
      props: { toolCall: makeToolCall() },
      global,
    })

    // First reject button — opens the input
    const rejectButtons = wrapper.findAll('button').filter(b => b.text().includes('Reject'))
    await rejectButtons[0].trigger('click')

    // The reject reason input lives inside the card's own block (not in the
    // editor), identified by its placeholder text.
    const reasonInput = wrapper.findAll('input[type="text"]').find(
      i => (i.attributes('placeholder') ?? '').includes("rejecting"),
    )!
    expect(reasonInput.exists()).toBe(true)
    await reasonInput.setValue('looks suspicious')

    const confirm = wrapper.findAll('button').find(b => b.text().includes('Confirm Reject'))!
    await confirm.trigger('click')

    const events = wrapper.emitted('reject')
    expect(events).toBeTruthy()
    expect(events![0][0]).toEqual({ providerCallId: 'pc_abc', reason: 'looks suspicious' })
  })

  it('disables the Approve button when the approving prop is true', () => {
    const wrapper = mount(ToolApprovalCard, {
      props: { toolCall: makeToolCall(), approving: true },
      global,
    })

    const approve = wrapper.findAll('button').find(b => b.text().includes('Approving'))!
    expect(approve.attributes('disabled')).toBeDefined()
  })

  it('uses the parameter_schema property order in the embedded editor', () => {
    // The schema declares [to, subject, body]; the LLM emitted them in the same
    // order, so this test mostly checks the prop is forwarded — order behaviour
    // itself is covered by ToolArgumentsEditor.spec.ts.
    const wrapper = mount(ToolApprovalCard, {
      props: { toolCall: makeToolCall() },
      global,
    })

    const labels = wrapper.findAll('label').map(l => l.text()).filter(t => ['To', 'Subject', 'Body'].includes(t))
    expect(labels).toEqual(['To', 'Subject', 'Body'])
  })
})
