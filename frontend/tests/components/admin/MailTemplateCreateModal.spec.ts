/**
 * MailTemplateCreateModal — name + subject + body fields, Cancel / Create.
 * Renders inside a Teleport (body), so we read buttons from the
 * document.body.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { nextTick } from 'vue'

import MailTemplateCreateModal from '@/components/admin/MailTemplateCreateModal.vue'
import type { MailTemplateCreateDraft } from '@/composables/useMailTemplates'

const makeForm = (overrides: Partial<MailTemplateCreateDraft> = {}): MailTemplateCreateDraft => ({
  name: '',
  subject: '',
  body_text: '',
  body_html: '',
  ...overrides,
})

const findButton = (label: string): HTMLButtonElement | null => {
  const buttons = Array.from(document.body.querySelectorAll('button'))
  return buttons.find((b) => b.textContent?.trim() === label) as HTMLButtonElement | null
}

beforeEach(() => {
  document.body.innerHTML = ''
  vi.resetAllMocks()
})

describe('MailTemplateCreateModal', () => {
  it('does not render when modelValue is false', () => {
    mount(MailTemplateCreateModal, {
      props: { modelValue: false, form: makeForm(), saving: false },
      attachTo: document.body,
    })
    expect(document.body.textContent).not.toContain('Create Template')
  })

  it('renders the form fields when modelValue is true', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm(), saving: false },
      attachTo: document.body,
    })
    await nextTick()
    expect(document.body.textContent).toContain('Create Template')
    expect(document.body.querySelector('#create-name')).toBeTruthy()
    expect(document.body.querySelector('#create-subject')).toBeTruthy()
    expect(document.body.querySelector('#create-body-text')).toBeTruthy()
    expect(document.body.querySelector('#create-body-html')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits update events for the form fields', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm(), saving: false },
      attachTo: document.body,
    })
    await nextTick()
    const nameInput = document.body.querySelector<HTMLInputElement>('#create-name')!
    await nameInput.dispatchEvent(new Event('input'))
    expect(wrapper.emitted('update:name')).toBeTruthy()

    const subjectInput = document.body.querySelector<HTMLInputElement>('#create-subject')!
    await subjectInput.dispatchEvent(new Event('input'))
    expect(wrapper.emitted('update:subject')).toBeTruthy()

    const bodyText = document.body.querySelector<HTMLTextAreaElement>('#create-body-text')!
    await bodyText.dispatchEvent(new Event('input'))
    expect(wrapper.emitted('update:bodyText')).toBeTruthy()

    const bodyHtml = document.body.querySelector<HTMLTextAreaElement>('#create-body-html')!
    await bodyHtml.dispatchEvent(new Event('input'))
    expect(wrapper.emitted('update:bodyHtml')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits `create` when the Create button is clicked', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm({ name: 'a', subject: 'b' }), saving: false },
      attachTo: document.body,
    })
    await nextTick()
    const createBtn = findButton('Create')!
    createBtn.click()
    expect(wrapper.emitted('create')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits `update:modelValue` false when the X close button is clicked', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm(), saving: false },
      attachTo: document.body,
    })
    await nextTick()
    const closeBtns = Array.from(document.body.querySelectorAll('button')).filter(
      (b) => b.querySelector('.h-5.w-5') !== null,
    )
    closeBtns[0]?.click()
    expect(wrapper.emitted('update:modelValue')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('emits `update:modelValue` false when the Cancel button is clicked', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm(), saving: false },
      attachTo: document.body,
    })
    await nextTick()
    const cancelBtn = findButton('Cancel')!
    cancelBtn.click()
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('disables the Create button while saving', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm({ name: 'a', subject: 'b' }), saving: true },
      attachTo: document.body,
    })
    await nextTick()
    const createBtn = findButton('Creating…')!
    expect(createBtn).toBeTruthy()
    expect(createBtn.disabled).toBe(true)
    wrapper.unmount()
  })

  it('disables the Create button when name or subject is empty', async () => {
    const wrapper = mount(MailTemplateCreateModal, {
      props: { modelValue: true, form: makeForm({ name: 'a', subject: '' }), saving: false },
      attachTo: document.body,
    })
    await nextTick()
    const createBtn = findButton('Create')!
    expect(createBtn.disabled).toBe(true)
    wrapper.unmount()
  })
})
