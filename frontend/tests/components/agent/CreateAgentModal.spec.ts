/**
 * CreateAgentModal — modal for creating a new agent and navigating to it.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
}))

const createAgentMock = vi.fn()
vi.mock('@/stores/agent', () => ({
  useAgentStore: () => ({ createAgent: createAgentMock }),
}))

import CreateAgentModal from '@/components/agent/CreateAgentModal.vue'

const IconStub = { name: 'Icon', template: '<i />' }

beforeEach(() => {
  setActivePinia(createPinia())
  createAgentMock.mockReset()
  pushMock.mockReset()
})

function mountModal(modelValue = true) {
  return mount(CreateAgentModal, {
    props: { modelValue },
    global: { stubs: { Icon: IconStub } },
    attachTo: document.body,
  })
}

describe('CreateAgentModal', () => {
  it('renders nothing when modelValue is false', () => {
    const wrapper = mountModal(false)
    expect(document.body.textContent ?? '').not.toContain('New Agent')
    wrapper.unmount()
  })

  it('renders the modal when modelValue is true', () => {
    const wrapper = mountModal(true)
    expect(document.body.textContent ?? '').toContain('New Agent')
    wrapper.unmount()
  })

  it('emits update:modelValue false when Cancel is clicked', async () => {
    const wrapper = mountModal(true)
    const cancel = Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === 'Cancel')
    expect(cancel).toBeDefined()
    cancel?.click()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')![0]).toEqual([false])
    wrapper.unmount()
  })

  it('disables Create when name is empty', () => {
    const wrapper = mountModal(true)
    const create = Array.from(document.body.querySelectorAll('button[type="submit"]'))[0] as HTMLButtonElement
    expect(create.disabled).toBe(true)
    wrapper.unmount()
  })

  it('enables Create when a name is entered', async () => {
    const wrapper = mountModal(true)
    const input = document.body.querySelector('input#agent-name') as HTMLInputElement
    input.value = 'My Agent'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    const create = document.body.querySelector('button[type="submit"]') as HTMLButtonElement
    expect(create.disabled).toBe(false)
    wrapper.unmount()
  })

  it('calls createAgent, emits created, and navigates on submit', async () => {
    const newAgent = { id: 42, name: 'X' }
    createAgentMock.mockResolvedValue(newAgent)
    const wrapper = mountModal(true)
    const input = document.body.querySelector('input#agent-name') as HTMLInputElement
    input.value = 'Test'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    const form = document.body.querySelector('form') as HTMLFormElement
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()
    expect(createAgentMock).toHaveBeenCalledWith({ name: 'Test' })
    expect(wrapper.emitted('created')).toBeTruthy()
    expect(wrapper.emitted('created')![0][0]).toMatchObject({ id: 42 })
    expect(wrapper.emitted('update:modelValue')).toBeTruthy()
    expect(pushMock).toHaveBeenCalledWith({ name: 'agent', params: { id: 42 } })
    wrapper.unmount()
  })

  it('trims the name before calling createAgent', async () => {
    createAgentMock.mockResolvedValue({ id: 1, name: 'X' })
    const wrapper = mountModal(true)
    const input = document.body.querySelector('input#agent-name') as HTMLInputElement
    input.value = '  Trimmed  '
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    const form = document.body.querySelector('form') as HTMLFormElement
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()
    expect(createAgentMock).toHaveBeenCalledWith({ name: 'Trimmed' })
    wrapper.unmount()
  })

  it('shows an error message when createAgent throws ApiError', async () => {
    const { ApiError } = await import('@/api/client')
    createAgentMock.mockRejectedValue(new ApiError('Name taken'))
    const wrapper = mountModal(true)
    const input = document.body.querySelector('input#agent-name') as HTMLInputElement
    input.value = 'X'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    const form = document.body.querySelector('form') as HTMLFormElement
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await flushPromises()
    expect(document.body.textContent ?? '').toContain('Name taken')
    wrapper.unmount()
  })
})
