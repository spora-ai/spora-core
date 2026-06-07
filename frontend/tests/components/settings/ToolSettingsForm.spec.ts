/**
 * ToolSettingsForm — schema-driven settings form, used for tool/LLM config UIs.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const confirmMock = vi.fn().mockResolvedValue(true)
vi.mock('@/composables/useConfirmDialog', () => ({
  useConfirmDialog: () => ({ confirm: confirmMock }),
}))

import ToolSettingsForm from '@/components/settings/ToolSettingsForm.vue'
import ToolSettingField from '@/components/settings/ToolSettingField.vue'

const schema = [
  { key: 'api_key', label: 'API Key', type: 'password' as const, required: true, default: '', description: '', options: null, expose_to_llm: false },
  { key: 'model', label: 'Model', type: 'text' as const, required: false, default: '', description: '', options: null, expose_to_llm: false },
  { key: 'region', label: 'Region', type: 'select' as const, required: false, default: '', description: '', options: { 'us-east': 'US East', 'eu-west': 'EU West' }, expose_to_llm: false },
]

const tool = { tool_class: 'OpenAI', tool_name: 'openai', display_name: 'OpenAI', category: '', settings_schema: schema, operations: [] }

beforeEach(() => {
  setActivePinia(createPinia())
  confirmMock.mockReset().mockResolvedValue(true)
})

function mountForm(props: { initialSettings?: Record<string, string>; saving?: boolean; error?: string | null; globalDefaults?: Record<string, string>; canClearToGlobal?: boolean; mode?: 'global' | 'user' } = {}) {
  return mount(ToolSettingsForm, {
    props: {
      tool,
      initialSettings: props.initialSettings ?? {},
      saving: props.saving ?? false,
      error: props.error ?? null,
      globalDefaults: props.globalDefaults,
      canClearToGlobal: props.canClearToGlobal,
      mode: props.mode,
    },
    global: { stubs: { ToolSettingField: false } },
  })
}

describe('ToolSettingsForm', () => {
  it('renders a field for each schema entry', () => {
    const wrapper = mountForm({ initialSettings: {} })
    expect(wrapper.text()).toContain('API Key')
    expect(wrapper.text()).toContain('Model')
    expect(wrapper.text()).toContain('Region')
  })

  it('disables Save and Discard changes when not dirty', () => {
    const wrapper = mountForm({ initialSettings: { model: 'gpt-4' } })
    const buttons = wrapper.findAll('button')
    const save = buttons.find((b) => (b.text() ?? '').includes('Save'))
    const discard = buttons.find((b) => (b.text() ?? '').includes('Discard'))
    expect(save?.attributes('disabled')).toBeDefined()
    expect(discard?.attributes('disabled')).toBeDefined()
  })

  it('emits save with the form values when submitted', async () => {
    const wrapper = mountForm({ initialSettings: { model: 'gpt-4' } })
    const modelInput = wrapper.find('input[type="text"]')
    await modelInput.setValue('gpt-5')
    await wrapper.find('form').trigger('submit.prevent')
    expect(wrapper.emitted('save')).toBeTruthy()
    expect(wrapper.emitted('save')![0][0]).toMatchObject({ model: 'gpt-5' })
  })

  it('treats password fields with "***" as unchanged', async () => {
    const wrapper = mountForm({ initialSettings: { api_key: '***' } })
    // Save should be disabled because nothing changed
    const save = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Save'))
    expect(save?.attributes('disabled')).toBeDefined()
  })

  it('enables Save when password is changed from "***" to a new value', async () => {
    const wrapper = mountForm({ initialSettings: { api_key: '***' } })
    const changeBtn = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Change')
    await changeBtn!.trigger('click')
    const apiKeyInput = wrapper.find('input#api_key')
    await apiKeyInput.setValue('new-secret')
    const save = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Save'))
    expect(save?.attributes('disabled')).toBeUndefined()
  })

  it('renders a global default hint when globalDefaults are set and value is empty', () => {
    const wrapper = mountForm({
      initialSettings: { model: '' },
      globalDefaults: { model: 'gpt-4' },
    })
    expect(wrapper.text()).toContain('Global default')
  })

  it('masks the global default for password fields', () => {
    const wrapper = mountForm({
      initialSettings: { api_key: '' },
      globalDefaults: { api_key: 'real' },
    })
    expect(wrapper.text()).toContain('••••••••')
  })

  it('shows "Delete settings" button when canClearToGlobal is true', () => {
    const wrapper = mountForm({ canClearToGlobal: true })
    expect(wrapper.text()).toContain('Delete settings')
  })

  it('emits clear-to-global after confirming the dialog', async () => {
    confirmMock.mockResolvedValue(true)
    const wrapper = mountForm({ canClearToGlobal: true, mode: 'user' })
    const del = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Delete settings'))
    expect(del).toBeDefined()
    await del!.trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(wrapper.emitted('clear-to-global')).toBeTruthy()
  })

  it('does not emit clear-to-global when dialog is cancelled', async () => {
    confirmMock.mockResolvedValue(false)
    const wrapper = mountForm({ canClearToGlobal: true, mode: 'user' })
    const del = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Delete settings'))
    await del!.trigger('click')
    await flushPromises()
    expect(wrapper.emitted('clear-to-global')).toBeFalsy()
  })

  it('shows error message when error prop is set', () => {
    const wrapper = mountForm({ error: 'Something went wrong' })
    expect(wrapper.text()).toContain('Something went wrong')
  })

  it('disables Save while saving', async () => {
    const wrapper = mountForm({ initialSettings: { model: 'gpt-4' } })
    const modelInput = wrapper.find('input[type="text"]')
    await modelInput.setValue('gpt-5')
    await wrapper.setProps({ saving: true })
    const save = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Saving'))
    expect(save).toBeDefined()
  })

  it('resets the form when Discard changes is clicked', async () => {
    const wrapper = mountForm({ initialSettings: { model: 'gpt-4' } })
    const modelInput = wrapper.find('input[type="text"]')
    await modelInput.setValue('gpt-5')
    const discard = wrapper.findAll('button').find((b) => (b.text() ?? '').includes('Discard'))
    await discard!.trigger('click')
    const modelValue = (wrapper.find('input[type="text"]').element as HTMLInputElement).value
    expect(modelValue).toBe('gpt-4')
  })
})
