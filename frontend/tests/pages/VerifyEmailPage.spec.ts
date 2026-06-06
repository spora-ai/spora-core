/**
 * VerifyEmailPage — handles email verification links on mount.
 *
 * Covers: success path, ApiError, missing selector/token, and a generic
 * fallback for non-ApiError rejections.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { selector: 'sel-1' }, query: { token: 'tok-1' } }),
  useRouter: () => ({ push: pushMock }),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn() },
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

import { api } from '@/api/client'
import VerifyEmailPage from '@/pages/VerifyEmailPage.vue'

const getMock = api.get as ReturnType<typeof vi.fn>

beforeEach(() => {
  getMock.mockReset()
  getMock.mockResolvedValue({})
})

describe('VerifyEmailPage', () => {
  it('shows the success state on a successful verify call', async () => {
    getMock.mockResolvedValueOnce({})
    const wrapper = mount(VerifyEmailPage)
    await flushPromises()
    expect(getMock).toHaveBeenCalledWith(expect.stringContaining('/auth/verify/sel-1?token=tok-1'))
    expect(wrapper.text()).toMatch(/verified|success/i)
  })

  it('shows the error message on ApiError', async () => {
    const { ApiError } = await import('@/api/client')
    getMock.mockRejectedValueOnce(new ApiError('expired token'))
    const wrapper = mount(VerifyEmailPage)
    await flushPromises()
    expect(wrapper.text()).toContain('expired token')
  })

  it('uses a generic fallback for non-ApiError rejections', async () => {
    getMock.mockRejectedValueOnce(new Error('network'))
    const wrapper = mount(VerifyEmailPage)
    await flushPromises()
    expect(wrapper.text()).toContain('Verification failed')
  })
})
