/**
 * LLMConfigCreateForm — create a new LLM configuration.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const driversRef = ref<Array<{ name: string; display_name: string; driver_class: string; settings_schema: Array<{ key: string; label: string; type: string; required: boolean; default: unknown; description: string; options: unknown; expose_to_llm: boolean }> }>>([])
const createConfigMock = vi.fn()

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({
    get drivers() { return driversRef.value },
    createConfig: createConfigMock,
    driverByName: (name: string) => driversRef.value.find((d) => d.name === name),
  }),
}))

const userRef = ref<{ is_admin: boolean } | null>(null)
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ get user() { return userRef.value } }),
}))

import LLMConfigCreateForm from '@/components/settings/llm/LLMConfigCreateForm.vue'

const driverSchema = [
  { key: 'api_key', label: 'API Key', type: 'password', required: true, default: '', description: '', options: null, expose_to_llm: false },
  { key: 'model', label: 'Model', type: 'text', required: false, default: '', description: '', options: null, expose_to_llm: false },
]

beforeEach(() => {
  setActivePinia(createPinia())
  driversRef.value = [{ name: 'openai', display_name: 'OpenAI', driver_class: 'OpenAI', settings_schema: driverSchema }]
  userRef.value = null
  createConfigMock.mockReset()
})

function mountCreate(props: { requireGlobal?: boolean } = {}) {
  return mount(LLMConfigCreateForm, { props })
}

describe('LLMConfigCreateForm', () => {
  it('renders the form title and the driver select', () => {
    const wrapper = mountCreate()
    expect(wrapper.text()).toContain('New LLM Configuration')
    const select = wrapper.find('select')
    expect(select.exists()).toBe(true)
  })

  it('emits cancel when the Cancel button is clicked', async () => {
    const wrapper = mountCreate()
    const cancel = wrapper.findAll('button').find((b) => (b.text() ?? '').trim() === 'Cancel')
    expect(cancel).toBeDefined()
    await cancel!.trigger('click')
    expect(wrapper.emitted('cancel')).toBeTruthy()
  })

  it('shows the admin global toggle for non-requireGlobal + admin', () => {
    userRef.value = { is_admin: true }
    const wrapper = mountCreate()
    expect(wrapper.text()).toContain('Make this a global configuration')
  })

  it('hides the admin global toggle for non-admin users', () => {
    userRef.value = { is_admin: false }
    const wrapper = mountCreate()
    expect(wrapper.text()).not.toContain('Make this a global configuration')
  })

  it('shows the global-default-only toggle in requireGlobal mode', () => {
    userRef.value = { is_admin: true }
    const wrapper = mountCreate({ requireGlobal: true })
    expect(wrapper.text()).toContain('Set as global default')
    expect(wrapper.text()).not.toContain('Make this a global configuration')
  })

  it('prompts to select a driver when none is chosen', () => {
    const wrapper = mountCreate()
    expect(wrapper.text()).toContain('Select a driver above')
  })

  it('renders the settings form after a driver is selected', async () => {
    const wrapper = mountCreate()
    await wrapper.find('select').setValue('openai')
    await flushPromises()
    expect(wrapper.text()).toContain('Settings')
  })

  it('emits created with the new config on a successful save', async () => {
    const newConfig = { id: 9, name: 'mine', driver_class: 'OpenAI', driver_name: 'openai', driver_display_name: 'OpenAI', settings: { api_key: 'real' }, context_window: null, max_tokens_output: null, is_default: false, is_global: false, user_id: null, created_at: '', updated_at: '' }
    createConfigMock.mockResolvedValue(newConfig)
    const wrapper = mountCreate()
    await wrapper.find('input#llm-create-name').setValue('mine')
    await wrapper.find('select').setValue('openai')
    await flushPromises()
    // Set the API key value via the rendered form
    const apiKeyInput = wrapper.find('input[type="password"]')
    expect(apiKeyInput.exists()).toBe(true)
    await apiKeyInput.setValue('real-key')
    const save = wrapper.find('button[type="submit"]')
    expect(save.attributes('disabled')).toBeUndefined()
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(createConfigMock).toHaveBeenCalled()
    expect(createConfigMock.mock.calls[0][0]).toMatchObject({ name: 'mine', driver_class: 'OpenAI' })
    expect(wrapper.emitted('created')).toBeTruthy()
    expect(wrapper.emitted('created')![0][0]).toMatchObject({ id: 9 })
  })
})
