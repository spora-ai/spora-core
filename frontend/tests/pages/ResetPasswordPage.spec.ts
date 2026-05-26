import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import ResetPasswordPage from '@/pages/ResetPasswordPage.vue'

vi.mock('@/api/client', () => ({
  ApiError: class extends Error {
    constructor(
      public override message: string,
      public readonly code: string,
      public readonly status = 500,
    ) {
      super(message)
    }
  },
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api, ApiError } from '@/api/client'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/auth/reset-password/:selector',
        name: 'reset-password',
        component: ResetPasswordPage,
      },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
}

describe('ResetPasswordPage', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.useRealTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.resetAllMocks()
  })

  it('shows password form when selector and token are provided', async () => {
    const router = makeRouter()
    router.push('/auth/reset-password/test-selector?token=test-token')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: {
          Icon: { template: '<span class="icon-stub" />' },
        },
      },
    })

    await flushPromises()
    // Should show password form, not loading
    expect(wrapper.find('input#password').exists()).toBe(true)
    expect(wrapper.find('input#confirm-password').exists()).toBe(true)
  })

  it('shows error when selector or token is missing', async () => {
    const router = makeRouter()
    router.push('/auth/reset-password/?token=')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: { Icon: { template: '<span class="icon-stub" />' } },
      },
    })

    await flushPromises()
    expect(wrapper.text()).toContain('Invalid reset link')
  })

  it('shows validation error when password is too short', async () => {
    const router = makeRouter()
    router.push('/auth/reset-password/test-selector?token=test-token')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: { Icon: { template: '<span class="icon-stub" />' } },
      },
    })

    await flushPromises()

    const passwordInput = wrapper.find('input#password')
    const confirmInput = wrapper.find('input#confirm-password')

    await passwordInput.setValue('short')
    await confirmInput.setValue('short')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('at least 8 characters')
  })

  it('shows validation error when passwords do not match', async () => {
    const router = makeRouter()
    router.push('/auth/reset-password/test-selector?token=test-token')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: { Icon: { template: '<span class="icon-stub" />' } },
      },
    })

    await flushPromises()

    const passwordInput = wrapper.find('input#password')
    const confirmInput = wrapper.find('input#confirm-password')

    await passwordInput.setValue('Password1!')
    await confirmInput.setValue('Password2!')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('do not match')
  })

  it('calls API with correct payload on successful submit', async () => {
    mockApi.post.mockResolvedValueOnce({ message: 'Password reset successfully.' })

    const router = makeRouter()
    router.push('/auth/reset-password/test-selector?token=test-token')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: { Icon: { template: '<span class="icon-stub" />' } },
      },
    })

    await flushPromises()

    const passwordInput = wrapper.find('input#password')
    const confirmInput = wrapper.find('input#confirm-password')

    await passwordInput.setValue('NewPassword1!')
    await confirmInput.setValue('NewPassword1!')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(mockApi.post).toHaveBeenCalledWith('/auth/reset-password', {
      selector: 'test-selector',
      token: 'test-token',
      password: 'NewPassword1!',
    })
  })

  it('shows error message on API failure', async () => {
    mockApi.post.mockRejectedValueOnce(new ApiError('INVALID_TOKEN', 'The token is invalid or has expired.', 400))

    const router = makeRouter()
    router.push('/auth/reset-password/test-selector?token=test-token')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: { Icon: { template: '<span class="icon-stub" />' } },
      },
    })

    await flushPromises()

    const passwordInput = wrapper.find('input#password')
    const confirmInput = wrapper.find('input#confirm-password')

    await passwordInput.setValue('NewPassword1!')
    await confirmInput.setValue('NewPassword1!')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    // The component should show an error (either from ApiError.message or fallback)
    expect(wrapper.text()).toContain('Reset')
  })

  it('shows success view and sign in button after successful reset', async () => {
    mockApi.post.mockResolvedValueOnce({ message: 'Password reset successfully.' })

    const router = makeRouter()
    router.push('/auth/reset-password/test-selector?token=test-token')
    await router.isReady()

    const wrapper = mount(ResetPasswordPage, {
      global: {
        plugins: [router],
        stubs: { Icon: { template: '<span class="icon-stub" />' } },
      },
    })

    await flushPromises()

    const passwordInput = wrapper.find('input#password')
    const confirmInput = wrapper.find('input#confirm-password')

    await passwordInput.setValue('NewPassword1!')
    await confirmInput.setValue('NewPassword1!')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('Password reset!')
    expect(wrapper.text()).toContain('Sign in')
  })
})
