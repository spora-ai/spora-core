/**
 * ForgotPasswordPage — email input + success/error states.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const forgotMock = vi.fn()
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ forgotPassword: forgotMock }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import ForgotPasswordPage from '@/pages/ForgotPasswordPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  forgotMock.mockReset()
})

describe('ForgotPasswordPage', () => {
  it('renders the email input and a submit button', () => {
    const wrapper = mount(ForgotPasswordPage)
    expect(wrapper.find('#email').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
  })

  it('calls auth.forgotPassword and shows the success message', async () => {
    forgotMock.mockResolvedValue(undefined)
    const wrapper = mount(ForgotPasswordPage)
    await wrapper.find('#email').setValue('me@example.com')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(forgotMock).toHaveBeenCalledWith('me@example.com')
    expect(wrapper.text()).toContain('Check your email')
  })

  it('surfaces the ApiError message on failure', async () => {
    const { ApiError } = await import('@/api/client')
    forgotMock.mockRejectedValueOnce(new ApiError('rate limited'))
    const wrapper = mount(ForgotPasswordPage)
    await wrapper.find('#email').setValue('me@example.com')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toBe('rate limited')
  })
})
