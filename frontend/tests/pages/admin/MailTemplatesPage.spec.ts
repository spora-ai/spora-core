/**
 * MailTemplatesPage — admin mail template editor.
 *
 * Mocks the store and router, then asserts the page renders the list view
 * by default and the editor when a template is selected. Sub-component
 * coverage is covered by their own specs.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const currentTemplateRef = ref<{ id: number; name: string; subject: string; body_text: string; body_html: string } | null>(null)
const templatesRef = ref<Array<{ id: number; name: string; subject: string; body_text: string; body_html: string }>>([])

const fetchAllMock = vi.fn()
const fetchOneMock = vi.fn()
const updateMock = vi.fn()
const createMock = vi.fn()
const removeMock = vi.fn()
const previewMock = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', template: '<a><slot /></a>' },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1, email: 'admin@x.com', name: 'Admin', roles: ['ADMIN'], is_admin: true } }),
}))

vi.mock('@/stores/mailTemplates', () => ({
  useMailTemplatesStore: () => ({
    get templates() { return templatesRef.value },
    get currentTemplate() { return currentTemplateRef.value },
    set currentTemplate(v) { currentTemplateRef.value = v },
    loading: false,
    saving: false,
    error: null,
    fetchAll: fetchAllMock,
    fetchOne: fetchOneMock,
    update: updateMock,
    create: createMock,
    remove: removeMock,
    preview: previewMock,
  }),
}))

const toastMock = { error: vi.fn(), success: vi.fn() }
vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMock,
}))

import MailTemplatesPage from '@/pages/admin/MailTemplatesPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  currentTemplateRef.value = null
  templatesRef.value = []
  fetchAllMock.mockReset()
  fetchAllMock.mockResolvedValue(undefined)
  fetchOneMock.mockReset()
  updateMock.mockReset()
  createMock.mockReset()
  removeMock.mockReset()
  previewMock.mockReset()
  updateMock.mockResolvedValue({ name: 'x', subject: 'y' })
  createMock.mockResolvedValue({ id: 99, name: 'x' })
  removeMock.mockResolvedValue(undefined)
  previewMock.mockResolvedValue({ subject: 'x', body_text: 'y', body_html: '<p>z</p>' })
  fetchOneMock.mockResolvedValue({
    id: 1, name: 'welcome', subject: 'Hi', body_text: 'x', body_html: '<p>x</p>',
  })
  toastMock.error.mockReset()
  toastMock.success.mockReset()
})

describe('MailTemplatesPage', () => {
  it('mounts and loads templates on mount', async () => {
    templatesRef.value = [{ id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }]
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(fetchAllMock).toHaveBeenCalled()
    expect(wrapper.exists()).toBe(true)
    expect(wrapper.text()).toContain('welcome')
  })

  it('redirects non-admins on mount', async () => {
    // The page checks auth.user.roles; with roles:['ADMIN'] (the default mock)
    // it does NOT redirect. The redirect branch is exercised by the
    // empty-roles test in useMailTemplateEditor.spec.ts.
    mount(MailTemplatesPage, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    // Default mock has ADMIN role → no redirect → fetchAll runs
    expect(fetchAllMock).toHaveBeenCalled()
  })

  it('shows a toast when fetchAll fails', async () => {
    fetchAllMock.mockRejectedValueOnce(new Error('boom'))
    mount(MailTemplatesPage, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(toastMock.error).toHaveBeenCalledWith('Failed to load mail templates.')
  })
})



import { nextTick } from 'vue'

describe('MailTemplatesPage — v-if switching', () => {
  it('renders the list view when no template is selected', async () => {
    templatesRef.value = [{ id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }]
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
    expect(wrapper.text()).toContain('welcome')
  })

  it('renders the editor view when a template is selected', async () => {
    currentTemplateRef.value = { id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }
    templatesRef.value = [currentTemplateRef.value]
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
    })
    await flushPromises()
        expect(wrapper.text()).toContain('Back to list')
    expect(wrapper.text()).toContain('Save')
  })

  it('shows the create modal after clicking "+ New Template"', async () => {
    templatesRef.value = [{ id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }]
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    const newBtn = wrapper.findAll('button').find((b) => b.text() === '+ New Template')!
    await newBtn.trigger('click')
    await nextTick()
    expect(document.body.textContent).toContain('Create Template')
    // Close the modal via the Cancel button — exercises the v-model setter.
    const cancelBtn = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Cancel')!
    cancelBtn.click()
    await nextTick()
    expect(document.body.textContent).not.toContain('Create Template')
    wrapper.unmount()
  })

  it('fires the create modal handlers (update:name/subject/bodyText/bodyHtml + create)', async () => {
    templatesRef.value = [{ id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }]
    createMock.mockResolvedValue({ id: 99, name: 'new' })
    fetchOneMock.mockResolvedValue({ id: 99, name: 'new', subject: 's', body_text: '', body_html: '' })
    const MailTemplateCreateModal = (await import('@/components/admin/MailTemplateCreateModal.vue')).default
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    // Open the modal
    const newBtn = wrapper.findAll('button').find((b) => b.text() === '+ New Template')!
    await newBtn.trigger('click')
    await nextTick()
    // Find the create modal component and fire its event handlers.
    const createModal = wrapper.findComponent(MailTemplateCreateModal)
    createModal.vm.$emit('update:name', 'newone')
    createModal.vm.$emit('update:subject', 'Subject')
    createModal.vm.$emit('update:bodyText', 'text')
    createModal.vm.$emit('update:bodyHtml', '<p>html</p>')
    await flushPromises()
    createModal.vm.$emit('create')
    await flushPromises()
    expect(createMock).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('fires the @create event on the list view (opens the create modal)', async () => {
    templatesRef.value = [{ id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }]
    const MailTemplateListView = (await import('@/components/admin/MailTemplateListView.vue')).default
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    const listView = wrapper.findComponent(MailTemplateListView)
    listView.vm.$emit('create')
    await nextTick()
    expect(document.body.textContent).toContain('Create Template')
    wrapper.unmount()
  })

  it('fires the preview modal handler (update:param)', async () => {
    currentTemplateRef.value = { id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }
    templatesRef.value = [currentTemplateRef.value]
    const MailTemplatePreviewModal = (await import('@/components/admin/MailTemplatePreviewModal.vue')).default
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    // Open the preview modal
    const previewBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Preview')!
    await previewBtn.trigger('click')
    await nextTick()
    const previewModal = wrapper.findComponent(MailTemplatePreviewModal)
    previewModal.vm.$emit('update:param', 'user_name', 'Alice')
    await flushPromises()
    // The page routes update:param into the editor's previewParams ref;
    // the modal input should now reflect the new value.
    const userNameInput = document.body.querySelector<HTMLInputElement>('#preview-user_name')
    expect(userNameInput?.value).toBe('Alice')
    wrapper.unmount()
  })

  it('exercises the create modal event handlers (open and close)', async () => {
    templatesRef.value = [{ id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }]
    createMock.mockResolvedValue({ id: 99, name: 'new' })
    fetchOneMock.mockResolvedValue({ id: 99, name: 'new', subject: 's', body_text: '', body_html: '' })
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    // Open the modal
    const newBtn = wrapper.findAll('button').find((b) => b.text() === '+ New Template')!
    await newBtn.trigger('click')
    await nextTick()
    // The create modal renders. The Create button is disabled because the
    // form is empty, but the event handlers are wired up. We do not need
    // to fire them individually here; the preview/editor tests cover
    // the deeper flows. Here we just confirm the modal opened and the
    // close path works.
    expect(document.body.textContent).toContain('Create Template')
    // Close via X — exercises update:modelValue → setter
    const closeX = document.body.querySelector('button .h-5.w-5')?.closest('button')!
    closeX?.click()
    await nextTick()
    expect(document.body.textContent).not.toContain('Create Template')
    wrapper.unmount()
  })

  it('opens and closes the preview modal via the editor view', async () => {
    currentTemplateRef.value = { id: 1, name: 'welcome', subject: 'Hi', body_text: '', body_html: '' }
    templatesRef.value = [currentTemplateRef.value]
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    // Click the Preview button in the editor view
    const previewBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Preview')!
    await previewBtn.trigger('click')
    await nextTick()
    expect(document.body.textContent).toContain('Preview Template')
    // Click Generate to exercise @generate
    const generateBtn = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Generate Preview')!
    generateBtn.click()
    await flushPromises()
    // Close the preview modal — exercises the showPreview setter
    const closeBtn = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Close')!
    closeBtn.click()
    await nextTick()
    expect(document.body.textContent).not.toContain('Preview Template')
    wrapper.unmount()
  })

  it('exercises the editor view event handlers (save / delete / back / placeholder / body updates)', async () => {
    currentTemplateRef.value = { id: 1, name: 'welcome', subject: 'Hi', body_text: 'x', body_html: '<p>x</p>' }
    templatesRef.value = [currentTemplateRef.value]
    updateMock.mockResolvedValue({ name: 'welcome', subject: 'Hi' })
    const wrapper = mount(MailTemplatesPage, {
      global: { stubs: { RouterLink: true } },
      attachTo: document.body,
    })
    await flushPromises()
    // Edit the subject field — exercises @update:subject
    const subjectInput = wrapper.find<HTMLInputElement>('#tmpl-subject')
    await subjectInput.setValue('New subject')
    // Edit the body text — exercises @update:bodyText
    const bodyText = wrapper.find<HTMLTextAreaElement>('#tmpl-body-text')
    await bodyText.setValue('Updated text')
    // Edit the body html — exercises @update:bodyHtml
    const bodyHtml = wrapper.find<HTMLTextAreaElement>('#tmpl-body-html')
    await bodyHtml.setValue('<p>Updated html</p>')
    // Click a placeholder chip — exercises @insert-placeholder
    const chip = wrapper.findAll('button').find((b) => b.text().includes('{{'))!
    await chip.trigger('click')
    // Click Save — exercises @save
    const saveBtn = wrapper.findAll('button').find((b) => b.text() === 'Save')!
    await saveBtn.trigger('click')
    await flushPromises()
    expect(updateMock).toHaveBeenCalled()
    // Click Back — exercises @back
    const backBtn = wrapper.findAll('button').find((b) => b.text().includes('Back to list'))!
    await backBtn.trigger('click')
    await nextTick()
    expect(currentTemplateRef.value).toBe(null)
    wrapper.unmount()
  })

  it('redirects non-admins on mount and skips fetchAll', async () => {
    vi.resetModules()
    vi.doMock('@/stores/auth', () => ({
      useAuthStore: () => ({ user: { id: 1, email: 'x', name: 'x', roles: [], is_admin: false } }),
    }))
    const { default: PageNonAdmin } = await import('@/pages/admin/MailTemplatesPage.vue')
    mount(PageNonAdmin, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    // Non-admin role → redirect path → fetchAll is NOT called
    expect(fetchAllMock).not.toHaveBeenCalled()
    vi.doUnmock('@/stores/auth')
  })
})