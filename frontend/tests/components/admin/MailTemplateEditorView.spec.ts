/**
 * MailTemplateEditorView — form fields (name disabled, subject, body text,
 * body HTML), placeholder chips, and the save/preview/delete actions.
 * All state lives in the parent; the view is a pure dumb component.
 */
import { mount } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('lucide-vue-next', () => ({
  ChevronRight: { template: '<span />' },
}))

import MailTemplateEditorView from '@/components/admin/MailTemplateEditorView.vue'
import type { MailTemplateDraft } from '@/composables/useMailTemplates'

const makeForm = (overrides: Partial<MailTemplateDraft & { name: string }> = {}) => ({
  name: 'welcome',
  subject: 'Hi',
  body_text: 'Hello',
  body_html: '<p>Hello</p>',
  ...overrides,
})

beforeEach(() => {
  vi.resetAllMocks()
})

describe('MailTemplateEditorView', () => {
  it('emits `back` when the back button is clicked', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('back')).toBeTruthy()
  })

  it('shows the system-template warning banner when isSystem is true', () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: true,
        placeholders: [],
      },
    })
    expect(wrapper.text()).toContain('system template and cannot be deleted')
  })

  it('does not show the system-template warning banner when isSystem is false', () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    expect(wrapper.text()).not.toContain('system template and cannot be deleted')
  })

  it('shows the loading state when loading is true', () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: true,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    expect(wrapper.text()).toContain('Loading template…')
  })

  it('renders the name field as disabled', () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    const nameInput = wrapper.find<HTMLInputElement>('#tmpl-name')
    expect(nameInput.exists()).toBe(true)
    expect(nameInput.element.disabled).toBe(true)
    expect(nameInput.element.value).toBe('welcome')
  })

  it('emits `update:subject` when the subject field changes', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    const subjectInput = wrapper.find<HTMLInputElement>('#tmpl-subject')
    await subjectInput.setValue('New subject')
    expect(wrapper.emitted('update:subject')).toBeTruthy()
    expect(wrapper.emitted('update:subject')![0]).toEqual(['New subject'])
  })

  it('emits `update:bodyText` when the plain-text body changes', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    const bodyText = wrapper.find<HTMLTextAreaElement>('#tmpl-body-text')
    await bodyText.setValue('Updated text')
    expect(wrapper.emitted('update:bodyText')![0]).toEqual(['Updated text'])
  })

  it('emits `update:bodyHtml` when the HTML body changes', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    const bodyHtml = wrapper.find<HTMLTextAreaElement>('#tmpl-body-html')
    await bodyHtml.setValue('<p>Updated</p>')
    expect(wrapper.emitted('update:bodyHtml')![0]).toEqual(['<p>Updated</p>'])
  })

  it('renders a chip for each placeholder and emits `insert-placeholder` on click', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: ['user_name', 'email'],
      },
    })
    const chips = wrapper.findAll('button').filter((b) => b.text().includes('{{'))
    expect(chips.length).toBe(2)
    await chips[0].trigger('click')
    expect(wrapper.emitted('insert-placeholder')![0]).toEqual(['user_name'])
  })

  it('shows the Delete button for non-system templates', () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    expect(wrapper.text()).toContain('Delete')
  })

  it('hides the Delete button for system templates', () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: true,
        placeholders: [],
      },
    })
    expect(wrapper.text()).not.toContain('Delete')
  })

  it('emits `save` and `preview` from the footer buttons', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    const buttons = wrapper.findAll('button')
    const previewBtn = buttons.find((b) => b.text() === 'Preview')
    const saveBtn = buttons.find((b) => b.text() === 'Save')
    expect(previewBtn).toBeTruthy()
    expect(saveBtn).toBeTruthy()
    await previewBtn!.trigger('click')
    await saveBtn!.trigger('click')
    expect(wrapper.emitted('preview')).toBeTruthy()
    expect(wrapper.emitted('save')).toBeTruthy()
  })

  it('emits `delete` when the Delete button is clicked', async () => {
    const wrapper = mount(MailTemplateEditorView, {
      props: {
        form: makeForm(),
        loading: false,
        saving: false,
        isSystem: false,
        placeholders: [],
      },
    })
    const deleteBtn = wrapper.findAll('button').find((b) => b.text() === 'Delete')!
    await deleteBtn.trigger('click')
    expect(wrapper.emitted('delete')).toBeTruthy()
  })
})
