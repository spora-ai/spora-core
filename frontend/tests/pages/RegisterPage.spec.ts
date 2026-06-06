/**
 * RegisterPage — sign-up form with email confirmation flow.
 *
 * Covers: form fields, password mismatch validation, register call, the
 * "pending verification" state, resend verification, and error paths.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const registerMock = vi.fn()
const resendMock = vi.fn()
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ register: registerMock, resendVerification: resendMock }),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import RegisterPage from '@/pages/RegisterPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  registerMock.mockReset()
  resendMock.mockReset()
})

describe('RegisterPage', () => {
  it('renders all four form fields', () => {
    const wrapper = mount(RegisterPage)
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.findAll('input[type="password"]').length).toBe(2)
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
  })

  it('shows a password-mismatch error without calling the store', async () => {
    const wrapper = mount(RegisterPage)
    await wrapper.find('input[type="email"]').setValue('me@example.com')
    const passwordInputs = wrapper.findAll('input[type="password"]')
    await passwordInputs[0].setValue('hunter2')
    await passwordInputs[1].setValue('different')
    await wrapper.find('form').trigger('submit.prevent')
    expect(registerMock).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').text()).toContain('Passwords do not match')
  })

  it('calls auth.register with the form values and shows the pending state', async () => {
    registerMock.mockResolvedValue({ id: 1, email: 'me@example.com' })
    const wrapper = mount(RegisterPage)
    await wrapper.find('input[type="email"]').setValue('me@example.com')
    const passwordInputs = wrapper.findAll('input[type="password"]')
    await passwordInputs[0].setValue('hunter2')
    await passwordInputs[1].setValue('hunter2')
    await wrapper.find('input[type="text"]').setValue('Me')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(registerMock).toHaveBeenCalledWith('me@example.com', 'hunter2', 'hunter2', 'Me')
    expect(wrapper.text()).toContain('Check your email')
  })

  it('surfaces the ApiError message on register failure', async () => {
    const { ApiError } = await import('@/api/client')
    registerMock.mockRejectedValueOnce(new ApiError('email taken'))
    const wrapper = mount(RegisterPage)
    await wrapper.find('input[type="email"]').setValue('me@example.com')
    const passwordInputs = wrapper.findAll('input[type="password"]')
    await passwordInputs[0].setValue('hunter2')
    await passwordInputs[1].setValue('hunter2')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toBe('email taken')
  })

  it('calls resendVerification when the resend button is clicked', async () => {
    resendMock.mockResolvedValue(undefined)
    const wrapper = mount(RegisterPage)
    await wrapper.find('input[type="email"]').setValue('me@example.com')
    const passwordInputs = wrapper.findAll('input[type="password"]')
    await passwordInputs[0].setValue('hunter2')
    await passwordInputs[1].setValue('hunter2')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    // Now in pending state — find the resend button
    const resendButton = wrapper.find('button[type="button"]')
    await resendButton.trigger('click')
    await flushPromises()
    expect(resendMock).toHaveBeenCalledWith('me@example.com')
  })
})
