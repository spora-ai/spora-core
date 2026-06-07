/**
 * TaskChatBanners — presentational banner variants.
 *
 * Drives each variant by toggling the props and asserts the rendered
 * testids/markup + emitted events.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach } from 'vitest'
import TaskChatBanners from '@/components/agent/TaskChat/TaskChatBanners.vue'
import type { TaskDetail } from '@/types/task'

function makeTask(overrides: Partial<TaskDetail> = {}): TaskDetail {
  return {
    id: 1,
    agent_id: 1,
    status: 'FAILED',
    user_prompt: 'hi',
    final_response: null,
    step_count: 5,
    max_steps: 10,
    error_code: 'RATE_LIMIT',
    error_message: 'slow down',
    failure_reason: null,
    history: [],
    tool_calls: [],
    created_at: '',
    updated_at: '',
    ...overrides,
  }
}

describe('TaskChatBanners', () => {
  describe('retry banner', () => {
    it('renders when showRetryBanner is true and emits retryNow / dismissBanner', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: {
          task: makeTask(),
          showRetryBanner: true,
          showNonRetryableErrorBanner: false,
          nonRetryableErrorMessage: null,
          showCountdown: false,
          countdown: '',
          canAutoRetry: false,
          retriesExhausted: false,
          autoRetryDisabled: false,
          retryAttempt: 1,
          maxRetryAttempts: 3,
          cancelling: false,
          showMaxStepsBanner: false,
          followupPrompt: '',
          submittingFollowup: false,
        },
      })
      expect(wrapper.find('[data-testid="retry-banner"]').exists()).toBe(true)
      await wrapper.find('[data-testid="retry-button"]').trigger('click')
      expect(wrapper.emitted('retryNow')).toBeTruthy()
      await wrapper.find('[data-testid="dismiss-retry-banner-button"]').trigger('click')
      expect(wrapper.emitted('dismissBanner')).toBeTruthy()
    })

    it('does not render when showRetryBanner is false', () => {
      const wrapper = mount(TaskChatBanners, {
        props: {
          task: makeTask(),
          showRetryBanner: false,
          showNonRetryableErrorBanner: false,
          nonRetryableErrorMessage: null,
          showCountdown: false,
          countdown: '',
          canAutoRetry: false,
          retriesExhausted: false,
          autoRetryDisabled: false,
          retryAttempt: 1,
          maxRetryAttempts: 3,
          cancelling: false,
          showMaxStepsBanner: false,
          followupPrompt: '',
          submittingFollowup: false,
        },
      })
      expect(wrapper.find('[data-testid="retry-banner"]').exists()).toBe(false)
    })
  })

  describe('non-retryable banner', () => {
    it('renders when showNonRetryableErrorBanner is true and emits retryNow / dismissBanner', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: {
          task: makeTask({ error_code: 'NO_LLM_CONFIGURATION' }),
          showRetryBanner: false,
          showNonRetryableErrorBanner: true,
          nonRetryableErrorMessage: 'No LLM',
          showCountdown: false,
          countdown: '',
          canAutoRetry: false,
          retriesExhausted: false,
          autoRetryDisabled: false,
          retryAttempt: 1,
          maxRetryAttempts: 0,
          cancelling: false,
          showMaxStepsBanner: false,
          followupPrompt: '',
          submittingFollowup: false,
        },
      })
      expect(wrapper.find('[data-testid="non-retryable-error-banner"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('No LLM')
      await wrapper.find('[data-testid="retry-button-non-retryable"]').trigger('click')
      expect(wrapper.emitted('retryNow')).toBeTruthy()
      await wrapper.find('[data-testid="dismiss-non-retryable-banner-button"]').trigger('click')
      expect(wrapper.emitted('dismissBanner')).toBeTruthy()
    })
  })

  describe('countdown variants', () => {
    const baseProps = {
      task: makeTask(),
      showRetryBanner: false,
      showNonRetryableErrorBanner: false,
      nonRetryableErrorMessage: null,
      countdown: '0:30',
      canAutoRetry: false,
      retriesExhausted: false,
      autoRetryDisabled: false,
      retryAttempt: 2,
      maxRetryAttempts: 3,
      cancelling: false,
      showMaxStepsBanner: false,
      followupPrompt: '',
      submittingFollowup: false,
    }

    it('renders canAutoRetry countdown with Cancel button and emits cancelRetryChain', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, canAutoRetry: true, task: makeTask({ error_code: 'RATE_LIMIT' }) },
      })
      expect(wrapper.find('[data-testid="retry-countdown"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="cancel-retry-button"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Retrying in 0:30')
      expect(wrapper.text()).toContain('Attempt 2 of 3')
      await wrapper.find('[data-testid="retry-button"]').trigger('click')
      expect(wrapper.emitted('retryNow')).toBeTruthy()
      await wrapper.find('[data-testid="cancel-retry-button"]').trigger('click')
      expect(wrapper.emitted('cancelRetryChain')).toBeTruthy()
    })

    it('swaps the message copy when error_code is ORPHANED', () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, canAutoRetry: true, task: makeTask({ error_code: 'ORPHANED' }) },
      })
      expect(wrapper.text()).toContain('Task was interrupted')
    })

    it('disables the Cancel button and shows "Cancelling…" when cancelling is true', () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, canAutoRetry: true, cancelling: true },
      })
      const cancel = wrapper.find('[data-testid="cancel-retry-button"]')
      expect(cancel.attributes('disabled')).toBeDefined()
      expect(cancel.text()).toBe('Cancelling…')
    })

    it('renders retriesExhausted countdown without Cancel and emits retryNow', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, retriesExhausted: true },
      })
      expect(wrapper.find('[data-testid="retry-countdown"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="cancel-retry-button"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('All retries exhausted')
      await wrapper.find('[data-testid="retry-button"]').trigger('click')
      expect(wrapper.emitted('retryNow')).toBeTruthy()
    })

    it('renders autoRetryDisabled countdown and emits retryNow', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, autoRetryDisabled: true },
      })
      expect(wrapper.find('[data-testid="retry-countdown"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('Auto-retry not configured')
      await wrapper.find('[data-testid="retry-button"]').trigger('click')
      expect(wrapper.emitted('retryNow')).toBeTruthy()
    })
  })

  describe('max-steps banner', () => {
    it('renders when showMaxStepsBanner is true and emits followup events', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: {
          task: makeTask({ step_count: 10, max_steps: 10, failure_reason: 'Max steps reached.' }),
          showRetryBanner: false,
          showNonRetryableErrorBanner: false,
          nonRetryableErrorMessage: null,
          showCountdown: false,
          countdown: '',
          canAutoRetry: false,
          retriesExhausted: false,
          autoRetryDisabled: false,
          retryAttempt: 1,
          maxRetryAttempts: 0,
          cancelling: false,
          showMaxStepsBanner: true,
          followupPrompt: 'do this next',
          submittingFollowup: false,
        },
      })
      expect(wrapper.text()).toContain('Max steps reached')
      const textarea = wrapper.find('textarea')
      expect((textarea.element as HTMLTextAreaElement).value).toBe('do this next')
      await textarea.setValue('updated')
      expect(wrapper.emitted('updateFollowupPrompt')).toBeTruthy()
      expect(wrapper.emitted('updateFollowupPrompt')![0]).toEqual(['updated'])
    })

    it('disables the submit button when followupPrompt is empty', () => {
      const wrapper = mount(TaskChatBanners, {
        props: {
          task: makeTask({ step_count: 10, max_steps: 10 }),
          showRetryBanner: false,
          showNonRetryableErrorBanner: false,
          nonRetryableErrorMessage: null,
          showCountdown: false,
          countdown: '',
          canAutoRetry: false,
          retriesExhausted: false,
          autoRetryDisabled: false,
          retryAttempt: 1,
          maxRetryAttempts: 0,
          cancelling: false,
          showMaxStepsBanner: true,
          followupPrompt: '   ',
          submittingFollowup: false,
        },
      })
      const button = wrapper.find('button.bg-amber-600')
      expect(button.attributes('disabled')).toBeDefined()
    })

    it('emits submitFollowup when the submit button is clicked and shows the "Continuing…" label while submitting', async () => {
      const wrapper = mount(TaskChatBanners, {
        props: {
          task: makeTask({ step_count: 10, max_steps: 10 }),
          showRetryBanner: false,
          showNonRetryableErrorBanner: false,
          nonRetryableErrorMessage: null,
          showCountdown: false,
          countdown: '',
          canAutoRetry: false,
          retriesExhausted: false,
          autoRetryDisabled: false,
          retryAttempt: 1,
          maxRetryAttempts: 0,
          cancelling: false,
          showMaxStepsBanner: true,
          followupPrompt: 'keep going',
          submittingFollowup: false,
        },
      })
      const button = wrapper.find('button.bg-amber-600')
      expect(button.text()).toBe('Reset steps & continue')
      await button.trigger('click')
      expect(wrapper.emitted('submitFollowup')).toBeTruthy()
      await wrapper.setProps({ submittingFollowup: true })
      expect(wrapper.find('button.bg-amber-600').text()).toBe('Continuing…')
    })
  })
})
