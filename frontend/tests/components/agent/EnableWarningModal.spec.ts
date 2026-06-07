/**
 * EnableWarningModal — modal shown when enabling a tool with missing required settings.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { ref } from 'vue'

const modelValueRef = ref(false)
const closeEmitted = vi.fn()
const updateModelValueEmitted = vi.fn()

vi.mock('@/components/Modal.vue', () => ({
  default: {
    name: 'Modal',
    props: ['modelValue', 'title', 'size', 'backdropClosable'],
    emits: ['update:modelValue', 'close'],
    template: '<div class="modal-stub" :data-open="modelValue"><slot /><slot name="footer" /><button class="modal-close" @click="$emit(\'update:modelValue\', false); $emit(\'close\')">x</button></div>',
  },
}))

import EnableWarningModal from '@/components/agent/EnableWarningModal.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  closeEmitted.mockReset()
  updateModelValueEmitted.mockReset()
})

function mountWarning(props: { toolName: string | null; missingRequired: string[] }) {
  return mount(EnableWarningModal, { props, attachTo: document.body })
}

describe('EnableWarningModal', () => {
  it('opens the modal when toolName is non-null', () => {
    const wrapper = mountWarning({ toolName: 'openai', missingRequired: ['api_key'] })
    expect(wrapper.find('.modal-stub').attributes('data-open')).toBe('true')
    wrapper.unmount()
  })

  it('closes the modal when toolName is null', () => {
    const wrapper = mountWarning({ toolName: null, missingRequired: [] })
    expect(wrapper.find('.modal-stub').attributes('data-open')).toBe('false')
    wrapper.unmount()
  })

  it('renders the tool name and the missing required fields', () => {
    const wrapper = mountWarning({ toolName: 'openai', missingRequired: ['api_key', 'region'] })
    expect(wrapper.text()).toContain('openai')
    expect(wrapper.text()).toContain('api_key, region')
    wrapper.unmount()
  })

  it('emits close when Close is clicked', async () => {
    const wrapper = mountWarning({ toolName: 'openai', missingRequired: ['api_key'] })
    const close = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Close')
    expect(close).toBeDefined()
    await close!.trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits configure when "Open Configuration" is clicked', async () => {
    const wrapper = mountWarning({ toolName: 'openai', missingRequired: ['api_key'] })
    const open = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Open Configuration'))
    expect(open).toBeDefined()
    await open!.trigger('click')
    expect(wrapper.emitted('configure')).toBeTruthy()
    wrapper.unmount()
  })
})
