/**
 * LLMConfigEditForm — edit form for an existing LLM driver configuration.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const driversRef = ref<Array<{ name: string; display_name: string; driver_class: string; settings_schema: Array<{ key: string; label: string; type: string; required: boolean; default: unknown; description: string; options: unknown; expose_to_llm: boolean }> }>>([])
const updateConfigMock = vi.fn()
const deleteConfigMock = vi.fn()

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({
    get drivers() { return driversRef.value },
    updateConfig: updateConfigMock,
    deleteConfig: deleteConfigMock,
    driverForClass: (cls: string) => driversRef.value.find((d) => d.driver_class === cls),
  }),
}))

const userRef = ref<{ is_admin: boolean } | null>(null)
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ get user() { return userRef.value } }),
}))

import LLMConfigEditForm from '@/components/settings/llm/LLMConfigEditForm.vue'

const sampleConfig = (overrides: Partial<{ id: number; name: string; driver_class: string; driver_display_name: string; is_global: boolean; settings: Record<string, string>; created_at: string; updated_at: string }> = {}) => ({
  id: 1, name: 'cfg', driver_class: 'OpenAI', driver_display_name: 'OpenAI', is_global: false,
  settings: { api_key: '***' }, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z',
  ...overrides,
})

const sampleSchema = [{ key: 'api_key', label: 'API Key', type: 'password', required: true, default: '', description: '', options: null, expose_to_llm: false }]

beforeEach(() => {
  setActivePinia(createPinia())
  driversRef.value = [{ name: 'openai', display_name: 'OpenAI', driver_class: 'OpenAI', settings_schema: sampleSchema }]
  userRef.value = null
  updateConfigMock.mockReset()
  deleteConfigMock.mockReset()
})

function mountEdit(config: ReturnType<typeof sampleConfig> = sampleConfig()) {
  return mount(LLMConfigEditForm, { props: { config }, attachTo: document.body })
}

function findDeleteButtonInModal(): HTMLButtonElement | undefined {
  const all = Array.from(document.body.querySelectorAll('button')).filter((b) => (b.textContent ?? '').trim() === 'Delete')
  // The page-level Delete button uses `bg-destructive/10`, the modal confirm uses `bg-destructive px-4`.
  return all.find((b) => b.className.includes('bg-destructive px-4')) as HTMLButtonElement | undefined
}

function findCancelButtonInModal(): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === 'Cancel' && b.className.includes('bg-background')) as HTMLButtonElement | undefined
}

describe('LLMConfigEditForm', () => {
  it('renders the configuration name and driver', () => {
    const wrapper = mountEdit(sampleConfig({ name: 'Production' }))
    expect(wrapper.text()).toContain('Production')
    expect(wrapper.text()).toContain('OpenAI')
  })

  it('renders the "All configurations" back button and emits cancel on click', async () => {
    const wrapper = mountEdit()
    const back = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('All configurations'))
    expect(back).toBeDefined()
    await back!.trigger('click')
    expect(wrapper.emitted('cancel')).toBeTruthy()
  })

  it('shows a read-only banner when the user is non-admin and config is global', () => {
    userRef.value = { is_admin: false }
    const wrapper = mountEdit(sampleConfig({ is_global: true }))
    expect(wrapper.text()).toContain('Global configuration')
  })

  it('does not show a read-only banner when the user is admin', () => {
    userRef.value = { is_admin: true }
    const wrapper = mountEdit(sampleConfig({ is_global: true }))
    expect(wrapper.text()).not.toContain('Global configuration — available to all users')
  })

  it('shows the Delete button and only opens the modal (no emit) on first click', async () => {
    const wrapper = mountEdit()
    const del = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Delete')
    expect(del).toBeDefined()
    await del!.trigger('click')
    await flushPromises()
    expect(wrapper.emitted('deleted')).toBeFalsy()
    wrapper.unmount()
  })

  it('opens the delete confirmation modal and confirms deletion', async () => {
    deleteConfigMock.mockResolvedValue(undefined)
    const wrapper = mountEdit()
    const del = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Delete')
    await del!.trigger('click')
    await flushPromises()
    const confirmBtn = findDeleteButtonInModal()
    expect(confirmBtn).toBeDefined()
    confirmBtn?.click()
    await flushPromises()
    expect(deleteConfigMock).toHaveBeenCalledWith(1)
    expect(wrapper.emitted('deleted')).toBeTruthy()
    wrapper.unmount()
  })

  it('cancels the delete modal when Cancel is clicked', async () => {
    const wrapper = mountEdit()
    const del = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Delete')
    await del!.trigger('click')
    await flushPromises()
    const cancelBtn = findCancelButtonInModal()
    expect(cancelBtn).toBeDefined()
    cancelBtn?.click()
    await flushPromises()
    expect(deleteConfigMock).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows the read-only settings list when global and not admin', () => {
    userRef.value = { is_admin: false }
    const wrapper = mountEdit(sampleConfig({ is_global: true, settings: { api_key: '***', model: 'gpt-4' } }))
    expect(wrapper.text()).toContain('••••••••')
    expect(wrapper.text()).toContain('model')
  })

  it('renders Created/Updated metadata', () => {
    const wrapper = mountEdit(sampleConfig({ created_at: '2026-01-15T00:00:00Z', updated_at: '2026-02-20T00:00:00Z' }))
    expect(wrapper.text()).toContain('Created')
    expect(wrapper.text()).toContain('Updated')
  })
})
