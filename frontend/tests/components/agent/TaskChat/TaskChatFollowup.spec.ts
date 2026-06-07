/**
 * TaskChatFollowup — bottom follow-up input bar.
 *
 * Asserts the show/hide behaviour, the input + button wiring, the
 * Enter-to-submit shortcut, and the error rendering.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect } from 'vitest'
import TaskChatFollowup from '@/components/agent/TaskChat/TaskChatFollowup.vue'

describe('TaskChatFollowup', () => {
  it('does not render when showFollowupBar is false', () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: false,
        followupPrompt: '',
        submittingFollowup: false,
        followupError: null,
      },
    })
    expect(wrapper.find('[data-testid="send-followup"]').exists()).toBe(false)
  })

  it('emits updateFollowupPrompt when the textarea changes', async () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: '',
        submittingFollowup: false,
        followupError: null,
      },
    })
    const textarea = wrapper.find('textarea')
    await textarea.setValue('hello there')
    expect(wrapper.emitted('updateFollowupPrompt')![0]).toEqual(['hello there'])
  })

  it('disables Send button when prompt is empty or whitespace', () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: '   ',
        submittingFollowup: false,
        followupError: null,
      },
    })
    const button = wrapper.find('[data-testid="send-followup"]')
    expect(button.attributes('disabled')).toBeDefined()
  })

  it('shows "Sending…" label and disables when submitting', () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: 'hi',
        submittingFollowup: true,
        followupError: null,
      },
    })
    const button = wrapper.find('[data-testid="send-followup"]')
    expect(button.text()).toBe('Sending…')
    expect(button.attributes('disabled')).toBeDefined()
  })

  it('emits submitFollowup when Send is clicked', async () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: 'hi',
        submittingFollowup: false,
        followupError: null,
      },
    })
    await wrapper.find('[data-testid="send-followup"]').trigger('click')
    expect(wrapper.emitted('submitFollowup')).toBeTruthy()
  })

  it('emits submitFollowup on Enter (without Shift)', async () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: 'hi',
        submittingFollowup: false,
        followupError: null,
      },
    })
    const textarea = wrapper.find('textarea')
    await textarea.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('submitFollowup')).toBeTruthy()
  })

  it('does not emit submitFollowup on Shift+Enter', async () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: 'hi',
        submittingFollowup: false,
        followupError: null,
      },
    })
    const textarea = wrapper.find('textarea')
    await textarea.trigger('keydown', { key: 'Enter', shiftKey: true })
    expect(wrapper.emitted('submitFollowup')).toBeFalsy()
  })

  it('renders followupError message when set', () => {
    const wrapper = mount(TaskChatFollowup, {
      props: {
        showFollowupBar: true,
        followupPrompt: 'hi',
        submittingFollowup: false,
        followupError: 'Something went wrong',
      },
    })
    const errorEl = wrapper.find('[data-testid="followup-error"]')
    expect(errorEl.exists()).toBe(true)
    expect(errorEl.text()).toBe('Something went wrong')
  })
})
