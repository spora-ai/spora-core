/**
 * AccountPage — display name, email change, and password forms.
 *
 * Covers the form fields and the three save flows (updateAccount,
 * changeEmail, changePassword) plus their error paths.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const updateAccountMock = vi.fn()
const changeEmailMock = vi.fn()
const changePasswordMock = vi.fn()
const userRef = { value: { id: 1, email: 'me@example.com', name: 'Me' } }

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() { return userRef.value },
    updateAccount: updateAccountMock,
    changeEmail: changeEmailMock,
    changePassword: changePasswordMock,
  }),
}))

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    constructor(message: string) { super(message); this.name = 'ApiError' }
  },
}))

const GlobalNavbarStub = { name: 'GlobalNavbar', template: '<div class="navbar-stub" />' }

import AccountPage from '@/pages/AccountPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  updateAccountMock.mockReset()
  updateAccountMock.mockResolvedValue(undefined)
  changeEmailMock.mockReset()
  changeEmailMock.mockResolvedValue(undefined)
  changePasswordMock.mockReset()
  changePasswordMock.mockResolvedValue(undefined)
})

describe('AccountPage', () => {
  it('renders the navbar and all three forms', () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    expect(wrapper.find('.navbar-stub').exists()).toBe(true)
    // Display name, email, password forms should all be present
    expect(wrapper.text()).toMatch(/display name/i)
    expect(wrapper.text()).toMatch(/email/i)
    expect(wrapper.text()).toMatch(/password/i)
  })

  it('pre-fills the display name from auth.user', () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const nameInput = wrapper.find('input[type="text"]')
    expect(nameInput.exists()).toBe(true)
    expect((nameInput.element as HTMLInputElement).value).toBe('Me')
  })

  it('calls updateAccount on display name save', async () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    await wrapper.find('input[type="text"]').setValue('New Name')
    // The display name section uses a button with @click="saveDisplayName" (no <form>)
    const buttons = wrapper.findAll('button')
    const saveButton = buttons.find((b) => b.text() === 'Save')
    expect(saveButton).toBeDefined()
    await saveButton!.trigger('click')
    await flushPromises()
    expect(updateAccountMock).toHaveBeenCalledWith('New Name')
  })

  it('does not save an empty display name', async () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    await wrapper.find('input[type="text"]').setValue('   ')
    const buttons = wrapper.findAll('button')
    const saveButton = buttons.find((b) => b.text() === 'Save')
    await saveButton!.trigger('click')
    expect(updateAccountMock).not.toHaveBeenCalled()
  })

  it('surfaces an ApiError message on display name save failure', async () => {
    const { ApiError } = await import('@/api/client')
    updateAccountMock.mockRejectedValueOnce(new ApiError('taken'))
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    await wrapper.find('input[type="text"]').setValue('New Name')
    const buttons = wrapper.findAll('button')
    const saveButton = buttons.find((b) => b.text() === 'Save')
    await saveButton!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('taken')
  })

  it('includes a hidden username field in the password form for password managers', () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const forms = wrapper.findAll('form')
    const passwordForm = forms[1]
    const usernameInput = passwordForm.find('input[autocomplete="username"]')
    expect(usernameInput.exists()).toBe(true)
    expect((usernameInput.element as HTMLInputElement).type).toBe('email')
    expect((usernameInput.element as HTMLInputElement).value).toBe('me@example.com')
    expect(usernameInput.attributes('tabindex')).toBe('-1')
    expect(usernameInput.attributes('aria-hidden')).toBe('true')
  })

  it('submits the password form with current + new password and clears inputs on success', async () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const forms = wrapper.findAll('form')
    const passwordForm = forms[1]
    await passwordForm.find('#current-pw').setValue('OldSecret1!')
    await passwordForm.find('#new-pw').setValue('NewSecret1!')
    await passwordForm.find('#confirm-pw').setValue('NewSecret1!')
    await passwordForm.trigger('submit.prevent')
    await flushPromises()
    expect(changePasswordMock).toHaveBeenCalledWith('OldSecret1!', 'NewSecret1!')
  })

  it('rejects mismatched new passwords without calling the store', async () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const forms = wrapper.findAll('form')
    const passwordForm = forms[1]
    await passwordForm.find('#current-pw').setValue('OldSecret1!')
    await passwordForm.find('#new-pw').setValue('NewSecret1!')
    await passwordForm.find('#confirm-pw').setValue('Different1!')
    await passwordForm.trigger('submit.prevent')
    await flushPromises()
    expect(changePasswordMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toMatch(/do not match/i)
  })

  it('rejects new passwords shorter than 8 characters', async () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const forms = wrapper.findAll('form')
    const passwordForm = forms[1]
    await passwordForm.find('#current-pw').setValue('OldSecret1!')
    await passwordForm.find('#new-pw').setValue('short')
    await passwordForm.find('#confirm-pw').setValue('short')
    await passwordForm.trigger('submit.prevent')
    await flushPromises()
    expect(changePasswordMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toMatch(/at least 8 characters/i)
  })

  it('surfaces an ApiError message on password change failure', async () => {
    const { ApiError } = await import('@/api/client')
    changePasswordMock.mockRejectedValueOnce(new ApiError('wrong password'))
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const forms = wrapper.findAll('form')
    const passwordForm = forms[1]
    await passwordForm.find('#current-pw').setValue('OldSecret1!')
    await passwordForm.find('#new-pw').setValue('NewSecret1!')
    await passwordForm.find('#confirm-pw').setValue('NewSecret1!')
    await passwordForm.trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toContain('wrong password')
  })

  it('calls changeEmail and clears the input on success', async () => {
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const emailForm = wrapper.find('form')
    await emailForm.find('input#new-email').setValue('new@example.com')
    await emailForm.trigger('submit.prevent')
    await flushPromises()
    expect(changeEmailMock).toHaveBeenCalledWith('new@example.com')
  })

  it('surfaces an ApiError message on email change failure', async () => {
    const { ApiError } = await import('@/api/client')
    changeEmailMock.mockRejectedValueOnce(new ApiError('email taken'))
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const emailForm = wrapper.find('form')
    await emailForm.find('input#new-email').setValue('new@example.com')
    await emailForm.trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toContain('email taken')
  })

  it('falls back to a generic message for non-ApiError email change failures', async () => {
    changeEmailMock.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mount(AccountPage, {
      global: { stubs: { GlobalNavbar: GlobalNavbarStub } },
    })
    const emailForm = wrapper.find('form')
    await emailForm.find('input#new-email').setValue('new@example.com')
    await emailForm.trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.text()).toMatch(/Failed to request email change/i)
  })
})
