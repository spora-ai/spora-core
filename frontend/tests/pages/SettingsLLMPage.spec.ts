/**
 * SettingsLLMPage — LLM config list / create / edit views.
 */
import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { ref } from 'vue'
import { setActivePinia, createPinia } from 'pinia'

const route = ref({ query: {} as Record<string, string> })
const replaceMock = vi.fn()
vi.mock('vue-router', () => ({
  useRoute: () => route.value,
  useRouter: () => ({ replace: replaceMock, push: vi.fn() }),
}))

const configs = ref<Array<{ id: number; name: string; driver_display_name: string; driver_class: string; is_default: boolean; is_global: boolean }>>([
  { id: 1, name: 'Primary', driver_display_name: 'OpenAI', driver_class: 'O', is_default: true, is_global: false },
  { id: 2, name: 'Global one', driver_display_name: 'Anthropic', driver_class: 'A', is_default: false, is_global: true },
])

const ensureMock = vi.fn().mockResolvedValue(undefined)
const loadPreferenceMock = vi.fn().mockResolvedValue(undefined)

vi.mock('@/stores/llmConfigs', () => ({
  useLlmConfigsStore: () => ({ get configs() { return configs.value }, ensure: ensureMock }),
}))

vi.mock('@/stores/llmPreferencesStore', () => ({
  useLlmPreferencesStore: () => ({ loadPreference: loadPreferenceMock }),
}))

const ListStub = { name: 'LLMConfigList', emits: ['select', 'create'], template: '<div class="list-stub"><button class="select-1" @click="$emit(\'select\', $attrs.configsValue)">x</button><button class="create-btn" @click="$emit(\'create\')">c</button></div>' }
const CreateStub = { name: 'LLMConfigCreateForm', emits: ['created', 'cancel'], template: '<div class="create-stub" />' }
const EditStub = { name: 'LLMConfigEditForm', emits: ['saved', 'deleted', 'cancel'], template: '<div class="edit-stub" />' }

import SettingsLLMPage from '@/pages/settings/SettingsLLMPage.vue'

beforeEach(() => {
  setActivePinia(createPinia())
  route.value = { query: {} }
  replaceMock.mockReset()
  ensureMock.mockClear().mockResolvedValue(undefined)
  loadPreferenceMock.mockClear().mockResolvedValue(undefined)
})

function mountPage() {
  return mount(SettingsLLMPage, {
    global: { stubs: { LLMConfigList: ListStub, LLMConfigCreateForm: CreateStub, LLMConfigEditForm: EditStub } },
  })
}

describe('SettingsLLMPage', () => {
  it('renders the list view by default', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('.list-stub').exists()).toBe(true)
    expect(wrapper.find('.create-stub').exists()).toBe(false)
  })

  it('calls ensure() and loadPreference() on mount', async () => {
    mountPage()
    await flushPromises()
    expect(ensureMock).toHaveBeenCalled()
    expect(loadPreferenceMock).toHaveBeenCalled()
  })

  it('switches to create view when ?create=1 is in the URL', async () => {
    route.value = { query: { create: '1' } }
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('.create-stub').exists()).toBe(true)
  })

  it('switches to edit view when ?config=<id> is in the URL', async () => {
    route.value = { query: { config: '1' } }
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('.edit-stub').exists()).toBe(true)
  })

  it('falls back to list view when ?config=<id> has no matching config', async () => {
    route.value = { query: { config: '999' } }
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('.list-stub').exists()).toBe(true)
  })

  it('selectConfig ignores global configs', async () => {
    const wrapper = mountPage()
    await flushPromises()
    const list = wrapper.findComponent({ name: 'LLMConfigList' })
    await list.vm.$emit('select', { id: 2, is_global: true, name: 'G', driver_display_name: 'D', driver_class: 'C', is_default: false })
    expect(wrapper.find('.edit-stub').exists()).toBe(false)
    expect(replaceMock).not.toHaveBeenCalled()
  })

  it('selectConfig navigates to edit view for a personal config', async () => {
    const wrapper = mountPage()
    await flushPromises()
    const list = wrapper.findComponent({ name: 'LLMConfigList' })
    await list.vm.$emit('select', { id: 1, is_global: false, name: 'P', driver_display_name: 'D', driver_class: 'C', is_default: false })
    await flushPromises()
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-llm', query: { config: '1' } })
    expect(wrapper.find('.edit-stub').exists()).toBe(true)
  })

  it('startCreate switches to the create view', async () => {
    const wrapper = mountPage()
    await flushPromises()
    const list = wrapper.findComponent({ name: 'LLMConfigList' })
    await list.vm.$emit('create')
    await flushPromises()
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-llm', query: { create: '1' } })
    expect(wrapper.find('.create-stub').exists()).toBe(true)
  })

  it('onCreated switches to edit view of the new config', async () => {
    const wrapper = mountPage()
    await flushPromises()
    const list = wrapper.findComponent({ name: 'LLMConfigList' })
    await list.vm.$emit('create')
    await flushPromises()
    const create = wrapper.findComponent({ name: 'LLMConfigCreateForm' })
    await create.vm.$emit('created', { id: 7, name: 'X', driver_class: 'C', driver_display_name: 'D', is_default: false, is_global: false })
    await flushPromises()
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-llm', query: { config: '7' } })
  })

  it('onDeleted returns to the list view', async () => {
    route.value = { query: { config: '1' } }
    const wrapper = mountPage()
    await flushPromises()
    const edit = wrapper.findComponent({ name: 'LLMConfigEditForm' })
    await edit.vm.$emit('deleted')
    await flushPromises()
    expect(replaceMock).toHaveBeenCalledWith({ name: 'settings-llm' })
  })
})
