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
    it('renders when showNonRetryableErrorBanner is true', () => {
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

    it('renders canAutoRetry countdown with Cancel button', () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, canAutoRetry: true },
      })
      expect(wrapper.find('[data-testid="retry-countdown"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="cancel-retry-button"]').exists()).toBe(true)
    })

    it('renders retriesExhausted countdown without Cancel', () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, retriesExhausted: true },
      })
      expect(wrapper.find('[data-testid="retry-countdown"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="cancel-retry-button"]').exists()).toBe(false)
    })

    it('renders autoRetryDisabled countdown', () => {
      const wrapper = mount(TaskChatBanners, {
        props: { ...baseProps, showCountdown: true, autoRetryDisabled: true },
      })
      expect(wrapper.find('[data-testid="retry-countdown"]').exists()).toBe(true)
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
  })
})
