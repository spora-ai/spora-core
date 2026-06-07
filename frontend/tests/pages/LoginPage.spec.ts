/**
 * LoginPage — email + password sign-in form.
 *
 * Covers: mounting, calling auth.login on submit, routing to dashboard on
 * success, surfacing an ApiError message, and using a generic fallback for
 * other errors.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const loginMock = vi.fn()
const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ login: loginMock }),
}))

vi.mock('@/utils/auth', () => ({
  isRegistrationEnabled: vi.fn().mockResolvedValue(true),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import LoginPage from '@/pages/LoginPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  loginMock.mockReset()
  pushMock.mockReset()
})

describe('LoginPage', () => {
  it('renders the email and password inputs and a submit button', () => {
    const wrapper = mount(LoginPage, {
      global: { stubs: { RouterLink: true } },
    })
    expect(wrapper.find('#email').exists()).toBe(true)
    expect(wrapper.find('#password').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
  })

  it('calls auth.login and navigates to dashboard on success', async () => {
    loginMock.mockResolvedValue(undefined)
    const wrapper = mount(LoginPage, {
      global: { stubs: { RouterLink: true } },
    })
    await wrapper.find('#email').setValue('me@example.com')
    await wrapper.find('#password').setValue('hunter2')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(loginMock).toHaveBeenCalledWith('me@example.com', 'hunter2')
    expect(pushMock).toHaveBeenCalledWith({ name: 'dashboard' })
  })

  it('surfaces the ApiError message on login failure', async () => {
    const { ApiError } = await import('@/api/client')
    loginMock.mockRejectedValueOnce(new ApiError('bad creds'))
    const wrapper = mount(LoginPage, {
      global: { stubs: { RouterLink: true } },
    })
    await wrapper.find('#email').setValue('me@example.com')
    await wrapper.find('#password').setValue('wrong')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toBe('bad creds')
  })

  it('uses a generic fallback for non-ApiError rejections', async () => {
    loginMock.mockRejectedValueOnce(new Error('network'))
    const wrapper = mount(LoginPage, {
      global: { stubs: { RouterLink: true } },
    })
    await wrapper.find('#email').setValue('me@example.com')
    await wrapper.find('#password').setValue('wrong')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toBe('An unexpected error occurred.')
  })
})
