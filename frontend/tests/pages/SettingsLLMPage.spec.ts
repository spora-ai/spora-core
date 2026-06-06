/**
 * SettingsLLMPage — LLM config list / create / edit views.
 *
 * Asserts the list view is shown by default, switching to create view via
 * the query param, and selecting a config switches to edit view.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const route = ref({ query: {} as Record<string, string> })
const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRoute: () => route.value,
  useRouter: () => ({ push: pushMock }),
}))

const configs = ref<Array<{ id: number; name: string; driver_display_name: string; driver_class: string; is_default: boolean; is_global: boolean }>>([
  { id: 1, name: 'Primary', driver_display_name: 'OpenAI', driver_class: 'O', is_default: true, is_global: false },
])

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ get configs() { return configs.value }, ensure: vi.fn() }),
}))

vi.mock('@/stores/llmPreferencesStore', () => ({
  useLlmPreferencesStore: () => ({ loadPreference: vi.fn().mockResolvedValue(undefined) }),
}))

const ListStub = { name: 'LLMConfigList', template: '<div class="list-stub" />' }
const CreateStub = { name: 'LLMConfigCreateForm', template: '<div class="create-stub" />' }
const EditStub = { name: 'LLMConfigEditForm', template: '<div class="edit-stub" />' }

import SettingsLLMPage from '@/pages/settings/SettingsLLMPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  route.value = { query: {} }
  pushMock.mockReset()
})

describe('SettingsLLMPage', () => {
  it('renders the list view by default', async () => {
    const wrapper = mount(SettingsLLMPage, {
      global: { stubs: { LLMConfigList: ListStub, LLMConfigCreateForm: CreateStub, LLMConfigEditForm: EditStub } },
    })
    await flushPromises()
    expect(wrapper.find('.list-stub').exists()).toBe(true)
    expect(wrapper.find('.create-stub').exists()).toBe(false)
  })

  it('switches to create view when ?create=1 is in the URL', async () => {
    route.value = { query: { create: '1' } }
    const wrapper = mount(SettingsLLMPage, {
      global: { stubs: { LLMConfigList: ListStub, LLMConfigCreateForm: CreateStub, LLMConfigEditForm: EditStub } },
    })
    await flushPromises()
    expect(wrapper.find('.create-stub').exists()).toBe(true)
  })

  it('switches to edit view when ?config=<id> is in the URL', async () => {
    route.value = { query: { config: '1' } }
    const wrapper = mount(SettingsLLMPage, {
      global: { stubs: { LLMConfigList: ListStub, LLMConfigCreateForm: CreateStub, LLMConfigEditForm: EditStub } },
    })
    await flushPromises()
    expect(wrapper.find('.edit-stub').exists()).toBe(true)
  })
})
