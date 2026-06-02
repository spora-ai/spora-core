import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import ToolApprovalBar from '@/components/agent/ToolApprovalBar.vue'
import type { ToolCall } from '@/types/task'

const global = { stubs: { Icon: true } }

function makeToolCall(overrides: Partial<ToolCall> = {}): ToolCall {
  return {
    id: 1,
    provider_call_id: 'pc_1',
    tool_name: 'send_email',
    tool_type: 'output',
    operation: null,
    operation_description: null,
    status: 'PENDING_APPROVAL',
    proposed_arguments: { to: 'a@b.c' },
    approved_arguments: null,
    human_description: 'Send email',
    result_content: null,
    executed_at: null,
    ...overrides,
  }
}

describe('ToolApprovalBar', () => {
  it('renders the singular header for one pending tool', () => {
    const wrapper = mount(ToolApprovalBar, {
      props: { pending: [makeToolCall()] },
      global,
    })

    expect(wrapper.text()).toContain('Tool approval required')
    expect(wrapper.text()).not.toContain('approvals required')
  })

  it('renders the plural header with count for multiple pending tools', () => {
    const wrapper = mount(ToolApprovalBar, {
      props: {
        pending: [
          makeToolCall({ id: 1 }),
          makeToolCall({ id: 2, provider_call_id: 'pc_2' }),
          makeToolCall({ id: 3, provider_call_id: 'pc_3' }),
        ],
      },
      global,
    })

    expect(wrapper.text()).toContain('3 tool approvals required')
  })

  it('hides bulk approve/reject buttons when only one tool is pending', () => {
    const wrapper = mount(ToolApprovalBar, {
      props: { pending: [makeToolCall()] },
      global,
    })

    expect(wrapper.findAll('button').find(b => b.text().includes('Approve All'))).toBeUndefined()
    expect(wrapper.findAll('button').find(b => b.text().includes('Reject All'))).toBeUndefined()
  })

  it('shows bulk approve/reject when more than one tool is pending', () => {
    const wrapper = mount(ToolApprovalBar, {
      props: {
        pending: [makeToolCall({ id: 1 }), makeToolCall({ id: 2, provider_call_id: 'pc_2' })],
      },
      global,
    })

    expect(wrapper.findAll('button').find(b => b.text().includes('Approve All'))).toBeDefined()
    expect(wrapper.findAll('button').find(b => b.text().includes('Reject All'))).toBeDefined()
  })

  it('emits approve-all with edited arguments per pending tool when clicked', async () => {
    const wrapper = mount(ToolApprovalBar, {
      props: {
        pending: [
          makeToolCall({ id: 1, provider_call_id: 'pc_1', proposed_arguments: { to: 'a@b.c' } }),
          makeToolCall({ id: 2, provider_call_id: 'pc_2', proposed_arguments: { to: 'x@y.z' } }),
        ],
      },
      global,
    })

    const btn = wrapper.findAll('button').find(b => b.text().includes('Approve All'))!
    await btn.trigger('click')

    const events = wrapper.emitted('approve-all')
    expect(events).toBeTruthy()
    const payload = events![0][0] as { approvals: Array<{ providerCallId: string; arguments: Record<string, unknown> }> }
    expect(payload.approvals).toHaveLength(2)
    expect(payload.approvals[0]).toEqual({ providerCallId: 'pc_1', arguments: { to: 'a@b.c' } })
    expect(payload.approvals[1]).toEqual({ providerCallId: 'pc_2', arguments: { to: 'x@y.z' } })
  })

  it('reveals the reject reason input then emits reject-all with reason', async () => {
    const wrapper = mount(ToolApprovalBar, {
      props: {
        pending: [makeToolCall({ id: 1 }), makeToolCall({ id: 2, provider_call_id: 'pc_2' })],
      },
      global,
    })

    const rejectAll = wrapper.findAll('button').find(b => b.text().includes('Reject All'))!
    await rejectAll.trigger('click')

    const reasonInput = wrapper.findAll('input[type="text"]').find(
      i => (i.attributes('placeholder') ?? '').includes("rejecting all"),
    )!
    await reasonInput.setValue('not what I asked for')

    const confirm = wrapper.findAll('button').find(b => b.text().includes('Confirm Reject All'))!
    await confirm.trigger('click')

    const events = wrapper.emitted('reject-all')
    expect(events).toBeTruthy()
    expect(events![0][0]).toEqual({ reason: 'not what I asked for' })
  })

  it('re-emits approve-one events bubbled up from cards', async () => {
    const wrapper = mount(ToolApprovalBar, {
      props: { pending: [makeToolCall()] },
      global,
    })

    // Clicking the per-card approve button is the integration path — emits approve
    // on the card, which the bar listens to and re-emits as approve-one.
    const approveButton = wrapper.findAll('button').find(b => b.text().includes('Approve'))!
    await approveButton.trigger('click')

    const events = wrapper.emitted('approve-one')
    expect(events).toBeTruthy()
    expect(events![0][0]).toHaveProperty('providerCallId', 'pc_1')
  })

  it('renders the approveError when provided', () => {
    const wrapper = mount(ToolApprovalBar, {
      props: { pending: [makeToolCall()], approveError: 'Network failed' },
      global,
    })

    expect(wrapper.text()).toContain('Network failed')
  })
})
