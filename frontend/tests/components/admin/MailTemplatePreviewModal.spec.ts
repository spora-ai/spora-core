/**
 * MailTemplatePreviewModal — params form + Generate Preview + rendered
 * result. Uses Teleport, so we read the DOM from document.body.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { nextTick } from 'vue'

import MailTemplatePreviewModal from '@/components/admin/MailTemplatePreviewModal.vue'

const findButton = (label: string): HTMLButtonElement | null => {
  const buttons = Array.from(document.body.querySelectorAll('button'))
  return buttons.find((b) => b.textContent?.trim() === label) as HTMLButtonElement | null
}

beforeEach(() => {
  document.body.innerHTML = ''
  vi.resetAllMocks()
})

describe('MailTemplatePreviewModal', () => {
  it('does not render when modelValue is false', () => {
    mount(MailTemplatePreviewModal, {
      props: {
        modelValue: false,
        paramKeys: ['user_name'],
        params: { user_name: 'Alice' },
        loading: false,
        result: null,
      },
      attachTo: document.body,
    })
    expect(document.body.textContent).not.toContain('Preview Template')
  })

  it('renders the title and an input per param key when open', async () => {
    mount(MailTemplatePreviewModal, {
      props: {
        modelValue: true,
        paramKeys: ['user_name', 'email'],
        params: { user_name: 'Alice', email: 'alice@example.com' },
        loading: false,
        result: null,
      },
      attachTo: document.body,
    })
    await nextTick()
    expect(document.body.textContent).toContain('Preview Template')
    expect(document.body.querySelector('#preview-user_name')).toBeTruthy()
    expect(document.body.querySelector('#preview-email')).toBeTruthy()
  })

  it('emits `update:param` when an input changes', async () => {
    const wrapper = mount(MailTemplatePreviewModal, {
      props: {
        modelValue: true,
        paramKeys: ['user_name'],
        params: { user_name: '' },
        loading: false,
        result: null,
      },
      attachTo: document.body,
    })
    await nextTick()
    const input = document.body.querySelector<HTMLInputElement>('#preview-user_name')!
    input.value = 'Bob'
    await input.dispatchEvent(new Event('input'))
    expect(wrapper.emitted('update:param')).toBeTruthy()
    expect(wrapper.emitted('update:param')!.at(-1)).toEqual(['user_name', 'Bob'])
    wrapper.unmount()
  })

  it('emits `generate` when the Generate Preview button is clicked', async () => {
    const wrapper = mount(MailTemplatePreviewModal, {
      props: {
        modelValue: true,
        paramKeys: ['user_name'],
        params: { user_name: 'Alice' },
        loading: false,
        result: null,
      },
      attachTo: document.body,
    })
    await nextTick()
    findButton('Generate Preview')!.click()
    expect(wrapper.emitted('generate')).toBeTruthy()
    wrapper.unmount()
  })

  it('shows the loading label while loading', async () => {
    mount(MailTemplatePreviewModal, {
      props: {
        modelValue: true,
        paramKeys: ['user_name'],
        params: { user_name: 'Alice' },
        loading: true,
        result: null,
      },
      attachTo: document.body,
    })
    await nextTick()
    expect(document.body.textContent).toContain('Rendering…')
  })

  it('renders the rendered result (subject + plain text + html) when result is provided', async () => {
    mount(MailTemplatePreviewModal, {
      props: {
        modelValue: true,
        paramKeys: ['user_name'],
        params: { user_name: 'Alice' },
        loading: false,
        result: { subject: 'Hi Alice', body_text: 'Hello Alice', body_html: '<p>Hello Alice</p>' },
      },
      attachTo: document.body,
    })
    await nextTick()
    const text = document.body.textContent ?? ''
    expect(text).toContain('Hi Alice')
    expect(text).toContain('Hello Alice')
  })

  it('emits `update:modelValue` false when Close is clicked', async () => {
    const wrapper = mount(MailTemplatePreviewModal, {
      props: {
        modelValue: true,
        paramKeys: ['user_name'],
        params: { user_name: 'Alice' },
        loading: false,
        result: null,
      },
      attachTo: document.body,
    })
    await nextTick()
    findButton('Close')!.click()
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([false])
    wrapper.unmount()
  })
})
